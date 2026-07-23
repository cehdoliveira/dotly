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
            $filters[] = " category = ? ";
            $filterParams[] = $cat;
        }

        $productsModel = new products_model();
        $productsModel->set_filter($filters, $filterParams);
        $productsModel->set_order([" sort_order asc ", " name asc "]);
        $productsModel->load_data(false);
        $productsModel->join("images", "product_images", ["products_id" => "idx"], null, [" idx ", " products_id ", " path ", " is_cover ", " sort_order "]);
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
        }
        unset($product);

        $categoriesModel = new products_model();
        $categoriesStmt = $categoriesModel->select(
            [" DISTINCT category "],
            "WHERE active = 'yes' ORDER BY category ASC"
        );
        $categories = array_column($categoriesStmt->fetchAll(\PDO::FETCH_ASSOC), 'category');

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
