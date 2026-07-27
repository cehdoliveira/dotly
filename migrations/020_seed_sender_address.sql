-- Plano 025: endereco de remetente da loja, cadastrado em /config e impresso
-- como 2a etiqueta em /pedidos/{idx}/etiqueta. Valores vazios = remetente nao
-- cadastrado (a etiqueta de remetente simplesmente nao e impressa).
-- CEP em `sender_zip` e guardado SO COM DIGITOS; a formatacao 12345-678 e
-- responsabilidade da view da etiqueta.
INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'sender_name',       ''),
    (NOW(), 0, 'yes', 'sender_zip',        ''),
    (NOW(), 0, 'yes', 'sender_street',     ''),
    (NOW(), 0, 'yes', 'sender_number',     ''),
    (NOW(), 0, 'yes', 'sender_complement', ''),
    (NOW(), 0, 'yes', 'sender_district',   ''),
    (NOW(), 0, 'yes', 'sender_city',       ''),
    (NOW(), 0, 'yes', 'sender_uf',         '');
