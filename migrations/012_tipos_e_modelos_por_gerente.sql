-- Tipos e modelos de aviso passam a ser de cada GERENTE (não mais do condomínio): o de um gerente nunca aparece
-- para o outro, e cada gerente pode apagar até os que vêm de fábrica. Os cinco tipos e os quatro modelos "do
-- sistema" viram apenas MATRIZES (usuario_id nulo, sistema = true): cada gerente recebe a sua cópia na primeira
-- vez que usa o painel (usuarios.padroes_criados) e depois mexe nela como quiser.
ALTER TABLE usuarios ADD COLUMN padroes_criados boolean NOT NULL DEFAULT false;

ALTER TABLE tipos_aviso ADD COLUMN usuario_id bigint REFERENCES usuarios (id) ON DELETE SET NULL;
ALTER TABLE modelos_aviso ADD COLUMN usuario_id bigint REFERENCES usuarios (id) ON DELETE CASCADE;
-- As cópias dos gerentes não têm condomínio nem são "do sistema": solta as regras antigas antes de copiar.
ALTER TABLE tipos_aviso DROP CONSTRAINT tipos_aviso_sistema_sem_condominio;
ALTER TABLE modelos_aviso DROP CONSTRAINT modelos_aviso_sistema_sem_condominio;
DROP INDEX tipos_aviso_chave_unica;
DROP INDEX modelos_aviso_nome_unico;

-- 1) Cada gerente existente recebe a sua cópia das matrizes.
INSERT INTO tipos_aviso (usuario_id, chave, nome, cor, fixo)
SELECT u.id, t.chave, t.nome, t.cor, t.fixo FROM usuarios u CROSS JOIN tipos_aviso t
WHERE u.papel = 'gerente' AND t.sistema ORDER BY u.id, t.id;

INSERT INTO modelos_aviso (usuario_id, nome, titulo, corpo, tipo)
SELECT u.id, m.nome, m.titulo, m.corpo, m.tipo FROM usuarios u CROSS JOIN modelos_aviso m
WHERE u.papel = 'gerente' AND m.sistema ORDER BY u.id, m.id;

UPDATE usuarios SET padroes_criados = true WHERE papel = 'gerente';

-- 2) Tipos e modelos que já eram de um condomínio viram cópias de cada gerente vinculado a ele.
INSERT INTO tipos_aviso (usuario_id, chave, nome, cor, fixo)
SELECT DISTINCT uc.usuario_id, t.chave, t.nome, t.cor, t.fixo
FROM tipos_aviso t
JOIN usuario_condominio uc ON uc.condominio_id = t.condominio_id
JOIN usuarios u ON u.id = uc.usuario_id AND u.papel = 'gerente'
WHERE t.condominio_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM tipos_aviso x WHERE x.usuario_id = uc.usuario_id AND x.chave = t.chave);

INSERT INTO modelos_aviso (usuario_id, nome, titulo, corpo, tipo)
SELECT DISTINCT uc.usuario_id, m.nome, m.titulo, m.corpo, m.tipo
FROM modelos_aviso m
JOIN usuario_condominio uc ON uc.condominio_id = m.condominio_id
JOIN usuarios u ON u.id = uc.usuario_id AND u.papel = 'gerente'
WHERE m.condominio_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM modelos_aviso x WHERE x.usuario_id = uc.usuario_id AND lower(x.nome) = lower(m.nome));

DELETE FROM modelos_aviso WHERE condominio_id IS NOT NULL;

-- 3) Cada aviso passa a apontar para um tipo (tipo_id) em vez de guardar a chave. Prefere o tipo do gerente que
-- criou o aviso; senão, o de qualquer gerente vinculado ao condomínio; senão, a matriz; senão, o tipo antigo do condomínio.
ALTER TABLE avisos ADD COLUMN tipo_id bigint;

