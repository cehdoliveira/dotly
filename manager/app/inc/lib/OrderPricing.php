<?php

/**
 * Calculo centralizado das taxas obrigatorias do pedido: 10% sobre o subtotal,
 * taxa fixa de R$60 (custo de cambio/transferencia BRL->USD) e uma taxa
 * Infinity parametrizavel (so incide se o carrinho tiver produto(s) Infinity
 * e o parametro estiver > 0). Os tres parametros vem da tabela `settings`
 * (nao hardcode) para poderem ser ajustados sem deploy.
 *
 * Arredondamento: intdiv (trunca centavos). Nao anda para cima.
 *
 * Nao recalcula/reconfere preco de linha — isso e responsabilidade de
 * checkout_controller::lockAndValidateCart(). Esta classe so aplica as taxas
 * sobre o subtotal ja reconferido.
 */
class OrderPricing
{
    /**
     * @param array<int, array{products_id:int, line_total_cents:int, ...}> $lines
     * @param int $subtotalCents  soma reconferida das linhas
     * @return array{subtotal_cents:int, fee_percent_bps:int, fee_percent_cents:int,
     *   fee_fixed_cents:int, fee_infinity_cents:int, total_cents:int}
     */
    public static function compute(array $lines, int $subtotalCents): array
    {
        $defaults = [
            'fee_percent_bps'  => '1000',
            'fee_fixed_cents'  => '6000',
            'fee_infinity_bps' => '0',
        ];
        $settings = self::settings($defaults);
        $percentBps  = self::intSetting('fee_percent_bps', $settings['fee_percent_bps'], $defaults['fee_percent_bps']);
        $fixedCents  = self::intSetting('fee_fixed_cents', $settings['fee_fixed_cents'], $defaults['fee_fixed_cents']);
        $infinityBps = self::intSetting('fee_infinity_bps', $settings['fee_infinity_bps'], $defaults['fee_infinity_bps']);

        // intdiv para arredondamento consistente (centavos inteiros, trunca).
        $feePercent = intdiv($subtotalCents * $percentBps, 10000);

        $feeInfinity = 0;
        if ($infinityBps > 0 && self::cartHasInfinity($lines)) {
            $feeInfinity = intdiv($subtotalCents * $infinityBps, 10000);
        }

        $total = $subtotalCents + $feePercent + $fixedCents + $feeInfinity;

        return [
            'subtotal_cents'     => $subtotalCents,
            'fee_percent_bps'    => $percentBps,
            'fee_percent_cents'  => $feePercent,
            'fee_fixed_cents'    => $fixedCents,
            'fee_infinity_cents' => $feeInfinity,
            'total_cents'        => $total,
        ];
    }

    /**
     * @param array<string,string> $defaults skey => valor padrao
     * @return array<string,string> skey => svalue (padrao se ausente/inativo)
     */
    private static function settings(array $defaults): array
    {
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $model = new settings_model();
        $stmt = $model->select(
            [" skey ", " svalue "],
            "WHERE active = 'yes' AND skey IN ($placeholders)",
            $keys
        );

        $found = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $found[$row['skey']] = $row['svalue'];
        }

        return array_merge($defaults, $found);
    }

    /**
     * Valida que uma taxa lida de `settings` e um inteiro nao-negativo antes de
     * usar — um valor em branco/nao-numerico viraria silenciosamente 0 (taxa
     * zerada) via cast direto de (int), e ninguem seria avisado. Sem CRUD no
     * manager pra essa tabela ainda, o unico jeito de corromper isso e edicao
     * direta no banco — mas e um multiplicador critico o bastante pra nao
     * falhar em silencio.
     */
    private static function intSetting(string $key, string $rawValue, string $defaultValue): int
    {
        if (ctype_digit($rawValue)) {
            return (int) $rawValue;
        }

        Logger::getInstance()->error('OrderPricing: valor invalido em settings, usando padrao', [
            'skey'    => $key,
            'svalue'  => $rawValue,
            'default' => $defaultValue,
        ]);

        return (int) $defaultValue;
    }

    /** @param array<int, array<string,mixed>> $lines */
    private static function cartHasInfinity(array $lines): bool
    {
        $productIds = array_values(array_unique(array_map(
            static fn(array $line) => (int) $line['products_id'],
            $lines
        )));

        if (empty($productIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $model = new products_model();
        $stmt = $model->select(
            [" idx "],
            "WHERE active = 'yes' AND is_infinity = 'yes' AND idx IN ($placeholders)",
            $productIds
        );

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }
}
