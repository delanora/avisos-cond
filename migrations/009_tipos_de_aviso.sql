-- Tipos de aviso deixam de ser uma lista fixa no código: cada condomínio pode criar os seus (nome, cor e se o
-- aviso fica fixo na faixa do topo da TV). Os cinco tipos que já existiam viram tipos "do sistema" (condominio_id
-- nulo), valem para todos os condomínios e não podem ser alterados nem apagados pelo gerente.
CREATE TABLE tipos_aviso (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    condominio_id  bigint REFERENCES condominios (id) ON DELETE CASCADE,
    -- Identificador estável (minúsculas, números e "-"): é o que fica gravado em avisos.tipo, então renomear o tipo não solta os avisos.
    chave          varchar(40) NOT NULL,
    nome           varchar(30) NOT NULL,
    -- Cor principal (#RRGGBB). Nula = visual neutro (cartão branco, etiqueta grafite). O tom claro do cartão é calculado a partir dela.
    cor            varchar(7),
    -- Fixo = aparece sempre na faixa do topo da TV (só texto), em vez de entrar no carrossel.
    fixo           boolean NOT NULL DEFAULT false,
    sistema        boolean NOT NULL DEFAULT false,
    criado_em      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT tipos_aviso_cor_valida CHECK (cor IS NULL OR cor ~ '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT tipos_aviso_chave_valida CHECK (chave ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT tipos_aviso_sistema_sem_condominio CHECK (sistema = (condominio_id IS NULL))
);

-- Uma chave por condomínio (e uma só entre os do sistema).
CREATE UNIQUE INDEX tipos_aviso_chave_unica ON tipos_aviso ((coalesce(condominio_id, 0)), chave);
CREATE INDEX tipos_aviso_condominio_idx ON tipos_aviso (condominio_id);

INSERT INTO tipos_aviso (condominio_id, chave, nome, cor, fixo, sistema) VALUES
    (NULL, 'comum',      'Comum',      NULL,      false, true),
    (NULL, 'fixo',       'Fixo',       '#3A3F8A', true,  true),
    (NULL, 'urgente',    'Urgente',    '#B42318', true,  true),
    (NULL, 'manutencao', 'Manutenção', '#C98A2B', false, true),
    (NULL, 'assembleia', 'Assembleia', '#1E4A93', false, true);

-- avisos.tipo continua guardando a chave; a lista fixa de valores aceitos (CHECK) sai e entra um gatilho que
-- garante o mesmo: o tipo precisa ser do sistema ou do MESMO condomínio do aviso (um aviso nunca usa o tipo de outro condomínio).
ALTER TABLE avisos DROP CONSTRAINT avisos_tipo_valido;
ALTER TABLE avisos ALTER COLUMN tipo TYPE varchar(40);

CREATE FUNCTION avisos_tipo_existe() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM tipos_aviso t
        WHERE t.chave = NEW.tipo AND (t.condominio_id IS NULL OR t.condominio_id = NEW.condominio_id)
    ) THEN
        RAISE EXCEPTION 'tipo de aviso inválido para este condomínio: %', NEW.tipo USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER avisos_tipo_existe BEFORE INSERT OR UPDATE OF tipo, condominio_id ON avisos
    FOR EACH ROW EXECUTE FUNCTION avisos_tipo_existe();
