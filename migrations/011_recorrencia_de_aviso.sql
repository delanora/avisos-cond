-- Agendamento avançado: dentro do período do aviso (inicio_em a fim_em), ele só aparece nos dias da semana e na
-- janela de horário escolhidos (horário de Brasília). Tudo nulo = o aviso vale o período inteiro, como sempre foi.
ALTER TABLE avisos
    -- Bits 0 a 6 = domingo a sábado (1 = domingo, 2 = segunda, 4 = terça...). Nulo = todos os dias.
    ADD COLUMN dias_semana smallint,
    -- Janela diária [hora_inicio, hora_fim). As duas juntas ou nenhuma; não atravessa a meia-noite.
    ADD COLUMN hora_inicio time,
    ADD COLUMN hora_fim time,
    ADD CONSTRAINT avisos_dias_semana_valido CHECK (dias_semana IS NULL OR dias_semana BETWEEN 1 AND 127),
    ADD CONSTRAINT avisos_janela_completa CHECK ((hora_inicio IS NULL) = (hora_fim IS NULL)),
    ADD CONSTRAINT avisos_janela_valida CHECK (hora_inicio IS NULL OR hora_fim > hora_inicio);
