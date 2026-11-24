<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FinanceiroRepository;
use App\Support\NaoEncontradoException;

/** Controle financeiro manual por condomínio: situação, valor mensal, vencimento e bloqueio da exibição. */
final class FinanceiroService
{
    public const SITUACOES = ['em_dia' => 'Em dia', 'pendente' => 'Pendente'];
    public const FILTROS = ['em_dia', 'pendente', 'sem_cadastro', 'bloqueado'];
    /** Teto do valor mensal, em centavos (R$ 1.000.000,00): barra erro de digitação. */
    private const VALOR_MAXIMO = 100000000;

    public function __construct(private readonly FinanceiroRepository $repositorio)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(string $filtro): array
    {
        return $this->repositorio->listar(in_array($filtro, self::FILTROS, true) ? $filtro : '');
    }

    /** @return array{total: int, em_dia: int, pendentes: int, sem_cadastro: int, bloqueados: int, valor_em_dia: int, valor_pendente: int} */
    public function resumo(): array
    {
        return $this->repositorio->resumo();
    }

    /**
     * @return array<string, mixed>
     * @throws NaoEncontradoException
     */
    public function buscar(int $condominioId): array
    {
        return $this->repositorio->porCondominio($condominioId) ?? throw new NaoEncontradoException();
    }

    /**
     * @param array<string, mixed> $dados situacao, valor_mensal ("1.234,56"), dia_vencimento, observacoes
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function salvar(int $condominioId, array $dados): void
    {
        $this->buscar($condominioId);
        $texto = static fn (string $k): string => is_string($dados[$k] ?? null) ? trim($dados[$k]) : '';
        $erros = [];

        $situacao = $texto('situacao');
        if (!isset(self::SITUACOES[$situacao])) {
            $erros['situacao'] = 'Escolha a situação.';
        }

        $valor = null;
        if ($texto('valor_mensal') !== '') {
            $valor = self::paraCentavos($texto('valor_mensal'));
            if ($valor === null || $valor > self::VALOR_MAXIMO) {
                $erros['valor_mensal'] = 'Informe um valor válido, como 1.250,00.';
            }
        }

        $dia = null;
        if ($texto('dia_vencimento') !== '') {
            $dia = ctype_digit($texto('dia_vencimento')) ? (int) $texto('dia_vencimento') : 0;
            if ($dia < 1 || $dia > 31) {
                $erros['dia_vencimento'] = 'O dia do vencimento vai de 1 a 31.';
                $dia = null;
            }
        }

        $observacoes = $texto('observacoes');
        if (mb_strlen($observacoes) > 1000) {
            $erros['observacoes'] = 'Use no máximo 1000 caracteres.';
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        $this->repositorio->salvar($condominioId, $situacao, $valor, $dia, $observacoes === '' ? null : $observacoes);
    }

    /**
     * @throws ValidacaoException motivo ausente ou longo demais
     * @throws NaoEncontradoException
     */
    public function bloquear(int $condominioId, string $motivo): void
    {
        $this->buscar($condominioId);
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ValidacaoException(['motivo' => 'Informe o motivo do bloqueio.']);
        }
        if (mb_strlen($motivo) > 255) {
            throw new ValidacaoException(['motivo' => 'Use no máximo 255 caracteres.']);
        }
        $this->repositorio->definirBloqueio($condominioId, true, $motivo);
    }

    /** @throws NaoEncontradoException */
    public function desbloquear(int $condominioId): void
    {
        $this->buscar($condominioId);
        $this->repositorio->definirBloqueio($condominioId, false, null);
    }

    /** "1.234,56", "R$ 150" ou "150,5" → centavos; null se não for um valor monetário. */
    public static function paraCentavos(string $texto): ?int
    {
        $limpo = str_replace(['R$', ' ', '.'], '', $texto);
        if (preg_match('/^(\d{1,9})(?:,(\d{1,2}))?$/', $limpo, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function formatar(?int $centavos): string
    {
        return $centavos === null ? '—' : 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
