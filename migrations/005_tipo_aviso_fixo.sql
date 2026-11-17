-- 005: novo tipo de aviso "fixo". Avisos fixos e urgentes ocupam uma faixa reservada no topo da TV;
-- os demais (comum, manutenção, assembleia) continuam em carrossel abaixo dela.
ALTER TABLE avisos DROP CONSTRAINT avisos_tipo_valido;
ALTER TABLE avisos ADD CONSTRAINT avisos_tipo_valido
    CHECK (tipo IN ('comum', 'urgente', 'fixo', 'manutencao', 'assembleia'));
