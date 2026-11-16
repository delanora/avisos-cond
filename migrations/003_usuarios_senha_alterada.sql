-- 003: momento da última troca de senha. Sessões iniciadas antes dele deixam de valer,
-- o que derruba os logins antigos quando a senha é redefinida.
ALTER TABLE usuarios ADD COLUMN senha_alterada_em timestamptz;
