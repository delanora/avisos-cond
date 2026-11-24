-- Correção da 013: o gatilho de imutabilidade barrava também o ON DELETE SET NULL das chaves estrangeiras,
-- então não dava para excluir um condomínio ou usuário que já tivesse registro de auditoria.
-- Agora a única alteração aceita é um desses três vínculos passar para NULL (os nomes copiados continuam no registro).
CREATE OR REPLACE FUNCTION auditoria_imutavel() RETURNS trigger AS $$
BEGIN
    IF (NEW.id, NEW.criado_em, NEW.usuario_nome, NEW.como_usuario_nome, NEW.acao, NEW.entidade, NEW.entidade_id, NEW.condominio_nome, NEW.detalhes, NEW.ip)
           IS NOT DISTINCT FROM
       (OLD.id, OLD.criado_em, OLD.usuario_nome, OLD.como_usuario_nome, OLD.acao, OLD.entidade, OLD.entidade_id, OLD.condominio_nome, OLD.detalhes, OLD.ip)
       AND (NEW.usuario_id IS NULL OR NEW.usuario_id IS NOT DISTINCT FROM OLD.usuario_id)
       AND (NEW.como_usuario_id IS NULL OR NEW.como_usuario_id IS NOT DISTINCT FROM OLD.como_usuario_id)
       AND (NEW.condominio_id IS NULL OR NEW.condominio_id IS NOT DISTINCT FROM OLD.condominio_id)
    THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'registros de auditoria não podem ser alterados' USING ERRCODE = '23514';
END;
$$ LANGUAGE plpgsql;
