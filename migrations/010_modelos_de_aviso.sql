-- Modelos de aviso: texto pronto que o gerente escolhe ao criar um aviso. Os quatro iniciais são do sistema
-- (condominio_id nulo, valem para todos e não mudam); o gerente cria os dele por condomínio.
CREATE TABLE modelos_aviso (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    condominio_id  bigint REFERENCES condominios (id) ON DELETE CASCADE,
    nome           varchar(60) NOT NULL,
    titulo         varchar(150) NOT NULL,
    corpo          text NOT NULL DEFAULT '',
    -- Chave do tipo sugerido (tipos_aviso.chave). Sem chave de verdade ligada: se o tipo sumir, o modelo cai em "comum".
    tipo           varchar(40) NOT NULL DEFAULT 'comum',
    sistema        boolean NOT NULL DEFAULT false,
    criado_em      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT modelos_aviso_sistema_sem_condominio CHECK (sistema = (condominio_id IS NULL))
);

CREATE INDEX modelos_aviso_condominio_idx ON modelos_aviso (condominio_id);
CREATE UNIQUE INDEX modelos_aviso_nome_unico ON modelos_aviso ((coalesce(condominio_id, 0)), lower(nome));

INSERT INTO modelos_aviso (condominio_id, nome, titulo, corpo, tipo, sistema) VALUES
    (NULL, 'Falta de água', 'Falta de água', E'Por causa de [motivo], o abastecimento de água será interrompido em [data], das [hora] às [hora].\nPedimos que reservem água para o período. Obrigado pela compreensão.', 'urgente', true),
    (NULL, 'Manutenção do elevador', 'Manutenção do elevador', E'O elevador [social/de serviço] ficará parado em [data], das [hora] às [hora], para manutenção preventiva.\nPedimos desculpas pelo transtorno.', 'manutencao', true),
    (NULL, 'Dedetização', 'Dedetização', E'Será realizada a dedetização em [data], a partir das [hora].\nMantenham animais de estimação e alimentos protegidos e avisem a portaria caso precisem reagendar.', 'manutencao', true),
    (NULL, 'Assembleia', 'Assembleia geral', E'Convocamos todos os condôminos para a assembleia em [data], às [hora], no [local].\nPauta: [pauta]. A sua presença é muito importante.', 'assembleia', true);
