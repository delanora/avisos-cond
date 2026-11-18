<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TipoAvisoRepository;
use App\Support\NaoEncontradoException;

/**
 * Tipos de aviso de cada gerente (nome, cor principal e se o aviso fica fixo na faixa do topo da TV). Os cinco de
 * fábrica (Comum, Fixo, Urgente, Manutenção, Assembleia) são copiados para o gerente na primeira vez e depois são dele.
 */
final class TipoAvisoService
{
    public const NOME_MAX = 30;

    public function __construct(private readonly TipoAvisoRepository $tipos)
    {
    }

    /** @return list<array<string, mixed>> os tipos do gerente */
    public function listar(int $usuarioId): array
    {
        return $this->tipos->listar($usuarioId);
    }

    /** @return array<string, mixed>|null */
    public function resolver(int $usuarioId, string $chave): ?array
    {
        return $this->tipos->porChave($usuarioId, $chave);
    }

    /**
     * @return array<string, mixed>
     * @throws NaoEncontradoException tipo inexistente ou de outro gerente
     */
    public function buscarProprio(int $usuarioId, int $id): array
    {
        return $this->tipos->porId($usuarioId, $id) ?? throw new NaoEncontradoException();
    }

    /**
     * @param array<string, mixed> $dados nome, cor, fixo
     * @return array<string, mixed> o tipo criado
     * @throws ValidacaoException
     */
    public function criar(int $usuarioId, array $dados): array
    {
        [$nome, $chave, $cor, $fixo] = $this->validar($usuarioId, $dados);

        return $this->buscarProprio($usuarioId, $this->tipos->criar($usuarioId, $chave, $nome, $cor, $fixo));
    }

    /**
     * @param array<string, mixed> $dados nome, cor, fixo
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(int $usuarioId, int $id, array $dados): void
    {
        $atual = $this->buscarProprio($usuarioId, $id);
        [$nome, , $cor, $fixo] = $this->validar($usuarioId, $dados, $id, $atual['chave']);

        $this->tipos->atualizar($usuarioId, $id, $nome, $cor, $fixo) || throw new NaoEncontradoException();
    }

    /**
     * @throws ValidacaoException tipo em uso por algum aviso, ou o último tipo do gerente
     * @throws NaoEncontradoException
     */
    public function excluir(int $usuarioId, int $id): void
    {
        $tipo = $this->buscarProprio($usuarioId, $id);
        if ($tipo['em_uso'] > 0) {
            throw new ValidacaoException(['tipo' => "Este tipo é usado por {$tipo['em_uso']} aviso(s). Troque o tipo deles antes de apagar."]);
        }
        if ($this->tipos->contar($usuarioId) <= 1) {
            throw new ValidacaoException(['tipo' => 'Você precisa manter pelo menos um tipo de aviso. Crie outro antes de apagar este.']);
        }

        $this->tipos->excluir($usuarioId, $id) || throw new NaoEncontradoException();
    }

    /**
     * Valida os campos do formulário e devolve [nome, chave, cor, fixo].
     *
     * @param array<string, mixed> $dados
     * @return array{string, string, string, bool}
     * @throws ValidacaoException
     */
    public function validar(int $usuarioId, array $dados, ?int $exceto = null, ?string $chaveAtual = null): array
    {
        $nome = is_string($dados['nome'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $dados['nome']) ?? '') : '';
        $cor = is_string($dados['cor'] ?? null) ? strtoupper(trim($dados['cor'])) : '';
        $erros = [];

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'Informe um nome de 2 a ' . self::NOME_MAX . ' caracteres.';
        }
        if (!AparenciaService::corValida($cor)) {
            $erros['cor'] = 'Escolha uma cor válida.';
        }

        $chave = $chaveAtual ?? self::chaveDoNome($nome);
        if ($erros === [] && $this->tipos->nomeOuChaveEmUso($usuarioId, $nome, $chave, $exceto)) {
            $erros['nome'] = 'Já existe um tipo com esse nome.';
        }
        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return [$nome, $chave, $cor, ($dados['fixo'] ?? '') === '1'];
    }

    /** "Reunião do Conselho" → "reuniao-do-conselho" (só a-z, 0-9 e "-"). */
    public static function chaveDoNome(string $nome): string
    {
        $semAcento = strtr(mb_strtolower($nome), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $chave = trim((string) preg_replace('/[^a-z0-9]+/', '-', $semAcento), '-');

        return $chave === '' ? 'tipo' : substr($chave, 0, 40);
    }

    /**
     * Folha de estilo com as cores dos tipos (etiquetas do painel e cartões da prévia). O CSP não permite estilo
     * inline, então as cores vêm de CSS; só entram ids numéricos e cores validadas como #RRGGBB.
     *
     * @param list<array<string, mixed>> $tipos
     */
    public static function css(array $tipos): string
    {
        $css = '';
        foreach ($tipos as $t) {
            $cores = AparenciaService::coresDoTipo($t['cor']);
            if ($cores === null) {
                continue;
            }
            $id = (int) $t['id'];
            $css .= ".etiqueta-tipo-t{$id} { color: {$cores['texto_tag']}; background: {$cores['principal']}; }\n"
                . ".tv-t{$id} { background: {$cores['fundo']}; }\n"
                . ".tv-fixo.tv-t{$id} { border: 0.1em solid {$cores['borda']}; }\n"
                . ".tv-t{$id} .tv-fixo-tipo, .tv-t{$id} .tv-tipo { color: {$cores['texto_tag']}; background: {$cores['principal']}; }\n"
                . ".tv-t{$id} .tv-fixo-titulo, .tv-t{$id} .tv-titulo { color: {$cores['titulo']}; }\n";
        }

        return $css;
    }
}
