-- categoryNameTaken() (products_controller.php) busca categoria ativa por nome
-- com `WHERE active = 'yes' AND name = ? AND idx <> ?` a cada criacao/renomeacao
-- de categoria no modal do /produtos. O UNIQUE funcional uniq_categories_active_name
-- (migration 015) e sobre a expressao `IF(active = 'yes', name, NULL)` — o
-- otimizador so usa indice funcional quando a WHERE repete a MESMA expressao
-- literal, entao essa busca (forma solta, coluna a coluna) nao usa nenhum
-- indice e faz table scan completo. Achado do review de /phpship (plano 024).
--
-- Indice plano adicional, sem mexer no UNIQUE existente: cobre exatamente o
-- predicado desta query (igualdade em active + name).

CREATE INDEX `idx_categories_active_name` ON `categories` (`active`, `name`);
