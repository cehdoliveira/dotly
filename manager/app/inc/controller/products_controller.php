<?php
class products_controller
{
    /**
     * Abaixo (ou igual a) este nivel de estoque o produto conta como "acabando"
     * e a linha ganha o tratamento visual de estoque baixo; em 0 vira "esgotado".
     * Constante unica de verdade — compartilhada com a view (passada por variavel).
     */
    private const LOW_STOCK_THRESHOLD = 10;

    /**
     * Whitelist de ordenacao clicavel: chave da querystring -> expressao SQL crua
     * do ORDER BY. So chaves desta lista viram ORDER BY (o DOLModel injeta
     * set_order() cru), entao uma chave forjada NUNCA e injetada — cai no default.
     */
    private const SORTABLE = [
        'nome'      => ' name ',
        'categoria' => ' category ',
        'preco'     => ' price_unit_cents ',
        'estoque'   => ' stock ',
    ];

    /**
     * Plano 003: CRUD de `products`.
     */
    public function index(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        $qRaw     = $info['get']['q'] ?? '';
        $currentQ = is_string($qRaw) ? trim($qRaw) : '';

        $catRaw          = $info['get']['categoria'] ?? '';
        $currentCategory = is_string($catRaw) ? trim($catRaw) : '';

        $stockRaw     = $info['get']['estoque'] ?? '';
        $currentStock = is_string($stockRaw) ? trim($stockRaw) : '';

        [$currentSort, $currentDir, $orderExpr] = $this->resolveSort($info);
        [$conds, $params]                       = $this->buildFilter($info);

        // Opcoes do dropdown de categoria: as categorias distintas em uso. Falha
        // aqui so esvazia o dropdown, nunca a listagem de produtos.
        $categories = [];
        try {
            $catStmt = (new products_model())->select(
                [" DISTINCT category "],
                "WHERE active = 'yes' AND category <> '' ORDER BY category ASC"
            );
            foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $categories[] = $row['category'];
            }
        } catch (RuntimeException $e) {
            $categories = [];
        }