UPDATE avisos a SET tipo_id = (
    SELECT t.id FROM tipos_aviso t
    WHERE t.chave = a.tipo AND t.usuario_id IS NOT NULL
      AND EXISTS (SELECT 1 FROM usuario_condominio uc WHERE uc.usuario_id = t.usuario_id AND uc.condominio_id = a.condominio_id)
    ORDER BY (t.usuario_id = a.criado_por) DESC NULLS LAST, t.usuario_id LIMIT 1);

UPDATE avisos a SET tipo_id = (SELECT t.id FROM tipos_aviso t WHERE t.condominio_id = a.condominio_id AND t.chave = a.tipo)
WHERE a.tipo_id IS NULL;

UPDATE avisos a SET tipo_id = (SELECT t.id FROM tipos_aviso t WHERE t.sistema AND t.chave = a.tipo)
WHERE a.tipo_id IS NULL;

-- Tipos antigos de condomínio que nenhum aviso usa saem; os que ainda são usados ficam "sem dono" (invisíveis).
DELETE FROM tipos_aviso WHERE condominio_id IS NOT NULL AND id NOT IN (SELECT tipo_id FROM avisos WHERE tipo_id IS NOT NULL);

DROP TRIGGER avisos_tipo_existe ON avisos;
DROP FUNCTION avisos_tipo_existe();
ALTER TABLE avisos ALTER COLUMN tipo_id SET NOT NULL;
ALTER TABLE avisos DROP COLUMN tipo;
ALTER TABLE avisos ADD CONSTRAINT avisos_tipo_id_fkey FOREIGN KEY (tipo_id) REFERENCES tipos_aviso (id) ON DELETE RESTRICT;
CREATE INDEX avisos_tipo_idx ON avisos (tipo_id);

-- 4) Tipos e modelos deixam de ter condomínio.
ALTER TABLE tipos_aviso DROP COLUMN condominio_id;
ALTER TABLE tipos_aviso ADD CONSTRAINT tipos_aviso_matriz_sem_dono CHECK (NOT sistema OR usuario_id IS NULL);
CREATE UNIQUE INDEX tipos_aviso_chave_por_gerente ON tipos_aviso (usuario_id, chave) WHERE usuario_id IS NOT NULL;
CREATE UNIQUE INDEX tipos_aviso_chave_da_matriz ON tipos_aviso (chave) WHERE sistema;
CREATE INDEX tipos_aviso_usuario_idx ON tipos_aviso (usuario_id);

ALTER TABLE modelos_aviso DROP COLUMN condominio_id;
ALTER TABLE modelos_aviso ADD CONSTRAINT modelos_aviso_matriz_sem_dono CHECK (NOT sistema OR usuario_id IS NULL);
CREATE UNIQUE INDEX modelos_aviso_nome_por_gerente ON modelos_aviso (usuario_id, lower(nome)) WHERE usuario_id IS NOT NULL;
CREATE UNIQUE INDEX modelos_aviso_nome_da_matriz ON modelos_aviso (lower(nome)) WHERE sistema;
CREATE INDEX modelos_aviso_usuario_idx ON modelos_aviso (usuario_id);

-- 5) Integridade no banco: o tipo de um aviso é uma matriz/sem dono (legado) ou de um gerente vinculado ao condomínio do aviso.
CREATE FUNCTION avisos_tipo_valido() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM tipos_aviso t
        WHERE t.id = NEW.tipo_id
          AND (t.usuario_id IS NULL
               OR EXISTS (SELECT 1 FROM usuario_condominio uc WHERE uc.usuario_id = t.usuario_id AND uc.condominio_id = NEW.condominio_id))
    ) THEN
        RAISE EXCEPTION 'tipo de aviso inválido para este condomínio: %', NEW.tipo_id USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER avisos_tipo_valido BEFORE INSERT OR UPDATE OF tipo_id, condominio_id ON avisos
    FOR EACH ROW EXECUTE FUNCTION avisos_tipo_valido();
