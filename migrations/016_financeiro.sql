-- Fase 3: situação financeira (digitada à mão) e bloqueio manual da exibição por condomínio.
-- A cobrança em si é feita em outro sistema; aqui só se consulta e se bloqueia.

ALTER TABLE condominios
    ADD COLUMN bloqueado       boolean     NOT NULL DEFAULT false,
    ADD COLUMN bloqueio_motivo text,
    ADD COLUMN bloqueado_em    timestamptz,
    ADD CONSTRAINT condominios_bloqueio_com_motivo CHECK (NOT bloqueado OR (bloqueio_motivo IS NOT NULL AND length(btrim(bloqueio_motivo)) > 0));

CREATE TABLE condominio_financeiro (
    condominio_id  bigint       PRIMARY KEY REFERENCES condominios (id) ON DELETE CASCADE,
    situacao       varchar(10)  NOT NULL DEFAULT 'em_dia',
    valor_mensal   integer,
    dia_vencimento smallint,
    observacoes    text,
    atualizado_em  timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT financeiro_situacao_valida CHECK (situacao IN ('em_dia', 'pendente')),
    CONSTRAINT financeiro_valor_valido    CHECK (valor_mensal IS NULL OR valor_mensal >= 0),
    CONSTRAINT financeiro_dia_valido      CHECK (dia_vencimento IS NULL OR dia_vencimento BETWEEN 1 AND 31)
);