        try {
            $model = new products_model();

            $countStmt      = $model->select(
                [" COUNT(*) AS total "],
                "WHERE " . implode(" AND ", $conds),
                $params
            );
            $total_products = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $model->set_field([" idx ", " name ", " slug ", " category ", " dosage ", " price_unit_cents ", " stock "]);
            $model->set_filter($conds, $params);
            $model->set_order([$orderExpr]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $model->join('images', 'product_images', ['products_id' => 'idx'], null, [' idx ', ' products_id ', ' path ', ' is_cover ', ' sort_order ']);
            $products = $model->data;

            foreach ($products as &$product) {
                $product['cover_path'] = $this->coverPath($product['images_attach'] ?? []);
            }
            unset($product);
        } catch (RuntimeException $e) {
            $products       = [];
            $total_products = 0;
        }

        $totalPages       = (int)ceil($total_products / $perPage);
        $lowStockThreshold = self::LOW_STOCK_THRESHOLD;

        $alpineControllers = ['products'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/products.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function action(array $info): void
    {
        global $products_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $products_url);

        if ($action === 'criar') {
            [$valid, $data] = $this->validate($post);
            if (!$valid) {
                basic_redir($products_url);
            }

            $rollback = false;

            try {
                $product = new products_model();
                $product->populate($data);
                $productId = (int)$product->save();

                $this->savePhotos($productId);

                $_SESSION["messages_app"]["success"] = ["Produto criado com sucesso."];
            } catch (RuntimeException $e) {
                $rollback = true;
                Logger::getInstance()->error("products_action(criar) failed", [
                    "error" => $e->getMessage(),
                ]);
                $_SESSION["messages_app"]["danger"] = ["Falha ao criar produto. Verifique se o slug já está em uso."];
            }

            basic_redir($products_url, rollback: $rollback);
        }

        if ($idx <= 0) {
            basic_redir($products_url);
        }

        if ($action === 'editar') {
            [$valid, $data] = $this->validate($post);
            if (!$valid) {
                basic_redir($products_url);
            }
        }

        $rollback = false;

        try {
            if ($action === 'editar') {
                $update = new products_model();
                $update->set_filter(["idx = ?"], [$idx]);
                $update->populate($data);
                $update->save();

                $this->savePhotos($idx);

                $_SESSION["messages_app"]["success"] = ["Produto atualizado com sucesso."];
            } elseif ($action === 'remover') {
                $remove = new products_model();
                $remove->set_filter(["idx = ?"], [$idx]);
                $remove->remove();

                $_SESSION["messages_app"]["success"] = ["Produto removido com sucesso."];
            }
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("products_action failed", [
                "error"  => $e->getMessage(),
                "action" => $action,
                "idx"    => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o produto. Verifique se o slug já está em uso."];
        }

        basic_redir($products_url, rollback: $rollback);
    }

    /**
     * Monta o filtro da listagem: dois eixos independentes, unidos por AND —
     * busca por nome (LIKE) e categoria exata (dropdown). Os curingas LIKE
     * (`%` e `_`) digitados no nome sao escapados para o termo ser tratado como
     * texto literal; ambos os valores sempre vao bindados.
     *
     * @return array{0: string[], 1: array<int,mixed>} [conditions, params]
     */
    private function buildFilter(array $info): array
    {
        $conds  = [" active = 'yes' "];
        $params = [];

        $qRaw = $info['get']['q'] ?? '';
        $q    = is_string($qRaw) ? trim($qRaw) : '';
        if ($q !== '') {
            $conds[]  = " name LIKE ? ";
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }

        $catRaw   = $info['get']['categoria'] ?? '';
        $category = is_string($catRaw) ? trim($catRaw) : '';
        if ($category !== '') {
            $conds[]  = " category = ? ";
            $params[] = $category;
        }

        // Estado de estoque: mesmos limiares do tratamento visual da linha (ver
        // LOW_STOCK_THRESHOLD). So os estados conhecidos viram condicao; qualquer
        // valor forjado e ignorado. O limiar vai bindado, nunca concatenado.
        $stockRaw   = $info['get']['estoque'] ?? '';
        $stockState = is_string($stockRaw) ? trim($stockRaw) : '';
        if ($stockState === 'esgotado') {
            $conds[] = " stock <= 0 ";
        } elseif ($stockState === 'baixo') {
            $conds[]  = " (stock > 0 AND stock <= ?) ";
            $params[] = self::LOW_STOCK_THRESHOLD;
        } elseif ($stockState === 'critico') {
            $conds[]  = " stock <= ? ";
            $params[] = self::LOW_STOCK_THRESHOLD;
        }

        return [$conds, $params];
    }

    /**
     * Resolve a ordenacao clicavel do cabecalho: mapeia `sort`/`dir` para a tripla
     * [chave validada, direcao, ORDER BY]. Chave forjada (fora de SORTABLE) cai no
     * default — a ordem curada do catalogo (sort_order). `idx` no fim e desempate
     * estavel para a paginacao nao embaralhar linhas de mesmo valor entre paginas.
     *
     * @return array{0:string,1:string,2:string} [chave, direcao(asc|desc), ORDER BY]
     */
    private function resolveSort(array $info): array
    {
        $rawKey = $info['get']['sort'] ?? '';
        $key    = is_string($rawKey) ? $rawKey : '';
        $dir    = (($info['get']['dir'] ?? '') === 'desc') ? 'DESC' : 'ASC';

        if (!isset(self::SORTABLE[$key])) {
            return ['', 'asc', ' sort_order ASC, name ASC, idx ASC '];
        }

        $expr = self::SORTABLE[$key] . ' ' . $dir . ', idx ' . $dir;

        return [$key, strtolower($dir), $expr];
    }

    /**
     * Escolhe a foto de capa entre as imagens anexadas via join(): a marcada is_cover='yes',
     * ou a de menor sort_order se nenhuma estiver marcada.
     */
    private function coverPath(array $images): ?string
    {
        if (empty($images)) {
            return null;
        }
        foreach ($images as $img) {
            if (($img['is_cover'] ?? 'no') === 'yes') {
                return $img['path'];
            }
        }
        usort($images, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        return $images[0]['path'] ?? null;
    }

    /**
     * Valida os campos do formulario de produto. Em falha, seta a mensagem de erro
     * em sessao e devolve [false, []] — quem chama decide como redirecionar.
     *
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function validate(array $post): array
    {
        $name = trim($post['name'] ?? '');
        $slug = trim($post['slug'] ?? '');

        if ($name === '') {
            $_SESSION["messages_app"]["danger"] = ["Nome é obrigatório."];
            return [false, []];
        }

        if ($slug === '') {
            $slug = generate_slug($name);
        }
        if (!valid_slug($slug)) {
            $_SESSION["messages_app"]["danger"] = ["Slug inválido: use minúsculas, números, '-' ou '_' (ex.: meu-produto)."];
            return [false, []];
        }

        $categoryName = trim(preg_replace('/\s+/', ' ', (string)($post['category'] ?? '')));
        if ($categoryName === '') {
            $_SESSION["messages_app"]["danger"] = ["Informe a categoria."];
            return [false, []];
        }
        if (mb_strlen($categoryName) > 60) {
            $_SESSION["messages_app"]["danger"] = ["Categoria deve ter no máximo 60 caracteres."];
            return [false, []];
        }

        // Preco digitado em REAIS (ex.: "70" -> R$70,00; "70,50" -> R$70,50;
        // "R$ 1.234,56" -> R$1.234,56). Remove tudo que nao for digito/virgula/ponto
        // (descarta "R$", espacos), tira o separador de milhar e usa a virgula como
        // decimal, convertendo para centavos.
        $priceClean = preg_replace('/[^\d,.]/', '', (string)($post['price_unit_cents'] ?? ''));
        $priceReais = (float)str_replace(',', '.', str_replace('.', '', $priceClean));
        $priceUnitCents = (int)round($priceReais * 100);
        if ($priceUnitCents <= 0) {
            $_SESSION["messages_app"]["danger"] = ["Preço unitário deve ser maior que zero."];
            return [false, []];
        }

        $stockRaw = trim((string)($post['stock'] ?? ''));
        $stock = $stockRaw === '' ? 0 : (int)$stockRaw;
        if ($stock < 0) {
            $_SESSION["messages_app"]["danger"] = ["Estoque não pode ser negativo."];
            return [false, []];
        }

        // Dosagem: campo aberto (ex.: "60"). O site acrescenta "mg" quando o valor
        // for numerico; texto livre (ex.: "5mg/ml") e renderizado como esta.
        $dosage = trim((string)($post['dosage'] ?? ''));
        if (mb_strlen($dosage) > 40) {
            $_SESSION["messages_app"]["danger"] = ["Dosagem deve ter no máximo 40 caracteres."];
            return [false, []];
        }

        return [true, [
            'name'             => $name,
            'slug'             => $slug,
            'category'         => $categoryName,
            'dosage'           => $dosage,
            'price_unit_cents' => $priceUnitCents,
            'stock'            => $stock,
        ]];
    }

    /**
     * Envia as fotos do $_FILES['photos'] (arrays paralelos, remontados item a item)
     * e grava em `product_images`. Apenas a primeira foto do produto nasce is_cover='yes'.
     */
    private function savePhotos(int $productId): void
    {
        if (empty($_FILES['photos']['name'][0] ?? null)) {
            return;
        }

        $existing = new product_images_model();
        $existing->set_field([' idx ', ' is_cover ']);
        $existing->set_filter([" active = 'yes' ", " products_id = ? "], [$productId]);
        $existing->load_data(false);

        $hasCover = false;
        foreach ($existing->data as $row) {
            if (($row['is_cover'] ?? 'no') === 'yes') {
                $hasCover = true;
                break;
            }
        }
        $sortOrder = count($existing->data);

        foreach ($_FILES['photos']['name'] as $i => $name) {
            if ($name === '' || ($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = [
                'name'     => $_FILES['photos']['name'][$i],
                'type'     => $_FILES['photos']['type'][$i],
                'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                'error'    => $_FILES['photos']['error'][$i],
                'size'     => $_FILES['photos']['size'][$i],
            ];

            $path = handle_upload($file, 'products', ['convert' => 'webp', 'max_width' => 1200, 'quality' => 80]);
            if ($path === false) {
                continue;   // handle_upload ja logou o motivo
            }

            // handle_upload devolve caminho raiz do site ("/assets/upload/..."). O banco
            // guarda relativo a `assets/` para a view prefixar com cAssets.
            $relPath = preg_replace('#^/assets/#', '', $path);

            $image = new product_images_model();
            $image->populate([
                'products_id' => $productId,
                'path'        => $relPath,
                'is_cover'    => $hasCover ? 'no' : 'yes',
                'sort_order'  => $sortOrder,
            ]);
            $image->save();

            $hasCover = true;
            $sortOrder++;
        }
    }
}
