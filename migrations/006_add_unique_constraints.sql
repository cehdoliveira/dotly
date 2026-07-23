-- Migration forward: adiciona as constraints UNIQUE que tornam os seeds
-- idempotentes em bancos JÁ EXISTENTES (onde 003/004 já rodaram como 'success'
-- e portanto não voltam a rodar). Em instalações novas, 003/004 já criam as
-- constraints e esta migration simplesmente não encontra nada a adicionar.
--
-- IMPORTANTE (pré-condição): ADD UNIQUE falha se a tabela já tiver duplicatas.
-- Se profiles tiver slugs repetidos ou users_profiles tiver pares (users_id,
-- profiles_id) repetidos, é preciso DEDUPLICAR antes de rodar esta migration.
-- Ver query de verificação no plano (Step 6 / tabela de comandos).
--
-- Idempotência da própria migration: guardamos cada ADD UNIQUE atrás de uma
-- checagem em information_schema, montando o ALTER via SQL dinâmico apenas
-- quando o índice ainda não existe. Assim, mesmo que esta migration seja
-- reexecutada (estado inconsistente), ela vira no-op em vez de falhar com
-- "Duplicate key name". Nenhum ';' aparece dentro de literais — o splitter
-- ingênuo do runner (explode(';')) continua válido.

-- 1) profiles.slug UNIQUE
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND INDEX_NAME = 'slug'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `profiles` ADD UNIQUE `slug` (`slug`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) users_profiles (users_id, profiles_id) UNIQUE
SET @idx_exists2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users_profiles'
      AND INDEX_NAME = 'uq_users_profiles'
);
SET @ddl2 := IF(
    @idx_exists2 = 0,
    'ALTER TABLE `users_profiles` ADD UNIQUE `uq_users_profiles` (`users_id`, `profiles_id`)',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
