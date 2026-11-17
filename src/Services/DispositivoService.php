<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\DispositivoRepository;
use App\Support\NaoEncontradoException;
use App\Support\SituacaoDispositivo;
use App\Support\Validator;
use RuntimeException;

/** Cadastro de TVs/mini PCs feito pelo superadmin (painel /admin). */
final class DispositivoService
{
    private const TENTATIVAS_DE_TOKEN = 8;

    public function __construct(
        private readonly DispositivoRepository $dispositivos,
        private readonly CondominioRepository $condominios,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->dispositivos->listar();
    }

    /**
     * Todos os dispositivos com a situação calculada na hora pelo último contato.
     *
     * @return list<array<string, mixed>>
     */
    public function listarComSituacao(int $agora): array
    {
        return array_map(function (array $d) use ($agora): array {
            $d['situacao'] = SituacaoDispositivo::de($d['ultimo_ping_epoch'], $d['ativo'], $agora);

            return $d;
        }, $this->dispositivos->listar());
    }

    /**
     * Quantos dispositivos há em cada situação (todas as chaves sempre presentes).
     *
     * @param list<array<string, mixed>> $comSituacao resultado de listarComSituacao()
     * @return array<string, int>
     */
    public static function contarPorSituacao(array $comSituacao): array
    {
        $contagem = array_fill_keys(array_keys(SituacaoDispositivo::ROTULOS), 0);
        foreach ($comSituacao as $d) {
            $contagem[$d['situacao']]++;
        }

        return $contagem;
    }

    /**
     * Histórico de quedas e retornos.
     *
     * @return list<array{tipo: string, registrado_epoch: int, ultimo_ping_epoch: ?int}>
     * @throws NaoEncontradoException
     */
    public function eventos(int $id): array
    {
        $this->buscar($id);

        return $this->dispositivos->eventos($id);
    }

    /**
     * @return array<string, mixed>
     * @throws NaoEncontradoException
     */
    public function buscar(int $id): array
    {
        return $this->dispositivos->porId($id) ?? throw new NaoEncontradoException();
    }

    /**
     * @param array<string, mixed> $dados nome, condominio_id
     * @throws ValidacaoException
     */
    public function criar(array $dados): int
    {
        [$nome, $condominioId] = $this->validar($dados);

        // O token sorteado quase nunca repete; se repetir, sorteia outro.
        for ($i = 0; $i < self::TENTATIVAS_DE_TOKEN; $i++) {
            $id = $this->dispositivos->criar($condominioId, $nome, TokenDispositivo::gerar());
            if ($id !== null) {
                return $id;
            }
        }

        throw new RuntimeException('Não foi possível gerar um token único para o dispositivo.');
    }

    /**
     * @param array<string, mixed> $dados nome, condominio_id, ativo
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(int $id, array $dados): void
    {
        $this->buscar($id);
        [$nome, $condominioId] = $this->validar($dados);

        $this->dispositivos->atualizar($id, $condominioId, $nome, ($dados['ativo'] ?? '') === '1');
    }

    /**
     * Gera um token novo; o antigo para de funcionar na hora (a TV precisa ser reconfigurada).
     *
     * @return string o novo token
     * @throws NaoEncontradoException
     */
    public function trocarToken(int $id): string
    {
        $this->buscar($id);

        for ($i = 0; $i < self::TENTATIVAS_DE_TOKEN; $i++) {
            $token = TokenDispositivo::gerar();
            if ($this->dispositivos->trocarToken($id, $token)) {
                return $token;
            }
        }

        throw new RuntimeException('Não foi possível gerar um token único para o dispositivo.');
    }

    /** @throws NaoEncontradoException */
    public function excluir(int $id): void
    {
        if (!$this->dispositivos->excluir($id)) {
            throw new NaoEncontradoException();
        }
    }

    /**
     * @param array<string, mixed> $dados
     * @return array{0: string, 1: int}
     * @throws ValidacaoException
     */
    private function validar(array $dados): array
    {
        $validador = new Validator($dados, ['nome' => 'required|max:100', 'condominio_id' => 'required|int']);
        $erros = $validador->errors();
        $condominioId = (int) $validador->valores()['condominio_id'];

        if (!isset($erros['condominio_id']) && $this->condominios->idsExistentes([$condominioId]) === []) {
            $erros['condominio_id'] = 'Escolha um condomínio válido.';
        }
        if (isset($erros['condominio_id']) && $erros['condominio_id'] === 'Este campo é obrigatório.') {
            $erros['condominio_id'] = 'Escolha o condomínio onde a TV será instalada.';
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return [$validador->valores()['nome'], $condominioId];
    }
}
