-- Categoria padrao. Todo produto ativo que hoje nao tem nenhuma ligacao ativa
-- em `products_categories` (ficou sem categoria depois do 023, ou sempre
-- esteve sem) ganha uma ligacao real com "Geral" — nao e so um valor
-- calculado na leitura, e uma linha de verdade em products_categories,
-- coerente com a convencao de attach do projeto.
--
-- INSERT IGNORE na categoria: se "Geral" ja existir (ex.: alguem criou manual
-- via /produtos antes desta migration rodar), reaproveita em vez de duplicar
-- — uniq_categories_active_name (migration 015) garante isso.

INSERT IGNORE INTO `categories` (`created_at`, `created_by`, `active`, `name`)
VALUES (NOW(), 0, 'yes', 'Geral');

INSERT IGNORE INTO `products_categories` (`created_at`, `created_by`, `active`, `products_id`, `categories_id`)
SELECT NOW(), 0, 'yes', p.`idx`, c.`idx`
FROM `products` p
JOIN `categories` c ON c.`name` = 'Geral' AND c.`active` = 'yes'
WHERE p.`active` = 'yes'
  AND NOT EXISTS (
      SELECT 1 FROM `products_categories` pc
      WHERE pc.`products_id` = p.`idx` AND pc.`active` = 'yes'
  );
