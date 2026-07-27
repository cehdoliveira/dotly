<?php
class site_controller
{
    public function home(array $info): void
    {
        $q   = trim((string)($info['get']['q'] ?? ''));
        $cat = trim((string)($info['get']['cat'] ?? ''));

        $filters = [" active = 'yes' "];
        $filterParams = [];

        if ($q !== '') {
            $filters[] = " name LIKE ? ";
            $filterParams[] = '%' . $q . '%';
        }

        if ($cat !== '') {
            $filters[]      = " idx IN (SELECT pc.products_id FROM products_categories pc INNER JOIN categories c ON c.idx = pc.categories_id AND c.active = 'yes' WHERE pc.active = 'yes' AND c.name = ?) ";
            $filterParams[] = $cat;
        }

        $productsModel = new products_model();
        $productsModel->set_filter($filters, $filterParams);
        $productsModel->set_order([" sort_order asc ", " name asc "]);
        $productsModel->load_data(false);
        $productsModel->join("images", "product_images", ["products_id" => "idx"], null, [" idx ", " products_id ", " path ", " is_cover ", " sort_order "]);
        $productsModel->attach(["categories"], class_field: [" idx ", " name "]);
        $products = $productsModel->data;

        // Capa de cada produto: a imagem marcada is_cover='yes', ou a primeira
        // disponivel na ausencia de uma capa explicita.
        foreach ($products as &$product) {
            $images = $product['images_attach'] ?? [];
            $cover = null;
            foreach ($images as $image) {
                if (($image['is_cover'] ?? 'no') === 'yes') {
                    $cover = $image;
                    break;
                }
            }
            $product['cover_image'] = $cover ?? ($images[0] ?? null);

            $linkedCategory     = $product['categories_attach'][0] ?? null;
            $product['category'] = $linkedCategory['name'] ?? 'Geral';
        }
        unset($product);

        // Chips da home: so categorias COM produto ativo. Preserva o
        // comportamento do antigo "SELECT DISTINCT products.category" — uma
        // categoria recem-criada no manager, ainda sem produto, nao vira chip
        // vazio na vitrine.
        $categoriesModel = new categories_model();
        $categoriesStmt  = $categoriesModel->select(
            [" name "],
            "WHERE active = 'yes'
               AND EXISTS (SELECT 1 FROM products_categories pc
                           INNER JOIN products p ON p.idx = pc.products_id AND p.active = 'yes'
                           WHERE pc.active = 'yes' AND pc.categories_id = categories.idx)
             ORDER BY name ASC"
        );
        $categories = array_column($categoriesStmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $alpineControllers = ['home', 'shop'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function terms(array $info): void
    {
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/terms.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function privacy(array $info): void
    {
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/privacy.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
