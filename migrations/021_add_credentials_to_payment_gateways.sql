-- Credenciais do PSP (tokens, secrets, base de API) cifradas em repouso. Ate o
-- plano 026 elas moravam em constantes do site/app/inc/kernel.php
-- (MP_ACCESS_TOKEN, MP_WEBHOOK_SECRET, PAGBANK_API_BASE, PAGBANK_TOKEN,
-- INFINITEPAY_HANDLE), o que exigia SSH para trocar uma chave e deixava o
-- manager sem saber se o gateway tinha credencial.
--
-- Formato: base64(iv[12] || tag[16] || ciphertext) de um JSON {campo: valor},
-- AES-256-GCM, chave em APP_ENCRYPTION_KEY (kernel.php dos DOIS ambientes).
-- Ver app/inc/lib/Crypto.php e app/inc/lib/GatewayCredentials.php.
--
-- TEXT (nao VARCHAR): o token do Mercado Pago sozinho ja passa de 70 chars e o
-- blob cifrado de todos os campos + overhead do base64 nao tem teto util.
--
-- NULL = gateway sem credencial cadastrada. GatewayRouter::pick() tira esse
-- gateway do sorteio mesmo com enabled='yes'.

ALTER TABLE `payment_gateways`
    ADD COLUMN `credentials_enc` TEXT DEFAULT NULL AFTER `mode`;
