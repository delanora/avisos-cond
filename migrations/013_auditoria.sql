-- Auditoria: registro de quem fez o quê (e quando, de onde). Os nomes são guardados também como texto
-- (instantâneo), para o registro continuar legível mesmo depois de a pessoa, o condomínio ou o item serem excluídos.
CREATE TABLE auditoria (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    criado_em         timestamptz NOT NULL DEFAULT now(),
    -- Quem agiu de verdade (em modo suporte, o administrador).
    usuario_id        bigint REFERENCES usuarios (id) ON DELETE SET NULL,
    usuario_nome      varchar(150),
    -- Em modo suporte: o gerente cujo painel o administrador estava usando.
    como_usuario_id   bigint REFERENCES usuarios (id) ON DELETE SET NULL,
    como_usuario_nome varchar(150),
    acao              varchar(60) NOT NULL,
    entidade          varchar(40),
    entidade_id       bigint,
    condominio_id     bigint REFERENCES condominios (id) ON DELETE SET NULL,
    condominio_nome   varchar(150),
    -- Resumo do que aconteceu (nomes, títulos, situação). Nunca senha, token ou segredo.
    detalhes          jsonb NOT NULL DEFAULT '{}'::jsonb,
    ip                varchar(45)
);

CREATE INDEX auditoria_criado_idx ON auditoria (criado_em DESC, id DESC);
CREATE INDEX auditoria_usuario_idx ON auditoria (usuario_id);
CREATE INDEX auditoria_acao_idx ON auditoria (acao);
CREATE INDEX auditoria_condominio_idx ON auditoria (condominio_id);

-- Registro não se edita: só entra e, depois do prazo de retenção, sai (limpeza pelo cron).
CREATE FUNCTION auditoria_imutavel() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'registros de auditoria não podem ser alterados' USING ERRCODE = '23514';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER auditoria_imutavel BEFORE UPDATE ON auditoria
    FOR EACH ROW EXECUTE FUNCTION auditoria_imutavel();
