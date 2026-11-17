-- 006: controle das tarefas do agendador (bin/cron.php). Guarda quando cada tarefa rodou pela última vez,
-- quanto levou, o resultado e a última falha. Serve para o agendador saber o que está "vencido" e, na Fase 2,
-- para o painel mostrar se o cron está saudável.
CREATE TABLE tarefas_agendadas (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome               varchar(100) NOT NULL,
    ultima_execucao    timestamptz,
    ultima_falha_em    timestamptz,
    ultima_duracao_ms  integer,
    ultimo_resultado   text,
    ultimo_erro        text,
    execucoes          bigint NOT NULL DEFAULT 0,
    falhas             bigint NOT NULL DEFAULT 0,
    CONSTRAINT tarefas_agendadas_nome_unico UNIQUE (nome),
    CONSTRAINT tarefas_agendadas_contagens CHECK (execucoes >= 0 AND falhas >= 0)
);
