<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Entrega ao gerente a SUA cópia dos tipos e modelos de fábrica (as matrizes, com sistema = true), uma única vez.
 * Depois disso ele edita e apaga os dele como quiser, e o que foi apagado não volta (a marca fica em
 * usuarios.padroes_criados). Num só comando, para ser atômico mesmo com duas requisições simultâneas.
 */
final class PadroesDoGerenteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return bool true se as cópias foram criadas agora (false: já existiam ou o usuário não é gerente) */
    public function garantir(int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'WITH reivindicado AS (
                 UPDATE usuarios SET padroes_criados = true
                 WHERE id = :id AND papel = \'gerente\' AND NOT padroes_criados RETURNING id
             ), tipos AS (
                 INSERT INTO tipos_aviso (usuario_id, chave, nome, cor, fixo)
                 SELECT r.id, t.chave, t.nome, t.cor, t.fixo FROM reivindicado r CROSS JOIN tipos_aviso t
                 WHERE t.sistema ORDER BY t.id
                 RETURNING 1
             ), modelos AS (
                 INSERT INTO modelos_aviso (usuario_id, nome, titulo, corpo, tipo)
                 SELECT r.id, m.nome, m.titulo, m.corpo, m.tipo FROM reivindicado r CROSS JOIN modelos_aviso m
                 WHERE m.sistema ORDER BY m.id
                 RETURNING 1
             )
             SELECT count(*) FROM reivindicado'
        );
        $stmt->execute(['id' => $usuarioId]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
