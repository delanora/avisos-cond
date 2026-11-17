-- Histórico de quando uma TV ficou offline e quando voltou (preenchido pela tarefa detectar_dispositivos_offline).
-- A situação "agora" não depende desta tabela: ela é calculada na hora por dispositivos.ultimo_ping.
CREATE TABLE dispositivo_eventos (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dispositivo_id  bigint NOT NULL REFERENCES dispositivos (id) ON DELETE CASCADE,
    tipo            varchar(10) NOT NULL,
    -- Último contato da TV no momento do registro (para mostrar "sem contato desde...").
    ultimo_ping_em  timestamptz,
    registrado_em   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT dispositivo_eventos_tipo_valido CHECK (tipo IN ('offline', 'online'))
);

CREATE INDEX dispositivo_eventos_dispositivo_idx ON dispositivo_eventos (dispositivo_id, id DESC);
