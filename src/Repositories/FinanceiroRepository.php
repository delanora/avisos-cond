<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Situação financeira (digitada à mão) e bloqueio manual dos condomínios. Só o superadmin usa. */
final class FinanceiroRepository
{
    private const SELECAO = 'c.id, c.nome, c.ativo, c.bloqueado, c.bloqueio_motivo,
            to_char(c.bloqueado_em AT TIME ZONE \'America/Sao_Paulo\', \'DD/MM/YYYY HH24:MI\') AS bloqueado_em,
            f.situacao, f.valor_mensal, f.dia_vencimento, f.observacoes,
            (f.condominio_id IS NOT NULL) AS cadastrado';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param string $filtro '' (todos), 'em_dia', 'pendente', 'sem_cadastro' ou 'bloqueado'
     * @return list<array<string, mixed>>
     */
    public function listar(string $filtro = ''): array
    {
        $where = match ($filtro) {
            'em_dia' => "f.situacao = 'em_dia'",
            'pendente' => "f.situacao = 'pendente'",
            'sem_cadastro' => 'f.condominio_id IS NULL',
            'bloqueado' => 'c.bloqueado',
            default => 'true',
        };
        $linhas = $this->pdo->query(
            'SELECT ' . self::SELECAO . "
             FROM condominios c LEFT JOIN condominio_financeiro f ON f.condominio_id = c.id
             WHERE {$where}
             ORDER BY lower(c.nome), c.id"
        )->fetchAll();

        return array_map($this->normalizar(...), $linhas);
    }

    /** @return array<string, mixed>|null */
    public function porCondominio(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECAO . '
             FROM condominios c LEFT JOIN condominio_financeiro f ON f.condominio_id = c.id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Totais em centavos. "Pago" = condomínios em dia; "em aberto" = pendentes; condomínio sem valor não soma.
     *
     * @return array{total: int, em_dia: int, pendentes: int, sem_cadastro: int, bloqueados: int, valor_em_dia: int, valor_pendente: int}
     */
    public function resumo(): array
    {
        $l = $this->pdo->query(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE f.situacao = 'em_dia') AS em_dia,
                    count(*) FILTER (WHERE f.situacao = 'pendente') AS pendentes,
                    count(*) FILTER (WHERE f.condominio_id IS NULL) AS sem_cadastro,
                    count(*) FILTER (WHERE c.bloqueado) AS bloqueados,
                    COALESCE(sum(f.valor_mensal) FILTER (WHERE f.situacao = 'em_dia'), 0) AS valor_em_dia,
                    COALESCE(sum(f.valor_mensal) FILTER (WHERE f.situacao = 'pendente'), 0) AS valor_pendente
             FROM condominios c LEFT JOIN condominio_financeiro f ON f.condominio_id = c.id"
        )->fetch();

        return array_map('intval', $l);
    }

    public function salvar(int $condominioId, string $situacao, ?int $valorMensal, ?int $diaVencimento, ?string $observacoes): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO condominio_financeiro (condominio_id, situacao, valor_mensal, dia_vencimento, observacoes)
             VALUES (:id, :situacao, :valor, :dia, :obs)
             ON CONFLICT (condominio_id) DO UPDATE
             SET situacao = EXCLUDED.situacao, valor_mensal = EXCLUDED.valor_mensal,
                 dia_vencimento = EXCLUDED.dia_vencimento, observacoes = EXCLUDED.observacoes, atualizado_em = now()'
        );
        $stmt->bindValue('id', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('situacao', $situacao);
        $stmt->bindValue('valor', $valorMensal, $valorMensal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('dia', $diaVencimento, $diaVencimento === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('obs', $observacoes);
        $stmt->execute();
    }

    /** Bloqueia (com motivo) ou desbloqueia a exibição. Retorna false se o condomínio não existe. */
    public function definirBloqueio(int $condominioId, bool $bloqueado, ?string $motivo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE condominios
             SET bloqueado = :bloqueado, bloqueio_motivo = :motivo,
                 bloqueado_em = CASE WHEN :bloqueado2::boolean THEN now() ELSE NULL END
             WHERE id = :id'
        );
        $stmt->bindValue('bloqueado', $bloqueado, PDO::PARAM_BOOL);
        $stmt->bindValue('bloqueado2', $bloqueado, PDO::PARAM_BOOL);
        $stmt->bindValue('motivo', $bloqueado ? $motivo : null);
        $stmt->bindValue('id', $condominioId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private function normalizar(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'nome' => (string) $l['nome'],
            'ativo' => (bool) $l['ativo'],
            'bloqueado' => (bool) $l['bloqueado'],
            'bloqueio_motivo' => $l['bloqueio_motivo'] === null ? null : (string) $l['bloqueio_motivo'],
            'bloqueado_em' => $l['bloqueado_em'] === null ? null : (string) $l['bloqueado_em'],
            'cadastrado' => (bool) $l['cadastrado'],
            'situacao' => $l['situacao'] === null ? null : (string) $l['situacao'],
            'valor_mensal' => $l['valor_mensal'] === null ? null : (int) $l['valor_mensal'],
            'dia_vencimento' => $l['dia_vencimento'] === null ? null : (int) $l['dia_vencimento'],
            'observacoes' => $l['observacoes'] === null ? null : (string) $l['observacoes'],
        ];
    }
}
