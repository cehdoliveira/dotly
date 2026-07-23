<?php
$home_url     = sprintf("%s%s", constant("cFrontend"), "");
$login_url    = sprintf("%s%s", constant("cFrontend"), "login");
$logout_url   = sprintf("%s%s", constant("cFrontend"), "sair");
$config_url   = sprintf("%s%s", constant("cFrontend"), "config");
$config_users_url = sprintf("%s%s", constant("cFrontend"), "config/usuarios");
$password_url = sprintf("%s%s", constant("cFrontend"), "senha");
$tkpwd_url    = sprintf("%s%s/%s", constant("cFrontend"), "tkpwd", "%s");
$customers_url     = sprintf("%s%s", constant("cFrontend"), "clientes");
$customer_url      = sprintf("%s%s/%s", constant("cFrontend"), "clientes", "%d");
$verify_email_url  = sprintf("%s%s/%s", constant("cFrontend"), "verificar-email", "%s");
$set_password_url  = sprintf("%s%s/%s", constant("cFrontend"), "definir-senha", "%s");
$products_url = sprintf("%s%s", constant("cFrontend"), "produtos");
$orders_url   = sprintf("%s%s", constant("cFrontend"), "pedidos");
$order_url    = sprintf("%s%s/%s", constant("cFrontend"), "pedidos", "%d");
$order_ship_url = sprintf("%s%s/%s/%s", constant("cFrontend"), "pedidos", "%d", "enviar");
$order_label_url = sprintf("%s%s/%s/%s", constant("cFrontend"), "pedidos", "%d", "etiqueta");
$orders_export_url = sprintf("%s%s", constant("cFrontend"), "pedidos/exportar");
