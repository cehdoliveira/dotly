<?php

/**
 * Carrinho em sessao, armazenado em $_SESSION[cAppKey]["cart"].
 *
 * Regra de seguranca inegociavel: a sessao NUNCA guarda preco. Apenas
 * products_id, variant e qty. Preco e nome sao relidos de `products` a
 * cada render (hydrate()) e de novo no checkout — nunca confiar em preco
 * vindo da sessao ou do POST.
 *
 * Chave da linha: "<products_id>:<variant>" — a mesma dosagem em unidade
 * e em caixa sao linhas distintas do carrinho.
 */
class Cart
{
    private const VARIANTS = ['unit', 'box'];
    private const MIN_QTY = 1;
    private const MAX_QTY = 99;

    private static function key(int $productId, string $variant): string
    {
        return $productId . ':' . $variant;
    }

    private static function clampQty(int $qty): int
    {
        return max(self::MIN_QTY, min(self::MAX_QTY, $qty));
    }

    /** Linhas cruas da sessao. */
    public static function all(): array
    {
        $cart = $_SESSION[constant("cAppKey")]["cart"] ?? [];
        return is_array($cart) ? $cart : [];
    }

    public static function add(int $productId, string $variant, int $qty): void
    {
        if ($productId <= 0 || !in_array($variant, self::VARIANTS, true)) {
            return;
        }

        $qty = self::clampQty($qty);
        $key = self::key($productId, $variant);
        $cart = self::all();

        $existingQty = (int)($cart[$key]["qty"] ?? 0);

        $cart[$key] = [
            "products_id" => $productId,
            "variant"     => $variant,
            "qty"         => self::clampQty($existingQty + $qty),
        ];

        $_SESSION[constant("cAppKey")]["cart"] = $cart;
    }

    /** qty <= 0 remove a linha. */
    public static function setQty(int $productId, string $variant, int $qty): void
    {
        if ($productId <= 0 || !in_array($variant, self::VARIANTS, true)) {
            return;
        }

        if ($qty <= 0) {
            self::remove($productId, $variant);
            return;
        }

        $key = self::key($productId, $variant);
        $cart = self::all();

        $cart[$key] = [
            "products_id" => $productId,
            "variant"     => $variant,
            "qty"         => self::clampQty($qty),
        ];

        $_SESSION[constant("cAppKey")]["cart"] = $cart;
    }

    public static function remove(int $productId, string $variant): void
    {
        $cart = self::all();
        unset($cart[self::key($productId, $variant)]);
        $_SESSION[constant("cAppKey")]["cart"] = $cart;
    }

    public static function clear(): void
    {
        $_SESSION[constant("cAppKey")]["cart"] = [];
    }

    /** Soma das qty — usado no badge "Pedido N". */
    public static function count(): int
    {
        $total = 0;
        foreach (self::all() as $row) {
            $total += (int)($row["qty"] ?? 0);
        }
        return $total;
    }

    /**
     * Rele products no banco; devolve [linhas com preco/nome, total_cents].
     *
     * Produto sumido do banco (inativo/removido) e silenciosamente descartado
     * da sessao. A variante `box` sempre vale preco_unitario * box_qty.
     *
     * @return array{0: array<int, array{products_id:int, variant:string, qty:int, name:string, category:string, box_qty:int, unit_price_cents:int, line_total_cents:int}>, 1: int}
     */
    public static function hydrate(): array
    {
        $cart = self::all();

        if (empty($cart)) {
            return [[], 0];
        }

        $productIds = array_values(array_unique(array_map(
            static fn(array $row) => (int)$row["products_id"],
            $cart
        )));

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $model = new products_model();
        $model->set_field([" idx ", " name ", " category ", " price_unit_cents ", " box_qty "]);
        $model->set_filter([" active = 'yes' ", " idx IN ($placeholders) "], $productIds);
        $model->load_data(false);

        $productsById = [];
        foreach ($model->data as $product) {
            $productsById[(int)$product["idx"]] = $product;
        }

        $lines = [];
        $totalCents = 0;
        $dirty = false;

        foreach ($cart as $key => $row) {
            $productId = (int)$row["products_id"];
            $variant   = $row["variant"];
            $qty       = (int)$row["qty"];

            $product = $productsById[$productId] ?? null;
            if ($product === null) {
                unset($cart[$key]);
                $dirty = true;
                continue;
            }

            if ($variant === 'box') {
                $unitPriceCents = (int)$product["price_unit_cents"] * (int)$product["box_qty"];
            } else {
                $unitPriceCents = (int)$product["price_unit_cents"];
            }

            $lineTotalCents = $unitPriceCents * $qty;
            $totalCents += $lineTotalCents;

            $lines[] = [
                "products_id"      => $productId,
                "variant"          => $variant,
                "qty"              => $qty,
                "name"             => $product["name"],
                // category + box_qty vao pro cliente pro lembrete de BAC Water:
                // o drawer soma os frascos de peptideo (nao-Diluente) e compara
                // com os de agua bacteriostatica (categoria "Diluente"). variant
                // 'box' vale box_qty frascos. Ver bacShortfall() no shopController.
                "category"         => (string)$product["category"],
                "box_qty"          => (int)$product["box_qty"],
                "unit_price_cents" => $unitPriceCents,
                "line_total_cents" => $lineTotalCents,
            ];
        }

        if ($dirty) {
            $_SESSION[constant("cAppKey")]["cart"] = $cart;
        }

        return [$lines, $totalCents];
    }
}
