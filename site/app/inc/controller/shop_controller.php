<?php
class shop_controller
{
    public function product(array $info): void
    {
        global $home_url;

        $slug = $info[1] ?? null;
        $wantsJson = ($info['get']['format'] ?? '') === 'json';

        if (!valid_slug($slug)) {
            if ($wantsJson) {
                json_response(['error' => 'produto nao encontrado'], 404);
            }
            basic_redir($home_url);
        }

        $productsModel = new products_model();
        $productsModel->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $productsModel->set_paginate([1]);
        $productsModel->load_data(false);
        $productsModel->join("images", "product_images", ["products_id" => "idx"], null, [" idx ", " products_id ", " path ", " is_cover ", " sort_order "]);

        $product = $productsModel->data[0] ?? null;

        if (!$product) {
            if ($wantsJson) {
                json_response(['error' => 'produto nao encontrado'], 404);
            }
            basic_redir($home_url);
        }

        // Payload do modal de produto. Só leitura — sem commit (ver plano 006, 1.1).
        if ($wantsJson) {
            json_response(['product' => $product]);
        }

        $alpineControllers = ['home', 'shop'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/product.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
