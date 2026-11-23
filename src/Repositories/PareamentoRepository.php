<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

/** Pareamentos de TV por código (tabela `pareamentos`). Sem condomínio aqui: quem pareia é só o superadmin. */
final class PareamentoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Apaga os códigos pendentes vencidos (liberam o código para outro sorteio). */
    public function removerPendentesVencidos(): void
    {
        $this->pdo->exec('DELETE FROM pareamentos WHERE pareado_em IS NULL AND expira_em < now()');
    }

    /** Cria o pareamento. Retorna false se o código pendente já existe (o chamador sorteia outro). */
    public function criar(string $codigo, string $segredoHash, string $ip, int $validadeSegundos): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO pareamentos (codigo, segredo_hash, ip, expira_em)
                 VALUES (:codigo, :segredo, :ip, now() + make_interval(secs => :validade))"
            );
            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('segredo', $segredoHash);
            $stmt->bindValue('ip', $ip);
            $stmt->bindValue('validade', $validadeSegundos, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Estado do pareamento pelo hash do segredo.
     *
     * @return ?array{id: int, pareado: bool, entregue: bool, expirado: bool, dispositivo_id: ?int, expira_epoch: int}
     */
    public function porSegredo(string $segredoHash, int $janelaDeEntregaSegundos): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, dispositivo_id, pareado_em IS NOT NULL AS pareado, entregue_em IS NOT NULL AS entregue,
                    extract(epoch FROM expira_em)::bigint AS expira_epoch,
                    CASE WHEN pareado_em IS NULL THEN expira_em < now()
                         ELSE pareado_em < now() - make_interval(secs => :janela) END AS expirado
             FROM pareamentos WHERE segredo_hash = :segredo'
        );
        $stmt->bindValue('segredo', $segredoHash);
        $stmt->bindValue('janela', $janelaDeEntregaSegundos, PDO::PARAM_INT);
        $stmt->execute();
        $l = $stmt->fetch();
        if ($l === false) {
            return null;
        }

        return [
            'id' => (int) $l['id'],
            'pareado' => (bool) $l['pareado'],
            'entregue' => (bool) $l['entregue'],
            'expirado' => (bool) $l['expirado'],
            'dispositivo_id' => $l['dispositivo_id'] === null ? null : (int) $l['dispositivo_id'],
            'expira_epoch' => (int) $l['expira_epoch'],
        ];
    }

    /**
     * Liga o código pendente (não vencido) ao dispositivo. Retorna o IP de quem pediu o código,
     * ou null se o código não existe, venceu ou já foi usado. É atômico: um código só pareia uma vez.
     */
    public function parear(string $codigo, int $dispositivoId, int $usuarioId): ?string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pareamentos SET dispositivo_id = :dispositivo, pareado_em = now(), pareado_por = :usuario
             WHERE codigo = :codigo AND pareado_em IS NULL AND expira_em > now()
             RETURNING ip'
        );
        $stmt->bindValue('dispositivo', $dispositivoId, PDO::PARAM_INT);
        $stmt->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('codigo', $codigo);
        $stmt->execute();
        $ip = $stmt->fetchColumn();

        return $ip === false ? null : (string) $ip;
    }

    /** Marca como entregue (uma vez só). Retorna true para quem ganhou a corrida e deve entregar o token. */
    public function marcarEntregue(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE pareamentos SET entregue_em = now() WHERE id = :id AND entregue_em IS NULL');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Apaga pareamentos criados há mais de `$segundos`. Retorna quantos saíram. */
    public function removerAntigos(int $segundos): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM pareamentos WHERE criado_em < now() - make_interval(secs => :s)');
        $stmt->bindValue('s', $segundos, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
