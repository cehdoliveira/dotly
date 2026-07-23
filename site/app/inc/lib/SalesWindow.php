<?php

/**
 * Janela de vendas do site publico. A loja opera por periodos de venda:
 * compras so acontecem dentro da janela configurada em /config (manager) e
 * enquanto houver estoque. Fora disso o site mostra "vendas encerradas" —
 * que e um estado normal do negocio, e nao um site fora do ar.
 *
 * Precedencia: override manual ('open'/'closed') > janela de datas > estoque.
 * Toda comparacao de data em PHP (date()) — nunca NOW() do MySQL (clock skew
 * documentado no projeto).
 *
 * Fail-open: erro de banco ou valor corrompido => vendas ABERTAS (mesma
 * filosofia de degradacao do Redis/Kafka; o checkout ainda valida estoque
 * por item, entao pedido invalido nao nasce).
 */
final class SalesWindow
{
    /**
     * Rotas de "pos-venda vivo" que o gate do site (index.php) nunca bloqueia,
     * mesmo com vendas fechadas: pagamento/pedido/acompanhamento de PIX
     * pendente e o webhook do PSP. Extraido para metodo testavel (plano 037,
     * revisao pre-landing) — antes era um regex inline no front controller.
     */
    public static function isPostSaleRoute(string $path): bool
    {
        return (bool) preg_match("#^/(webhook/pix/|pagamento/|pedido/|acompanhar-pedido(/|$))#", $path);
    }

    /** @return array{open: bool, reopens_at: ?string, reason: ?string} */
    public static function status(): array
    {
        $s = [
            'sales_override'        => '',
            'sales_window_start_at' => '',
            'sales_window_end_at'   => '',
        ];

        try {
            $model = new settings_model();
            $stmt = $model->select(
                [" skey ", " svalue "],
                "WHERE active = 'yes' AND skey IN (?, ?, ?)",
                array_keys($s)
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $s[$row['skey']] = (string) $row['svalue'];
            }
        } catch (\RuntimeException $e) {
            return ['open' => true, 'reopens_at' => null, 'reason' => null];
        }

        $override = in_array($s['sales_override'], ['open', 'closed'], true)
            ? $s['sales_override']
            : '';

        if ($override === 'open') {
            return ['open' => true, 'reopens_at' => null, 'reason' => null];
        }
        if ($override === 'closed') {
            return ['open' => false, 'reopens_at' => null, 'reason' => 'override'];
        }

        $now   = date('Y-m-d H:i:s');
        $start = self::parseDatetime($s['sales_window_start_at']);
        $end   = self::parseDatetime($s['sales_window_end_at']);

        if ($start !== null && $now < $start) {
            return ['open' => false, 'reopens_at' => $start, 'reason' => 'window'];
        }
        if ($end !== null && $now > $end) {
            return ['open' => false, 'reopens_at' => null, 'reason' => 'window'];
        }

        try {
            if (!self::hasSellableStock()) {
                return ['open' => false, 'reopens_at' => null, 'reason' => 'stock'];
            }
        } catch (\RuntimeException $e) {
            // fail-open: na duvida, vende (checkout valida estoque por item)
        }

        return ['open' => true, 'reopens_at' => null, 'reason' => null];
    }

    private static function hasSellableStock(): bool
    {
        $model = new products_model();
        $stmt = $model->select(
            [" idx "],
            "WHERE active = 'yes' AND stock > 0 LIMIT 1"
        );

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /** 'Y-m-d H:i:s' valido => normalizado; qualquer outra coisa => null. */
    private static function parseDatetime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        return $value;
    }
}
