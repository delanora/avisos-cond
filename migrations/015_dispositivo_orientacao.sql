-- Orientação da tela de cada dispositivo: horizontal (padrão) ou vertical (monitor virado em pé).
ALTER TABLE dispositivos
    ADD COLUMN orientacao varchar(10) NOT NULL DEFAULT 'horizontal'
        CONSTRAINT dispositivos_orientacao_valida CHECK (orientacao IN ('horizontal', 'vertical'));
