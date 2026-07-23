-- Plano 017: "Acompanhar meu pedido" consulta orders.customer_mail em toda
-- requisicao de uma pagina PUBLICA (sem login). Achado da revisao adversarial
-- (/ship): customer_mail nao tinha indice nenhum, entao a busca (WHERE
-- active='yes' AND customer_mail=? AND RIGHT(customer_phone,4)=?) fazia table
-- scan completo em orders a cada tentativa — amplificacao de custo de banco
-- num endpoint publico so protegido por rate-limit fail-open por IP.
--
-- Idempotencia da propria migration: checagem em information_schema (mesmo
-- padrao de 028_add_tracking_to_orders.sql).

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_customer_mail'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `orders` ADD KEY `idx_orders_customer_mail` (`customer_mail`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
