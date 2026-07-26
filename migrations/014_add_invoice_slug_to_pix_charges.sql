-- Codigo da fatura na InfinitePay ("slug" na doc/API deles), capturado do retorno
-- do comprador em checkout_controller::done(). NAO confundir com
-- payment_gateways.slug, que identifica o gateway ('infinitepay'/'mercadopago'/
-- 'pagbank') — dai o nome gateway_invoice_slug.
--
-- Necessario junto com transaction_nsu para chamar POST /payment_check da
-- InfinitePay (InfinitePayGateway::buildPaymentCheckBody exige order_nsu +
-- transaction_nsu + slug). Sem ele, uma cobranca InfinitePay cujo webhook nunca
-- chegou nao tem como ser reconfirmada. NULL para todo gateway que nao seja
-- InfinitePay, e para cobrancas anteriores a esta migration.
--
-- Sem UNIQUE de proposito: a garantia anti-replay e a UNIQUE ja existente em
-- transaction_nsu (migrations/010), o slug da fatura nao carrega essa semantica.

ALTER TABLE `pix_charges`
    ADD COLUMN `gateway_invoice_slug` VARCHAR(120) DEFAULT NULL AFTER `transaction_nsu`;
