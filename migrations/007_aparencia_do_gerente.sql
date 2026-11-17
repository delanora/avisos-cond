-- A aparência (cor de destaque) passa a ser do gerente e vale só para o painel dele.
-- Condomínio e TV deixam de ter logo e cores próprias.
ALTER TABLE usuarios
    ADD COLUMN cor_primaria varchar(7),
    ADD CONSTRAINT usuarios_cor_primaria_valida CHECK (cor_primaria IS NULL OR cor_primaria ~ '^#[0-9A-Fa-f]{6}$');

-- Os arquivos de logo já enviados ficam órfãos em storage/uploads e são apagados pela tarefa limpar_arquivos_orfaos.
ALTER TABLE condominios
    DROP COLUMN logo,
    DROP COLUMN cor_primaria,
    DROP COLUMN cor_secundaria;
