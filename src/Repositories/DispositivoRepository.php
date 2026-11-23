<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DispositivoRepository
{
    private const COLUNAS = 'd.id, d.condominio_id, d.nome, d.token, d.ativo, d.orientacao,
        extract(epoch FROM d.ultimo_ping)::bigint AS ultimo_ping_epoch,
        extract(epoch FROM d.criado_em)::bigint AS criado_epoch,
        c.nome AS condominio_nome';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        $linhas = $this->pdo->query(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             ORDER BY lower(c.nome), lower(d.nome), d.id'
        )->fetchAll();

        return array_map($this->normalizar(...), $linhas);
    }

    /**
     * Dispositivos dos condomínios permitidos (o gerente só vê os dos condomínios dele).
     *
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function listarDosCondominios(array $permitidos): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             WHERE d.condominio_id = ANY(:permitidos::bigint[])
             ORDER BY lower(c.nome), lower(d.nome), d.id'
        );
        $stmt->execute(['permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * Um dispositivo, só se for de um condomínio permitido (senão null).
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function porIdDosCondominios(array $permitidos, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             WHERE d.id = :id AND d.condominio_id = ANY(:permitidos::bigint[])'
        );
        $stmt->execute(['id' => $id, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Dispositivo e condomínio dele pelo token (já normalizado). Usado pela tela da TV.
     *
     * @return array{dispositivo: array<string, mixed>, condominio: array<string, mixed>}|null
     */
    public function porToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.condominio_id, d.nome, d.token, d.ativo, d.orientacao,
                    c.nome AS condominio_nome, c.ativo AS condominio_ativo
             FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             WHERE d.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $l = $stmt->fetch();
        if ($l === false) {
            return null;
        }

        return [
            'dispositivo' => ['id' => (int) $l['id'], 'condominio_id' => (int) $l['condominio_id'], 'nome' => (string) $l['nome'], 'token' => (string) $l['token'], 'ativo' => (bool) $l['ativo'], 'orientacao' => (string) $l['orientacao']],
            'condominio' => [
                'id' => (int) $l['condominio_id'],
                'nome' => (string) $l['condominio_nome'],
                'ativo' => (bool) $l['condominio_ativo'],
            ],
        ];
    }

    /** Cria o dispositivo. Retorna null se o token já existir (o chamador sorteia outro). */
    public function criar(int $condominioId, string $nome, string $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dispositivos (condominio_id, nome, token) VALUES (:condominio, :nome, :token)
             ON CONFLICT (token) DO NOTHING RETURNING id'
        );
        $stmt->execute(['condominio' => $condominioId, 'nome' => $nome, 'token' => $token]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function atualizar(int $id, int $condominioId, string $nome, bool $ativo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispositivos SET condominio_id = :condominio, nome = :nome, ativo = :ativo WHERE id = :id'
        );
        $stmt->bindValue('condominio', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Troca o token (o antigo deixa de funcionar). Retorna false se o novo já existir ou o dispositivo não existir. */
    public function trocarToken(int $id, string $novoToken): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispositivos SET token = :token, ultimo_ping = NULL
             WHERE id = :id AND NOT EXISTS (SELECT 1 FROM dispositivos WHERE token = :token)'
        );
        $stmt->execute(['token' => $novoToken, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Define a orientação (o valor já foi validado pelo serviço; o banco também confere). Retorna false se o dispositivo não existe. */
    public function atualizarOrientacao(int $id, string $orientacao): bool
    {
        $stmt = $this->pdo->prepare('UPDATE dispositivos SET orientacao = :orientacao WHERE id = :id');
        $stmt->execute(['orientacao' => $orientacao, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Igual a atualizarOrientacao, mas só vale para dispositivo de condomínio permitido (isolamento do gerente). */
    public function atualizarOrientacaoDosCondominios(array $permitidos, int $id, string $orientacao): bool
    {
        $stmt = $this->pdo->prepare('UPDATE dispositivos SET orientacao = :orientacao WHERE id = :id AND condominio_id = ANY(:permitidos::bigint[])');
        $stmt->execute(['orientacao' => $orientacao, 'id' => $id, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return $stmt->rowCount() === 1;
    }

    /** Heartbeat: cada consulta da TV ao /state atualiza o último contato. */
    public function registrarPing(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE dispositivos SET ultimo_ping = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Registra as mudanças de situação (ficou offline / voltou) das TVs ativas que já se conectaram alguma vez.
     * Idempotente: só grava quando a situação difere do último evento do dispositivo, então rodar de novo
     * não duplica nada. Uma TV que sempre esteve online não gera evento.
     *
     * @return array{offline: int, online: int} quantos eventos de cada tipo foram gravados
     */
    public function registrarMudancasDeSituacao(int $limiteSegundos): array
    {
        $stmt = $this->pdo->prepare(
            "WITH atuais AS (
                 SELECT d.id, d.ultimo_ping,
                        CASE WHEN d.ultimo_ping >= now() - make_interval(secs => :limite) THEN 'online' ELSE 'offline' END AS situacao,
                        (SELECT e.tipo FROM dispositivo_eventos e WHERE e.dispositivo_id = d.id ORDER BY e.id DESC LIMIT 1) AS ultimo_tipo
                 FROM dispositivos d
                 WHERE d.ativo AND d.ultimo_ping IS NOT NULL
             )
             INSERT INTO dispositivo_eventos (dispositivo_id, tipo, ultimo_ping_em)
             SELECT id, situacao, ultimo_ping FROM atuais
             WHERE (situacao = 'offline' AND ultimo_tipo IS DISTINCT FROM 'offline')
                OR (situacao = 'online' AND ultimo_tipo = 'offline')
             RETURNING tipo"
        );
        $stmt->bindValue('limite', $limiteSegundos, PDO::PARAM_INT);
        $stmt->execute();

        $contagem = ['offline' => 0, 'online' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tipo) {
            $contagem[(string) $tipo]++;
        }

        return $contagem;
    }

    /**
     * Histórico de quedas e retornos de um dispositivo, do mais recente para o mais antigo.
     *
     * @return list<array{tipo: string, registrado_epoch: int, ultimo_ping_epoch: ?int}>
     */
    public function eventos(int $dispositivoId, int $limite = 20, int $deslocamento = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo, extract(epoch FROM registrado_em)::bigint AS registrado_epoch,
                    extract(epoch FROM ultimo_ping_em)::bigint AS ultimo_ping_epoch
             FROM dispositivo_eventos WHERE dispositivo_id = :id ORDER BY id DESC LIMIT :limite OFFSET :deslocamento'
        );
        $stmt->bindValue('id', $dispositivoId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue('deslocamento', $deslocamento, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $l): array => [
            'tipo' => (string) $l['tipo'],
            'registrado_epoch' => (int) $l['registrado_epoch'],
            'ultimo_ping_epoch' => $l['ultimo_ping_epoch'] === null ? null : (int) $l['ultimo_ping_epoch'],
        ], $stmt->fetchAll());
    }

    public function contarEventos(int $dispositivoId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM dispositivo_eventos WHERE dispositivo_id = :id');
        $stmt->execute(['id' => $dispositivoId]);

        return (int) $stmt->fetchColumn();
    }

    public function excluir(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM dispositivos WHERE id = :id');
        $stmt->execute(['id' => $id]);

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
            'condominio_id' => (int) $l['condominio_id'],
            'condominio_nome' => (string) $l['condominio_nome'],
            'nome' => (string) $l['nome'],
            'token' => (string) $l['token'],
            'ativo' => (bool) $l['ativo'],
            'orientacao' => (string) $l['orientacao'],
            'ultimo_ping_epoch' => $l['ultimo_ping_epoch'] === null ? null : (int) $l['ultimo_ping_epoch'],
            'criado_epoch' => (int) $l['criado_epoch'],
        ];
    }
}
