-- 004: o token da TV é guardado sempre em MAIÚSCULAS. Na URL ele é aceito em qualquer caixa (o teclado
-- do controle remoto costuma começar em minúsculas), mas a comparação é feita já normalizada.
ALTER TABLE dispositivos ADD CONSTRAINT dispositivos_token_maiusculo CHECK (token = upper(token));
