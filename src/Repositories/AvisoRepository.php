<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Avisos. ISOLAMENTO ENTRE CONDOMÍNIOS: todo método recebe a lista de condomínios permitidos ao
 * usuário (lida do banco, nunca do cliente) e filtra por ela, além de filtrar pelo condomínio
 * pedido. Aviso de outro condomínio nunca é lido, alterado nem apagado (resultado: null/false).
 */
final class AvisoRepository
{
    // O tipo (nome, cor e se é fixo) vem de tipos_aviso: o do próprio condomínio tem preferência sobre o do sistema.
    private const COLUNAS = "avisos.id, avisos.condominio_id, avisos.titulo, avisos.corpo, avisos.tipo, avisos.midia, avisos.midia_tipo,
        avisos.ativo, avisos.criado_por,
        coalesce(t.id, 0) AS tipo_id, coalesce(t.nome, avisos.tipo) AS tipo_nome, t.cor AS tipo_cor, coalesce(t.fixo, false) AS tipo_fixo,
        extract(epoch FROM avisos.inicio_em)::bigint AS inicio_epoch,
        extract(epoch FROM avisos.fim_em)::bigint AS fim_epoch,
        extract(epoch FROM avisos.criado_em)::bigint AS criado_epoch";

    private const TIPO_JOIN = "LEFT JOIN LATERAL (
            SELECT tt.id, tt.nome, tt.cor, tt.fixo FROM tipos_aviso tt
            WHERE tt.chave = avisos.tipo AND (tt.condominio_id = avisos.condominio_id OR tt.condominio_id IS NULL)
            ORDER BY tt.condominio_id NULLS LAST LIMIT 1
        ) t ON true";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function listar(array $permitidos, int $condominioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM avisos ' . self::TIPO_JOIN . '
             WHERE avisos.condominio_id = :cid AND avisos.condominio_id = ANY(:permitidos::bigint[])
             ORDER BY avisos.inicio_em DESC, avisos.id DESC'
        );
        $stmt->execute(['cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function porId(array $permitidos, int $condominioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM avisos ' . self::TIPO_JOIN . '
             WHERE avisos.id = :id AND avisos.condominio_id = :cid AND avisos.condominio_id = ANY(:permitidos::bigint[])'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Cria o aviso. Se o condomínio não estiver entre os permitidos, nada é gravado (retorna null).
     *
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados titulo, corpo, tipo, midia, midia_tipo, inicio_epoch, fim_epoch, ativo
     */
    public function criar(array $permitidos, int $condominioId, array $dados, int $criadoPor): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO avisos (condominio_id, titulo, corpo, tipo, midia, midia_tipo, inicio_em, fim_em, ativo, criado_por)
             SELECT :cid::bigint, :titulo::text, :corpo::text, :tipo::text, :midia::text, :midia_tipo::text,
                    :inicio::timestamptz, :fim::timestamptz, :ativo::boolean, :criado_por::bigint
             WHERE :cid::bigint = ANY(:permitidos::bigint[])
             RETURNING id'
        );
        $this->vincular($stmt, $dados);
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('criado_por', $criadoPor, PDO::PARAM_INT);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados
     */
    public function atualizar(array $permitidos, int $condominioId, int $id, array $dados): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE avisos SET titulo = :titulo, corpo = :corpo, tipo = :tipo, midia = :midia, midia_tipo = :midia_tipo,
                    inicio_em = :inicio::timestamptz, fim_em = :fim::timestamptz, ativo = :ativo
             WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[])'
        );
        $this->vincular($stmt, $dados);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Exclui o aviso e devolve o nome da mídia dele (para apagar o arquivo), ou null se não existir
     * (ou for de outro condomínio).
     *
     * @param list<int> $permitidos
     * @return array{midia: ?string}|null
     */
    public function excluir(array $permitidos, int $condominioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM avisos WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[])
             RETURNING midia'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : ['midia' => $linha['midia'] === null ? null : (string) $linha['midia']];
    }

    /**
     * Avisos que a TV do condomínio precisa conhecer: ativos, que ainda não terminaram e que já
     * começaram ou começam dentro da antecedência (assim a TV consegue exibi-los na hora certa mesmo
     * sem internet). Quem decide o que mostrar a cada instante é a própria TV, pelo relógio corrigido.
     *
     * @param list<int> $permitidos condomínios permitidos (aqui, só o do dispositivo)
     * @return list<array<string, mixed>>
     */
    public function visiveisNaTv(array $permitidos, int $condominioId, int $agora, int $antecedenciaSegundos): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM avisos ' . self::TIPO_JOIN . '
             WHERE avisos.condominio_id = :cid AND avisos.condominio_id = ANY(:permitidos::bigint[]) AND avisos.ativo
               AND avisos.fim_em > to_timestamp(:agora::double precision)
               AND avisos.inicio_em <= to_timestamp((:agora::bigint + :antecedencia::bigint)::double precision)
             ORDER BY avisos.inicio_em, avisos.id'
        );
        $stmt->execute([
            'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos),
            'agora' => $agora, 'antecedencia' => $antecedenciaSegundos,
        ]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * O aviso visível na TV que usa esta mídia (nome do arquivo), ou null. Só mídias de avisos
     * visíveis (mesmo critério de visiveisNaTv) são entregues à TV.
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function porMidiaVisivelNaTv(array $permitidos, int $condominioId, string $midia, int $agora, int $antecedenciaSegundos): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM avisos ' . self::TIPO_JOIN . '
             WHERE avisos.midia = :midia AND avisos.condominio_id = :cid AND avisos.condominio_id = ANY(:permitidos::bigint[]) AND avisos.ativo
               AND avisos.fim_em > to_timestamp(:agora::double precision)
               AND avisos.inicio_em <= to_timestamp((:agora::bigint + :antecedencia::bigint)::double precision)
             LIMIT 1'
        );
        $stmt->execute([
            'midia' => $midia, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos),
            'agora' => $agora, 'antecedencia' => $antecedenciaSegundos,
        ]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** @param array<string, mixed> $dados */
    private function vincular(\PDOStatement $stmt, array $dados): void
    {
        $stmt->bindValue('titulo', $dados['titulo']);
        $stmt->bindValue('corpo', $dados['corpo']);
        $stmt->bindValue('tipo', $dados['tipo']);
        $stmt->bindValue('midia', $dados['midia'], $dados['midia'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('midia_tipo', $dados['midia_tipo'], $dados['midia_tipo'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('inicio', \App\Support\DataHora::paraBanco((int) $dados['inicio_epoch']));
        $stmt->bindValue('fim', \App\Support\DataHora::paraBanco((int) $dados['fim_epoch']));
        $stmt->bindValue('ativo', (bool) $dados['ativo'], PDO::PARAM_BOOL);
    }

    /**
     * @param array<string, mixed> $linha
     * @return array<string, mixed>
     */
    private function normalizar(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'condominio_id' => (int) $linha['condominio_id'],
            'titulo' => (string) $linha['titulo'],
            'corpo' => (string) $linha['corpo'],
            'tipo' => (string) $linha['tipo'],
            'tipo_id' => (int) $linha['tipo_id'],
            'tipo_nome' => (string) $linha['tipo_nome'],
            'tipo_cor' => $linha['tipo_cor'] === null ? null : (string) $linha['tipo_cor'],
            'fixo' => (bool) $linha['tipo_fixo'],
            'midia' => $linha['midia'] === null ? null : (string) $linha['midia'],
            'midia_tipo' => $linha['midia_tipo'] === null ? null : (string) $linha['midia_tipo'],
            'ativo' => (bool) $linha['ativo'],
            'criado_por' => $linha['criado_por'] === null ? null : (int) $linha['criado_por'],
            'inicio_epoch' => (int) $linha['inicio_epoch'],
            'fim_epoch' => (int) $linha['fim_epoch'],
            'criado_epoch' => (int) $linha['criado_epoch'],
        ];
    }
}
