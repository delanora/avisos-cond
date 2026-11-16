-- 002_tabelas_apoio: recuperação de senha e rate limit.

CREATE TABLE password_resets (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id  bigint      NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
    -- SHA-256 (hex) do token enviado por e-mail. O token em si nunca é guardado.
    token_hash  char(64)    NOT NULL,
    expira_em   timestamptz NOT NULL,
    -- Preenchido quando o token é usado (uso único).
    usado_em    timestamptz,
    criado_em   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT password_resets_token_unico UNIQUE (token_hash)
);

CREATE INDEX password_resets_usuario_idx ON password_resets (usuario_id);

-- Contadores de tentativas (login, recuperação de senha, token de TV inválido).
-- A chave identifica o contador, ex.: "login:ip:203.0.113.7". Use hash para dados pessoais (e-mail).
CREATE TABLE rate_limits (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chave          varchar(190) NOT NULL,
    tentativas     integer      NOT NULL DEFAULT 0,
    janela_inicio  timestamptz  NOT NULL DEFAULT now(),
    bloqueado_ate  timestamptz,
    CONSTRAINT rate_limits_chave_unica UNIQUE (chave),
    CONSTRAINT rate_limits_tentativas_nao_negativas CHECK (tentativas >= 0)
);

-- Limpeza de contadores antigos.
CREATE INDEX rate_limits_janela_idx ON rate_limits (janela_inicio);
