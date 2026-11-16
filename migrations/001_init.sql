-- 001_init: tabelas centrais do sistema.
-- Migrations aplicadas são imutáveis: para corrigir algo, crie uma nova.

CREATE TABLE usuarios (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome          varchar(150) NOT NULL,
    email         varchar(190) NOT NULL,
    senha_hash    varchar(255) NOT NULL,
    papel         varchar(20)  NOT NULL,
    ativo         boolean      NOT NULL DEFAULT true,
    criado_em     timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT usuarios_papel_valido CHECK (papel IN ('superadmin', 'gerente'))
);

-- E-mail único sem diferenciar maiúsculas de minúsculas.
CREATE UNIQUE INDEX usuarios_email_unico ON usuarios (lower(email));

CREATE TABLE condominios (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome            varchar(150) NOT NULL,
    endereco        varchar(255),
    -- Nome do arquivo (gerado aleatoriamente) em storage/uploads. Nulo = logo padrão da Embracore.
    logo            varchar(255),
    -- Nulos = cores padrão da Embracore.
    cor_primaria    varchar(7),
    cor_secundaria  varchar(7),
    ativo           boolean     NOT NULL DEFAULT true,
    criado_em       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT condominios_cor_primaria_valida   CHECK (cor_primaria   IS NULL OR cor_primaria   ~ '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT condominios_cor_secundaria_valida CHECK (cor_secundaria IS NULL OR cor_secundaria ~ '^#[0-9A-Fa-f]{6}$')
);

-- Relação N:N entre gerentes e condomínios. É a fonte da verdade do isolamento entre tenants.
CREATE TABLE usuario_condominio (
    usuario_id     bigint NOT NULL REFERENCES usuarios (id)    ON DELETE CASCADE,
    condominio_id  bigint NOT NULL REFERENCES condominios (id) ON DELETE CASCADE,
    PRIMARY KEY (usuario_id, condominio_id)
);

CREATE INDEX usuario_condominio_condominio_idx ON usuario_condominio (condominio_id);

CREATE TABLE avisos (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Cada aviso pertence a um único condomínio.
    condominio_id  bigint       NOT NULL REFERENCES condominios (id) ON DELETE CASCADE,
    titulo         varchar(150) NOT NULL,
    corpo          text         NOT NULL DEFAULT '',
    tipo           varchar(20)  NOT NULL DEFAULT 'comum',
    -- Nome do arquivo (gerado aleatoriamente) em storage/uploads e seu tipo.
    midia          varchar(255),
    midia_tipo     varchar(10),
    inicio_em      timestamptz  NOT NULL,
    fim_em         timestamptz  NOT NULL,
    ativo          boolean      NOT NULL DEFAULT true,
    criado_por     bigint REFERENCES usuarios (id) ON DELETE SET NULL,
    criado_em      timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT avisos_tipo_valido    CHECK (tipo IN ('comum', 'urgente', 'manutencao', 'assembleia')),
    CONSTRAINT avisos_midia_tipo_ok  CHECK (midia_tipo IS NULL OR midia_tipo IN ('imagem', 'video')),
    CONSTRAINT avisos_midia_coerente CHECK ((midia IS NULL) = (midia_tipo IS NULL)),
    CONSTRAINT avisos_periodo_valido CHECK (fim_em > inicio_em)
);

-- Consulta principal da TV: avisos ativos de um condomínio dentro do período.
CREATE INDEX avisos_condominio_periodo_idx ON avisos (condominio_id, inicio_em, fim_em);

CREATE TABLE dispositivos (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    condominio_id  bigint       NOT NULL REFERENCES condominios (id) ON DELETE CASCADE,
    nome           varchar(100) NOT NULL,
    -- Token da URL /tv/{token}: 12 caracteres, curto para ser digitado no dispositivo.
    token          varchar(12)  NOT NULL,
    -- Heartbeat: atualizado a cada consulta da TV.
    ultimo_ping    timestamptz,
    -- false = token revogado.
    ativo          boolean      NOT NULL DEFAULT true,
    criado_em      timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT dispositivos_token_unico UNIQUE (token),
    CONSTRAINT dispositivos_token_tamanho CHECK (char_length(token) = 12)
);

CREATE INDEX dispositivos_condominio_idx ON dispositivos (condominio_id);
