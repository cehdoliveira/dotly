<?php
$home_url     = sprintf("%s%s", constant("cFrontend"), "");
$terms_url          = sprintf("%s%s", constant("cFrontend"), "termos-de-uso");
$privacy_url        = sprintf("%s%s", constant("cFrontend"), "politica-de-privacidade");
$track_order_url         = sprintf("%s%s", constant("cFrontend"), "acompanhar-pedido");

$cart_url     = sprintf("%s%s", constant("cFrontend"), "carrinho");
$checkout_url = sprintf("%s%s", constant("cFrontend"), "checkout");
$payment_url  = sprintf("%s%s/%s", constant("cFrontend"), "pagamento", "%s");
$done_url     = sprintf("%s%s/%s", constant("cFrontend"), "pedido", "%s");
$product_url  = sprintf("%s%s/%s", constant("cFrontend"), "produto", "%s");
