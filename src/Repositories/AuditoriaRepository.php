<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Auditoria: só entra registro (nunca se altera) e, passada a retenção, a limpeza apaga os antigos. */
final class AuditoriaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $r */
    public function inserir(array $r): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auditoria (usuario_id, usuario_nome, como_usuario_id, como_usuario_nome, acao, entidade, entidade_id,
                                    condominio_id, condominio_nome, detalhes, ip)
             VALUES (:usuario_id, :usuario_nome, :como_id, :como_nome, :acao, :entidade, :entidade_id, :condominio_id, :condominio_nome, :detalhes::jsonb, :ip)'
        );
        foreach (['usuario_id', 'como_id', 'entidade_id', 'condominio_id'] as $campo) {
            $v = $r[$campo] ?? null;
            $stmt->bindValue($campo, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        foreach (['usuario_nome', 'como_nome', 'entidade', 'condominio_nome', 'ip'] as $campo) {
            $v = $r[$campo] ?? null;
            $stmt->bindValue($campo, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue('acao', (string) $r['acao']);
        $stmt->bindValue('detalhes', json_encode($r['detalhes'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $stmt->execute();
    }

    /**
     * Registros mais novos primeiro, com filtros opcionais.
     *
     * @param array{acao?: string, grupo?: string, usuario_id?: int, condominio_id?: int, de_epoch?: int, ate_epoch?: int} $filtros
     * @return array{linhas: list<array<string, mixed>>, total: int}
     */
    public function listar(array $filtros, int $limite, int $deslocamento): array
    {
        [$onde, $params] = $this->condicoes($filtros);

        $stmt = $this->pdo->prepare("SELECT count(*) FROM auditoria {$onde}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT id, extract(epoch FROM criado_em)::bigint AS criado_epoch, usuario_id, usuario_nome, como_usuario_id, como_usuario_nome,
                    acao, entidade, entidade_id, condominio_id, condominio_nome, detalhes::text AS detalhes, ip
             FROM auditoria {$onde} ORDER BY criado_em DESC, id DESC LIMIT :limite OFFSET :deslocamento"
        );
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue('deslocamento', $deslocamento, PDO::PARAM_INT);
        $stmt->execute();

        return ['linhas' => array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'criado_epoch' => (int) $l['criado_epoch'],
            'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'],
            'usuario_nome' => $l['usuario_nome'],
            'como_usuario_id' => $l['como_usuario_id'] === null ? null : (int) $l['como_usuario_id'],
            'como_usuario_nome' => $l['como_usuario_nome'],
            'acao' => (string) $l['acao'],
            'entidade' => $l['entidade'],
            'entidade_id' => $l['entidade_id'] === null ? null : (int) $l['entidade_id'],
            'condominio_id' => $l['condominio_id'] === null ? null : (int) $l['condominio_id'],
            'condominio_nome' => $l['condominio_nome'],
            'detalhes' => json_decode((string) $l['detalhes'], true) ?: [],
            'ip' => $l['ip'],
        ], $stmt->fetchAll()), 'total' => $total];
    }

    /** Pessoas que aparecem na auditoria (para o filtro da tela). @return list<array{id: int, nome: string}> */
    public function pessoas(): array
    {
        $linhas = $this->pdo->query(
            'SELECT usuario_id AS id, max(usuario_nome) AS nome FROM auditoria WHERE usuario_id IS NOT NULL GROUP BY usuario_id ORDER BY lower(max(usuario_nome))'
        )->fetchAll();

        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'nome' => (string) $l['nome']], $linhas);
    }

    /** Apaga o que passou do prazo de retenção. Retorna quantos registros saíram. */
    public function removerAntigos(int $dias): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM auditoria WHERE criado_em < now() - make_interval(days => :dias)");
        $stmt->bindValue('dias', $dias, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $f
     * @return array{string, array<string, int|string>}
     */
    private function condicoes(array $f): array
    {
        $partes = [];
        $params = [];
        if (isset($f['acao']) && $f['acao'] !== '') {
            $partes[] = 'acao = :acao';
            $params['acao'] = (string) $f['acao'];
        }
        if (isset($f['grupo']) && $f['grupo'] !== '') {
            $partes[] = 'acao LIKE :grupo';
            $params['grupo'] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['grupo']) . '.%';
        }
        if (isset($f['usuario_id'])) {
            $partes[] = '(usuario_id = :usuario_id OR como_usuario_id = :usuario_id)';
            $params['usuario_id'] = (int) $f['usuario_id'];
        }
        if (isset($f['condominio_id'])) {
            $partes[] = 'condominio_id = :condominio_id';
            $params['condominio_id'] = (int) $f['condominio_id'];
        }
        if (isset($f['de_epoch'])) {
            $partes[] = 'criado_em >= to_timestamp(:de::double precision)';
            $params['de'] = (int) $f['de_epoch'];
        }
        if (isset($f['ate_epoch'])) {
            $partes[] = 'criado_em < to_timestamp(:ate::double precision)';
            $params['ate'] = (int) $f['ate_epoch'];
        }

        return [$partes === [] ? '' : 'WHERE ' . implode(' AND ', $partes), $params];
    }
}
