-- Pareamento por código: a TV abre /tv, recebe um código curto, e o superadmin o digita no painel do dispositivo.
-- O segredo que a TV usa para consultar nunca é guardado: só o hash (SHA-256). O token do dispositivo não é copiado
-- para cá; ele é lido de `dispositivos` no momento em que a TV o recebe (uma única vez).
CREATE TABLE pareamentos (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo          varchar(6)  NOT NULL CONSTRAINT pareamentos_codigo_formato CHECK (codigo ~ '^[2-9A-HJKMNP-Z]{6}$'),
    segredo_hash    char(64)    NOT NULL UNIQUE,
    ip              varchar(45) NOT NULL,
    criado_em       timestamptz NOT NULL DEFAULT now(),
    expira_em       timestamptz NOT NULL,
    dispositivo_id  bigint REFERENCES dispositivos (id) ON DELETE CASCADE,
    pareado_em      timestamptz,
    pareado_por     bigint REFERENCES usuarios (id) ON DELETE SET NULL,
    entregue_em     timestamptz,
    CONSTRAINT pareamentos_pareado_coerente CHECK ((dispositivo_id IS NULL) = (pareado_em IS NULL))
);

-- Dois códigos pendentes nunca são iguais (o admin digita o código e precisa cair na TV certa).
CREATE UNIQUE INDEX pareamentos_codigo_pendente_idx ON pareamentos (codigo) WHERE pareado_em IS NULL;
CREATE INDEX pareamentos_criado_idx ON pareamentos (criado_em);
