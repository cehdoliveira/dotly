<?php

/**
 * Escolhe qual gateway PIX recebe a proxima cobranca, distribuindo o volume
 * mensal entre os PSPs habilitados por sorteio ponderado pela folga (headroom)
 * de cada um em relacao ao seu monthly_limit_cents.
 *
 * `monthly_limit_cents` e meta de equilibrio entre gateways, nunca trava de
 * venda: se todos estourarem o limite no mes, a venda continua — escolhe-se o
 * gateway com menor proporcao mtd/limite e loga um warning para o time
 * acompanhar. 4o gateway = 1 classe implementando PixGateway + 1 INSERT IGNORE
 * numa migration; esta classe nao muda.
 *
 * `max_order_cents` (plano 042) e um teto opcional por gateway, configurado
 * pelo dono em /config: gateway com teto abaixo do valor do pedido sai do
 * sorteio. NULL = sem teto. Mesma filosofia do monthly_limit_cents — nunca
 * trava de venda: se o filtro esvaziar o conjunto, o teto e ignorado e um
 * warning e logado.
 *
 * `avoid_on_spike` (plano 043) e a deteccao de pico de pedidos pagos
 * (smurfing): quando `settings.velocity_paid_orders_per_hour` (threshold,
 * 0 = desligado) e atingido pela contagem de pedidos pagos na ultima hora,
 * os gateways marcados `avoid_on_spike='yes'` saem do sorteio ate a janela
 * esfriar. Mesma filosofia dos demais filtros: nunca trava a venda — se
 * esvaziar o conjunto ou a query falhar, o filtro e ignorado e um warning e
 * logado.
 */
final class GatewayRouter
{
    private const VELOCITY_WINDOW_MINUTES = 60;

    /**
     * @return array{idx:int, slug:string, mode:string}
     */
    public static function pick(?int $orderCents = null): array
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx ", " slug ", " mode ", " monthly_limit_cents ", " max_order_cents ", " avoid_on_spike "]);
        $model->set_filter([" active = 'yes' ", " enabled = 'yes' "]);
        $model->load_data(false);
        $gateways = $model->data;

        if (empty($gateways)) {
            throw new RuntimeException('nenhum gateway habilitado');
        }

        // Teto por valor de pedido (plano 042): gateway com max_order_cents definido
        // nao entra no sorteio para pedidos acima do teto. NULL = sem teto. Se o
        // filtro esvaziar o conjunto, ignora o teto e loga — a mesma filosofia do
        // monthly_limit_cents: meta de roteamento, nunca trava de venda.
        if ($orderCents !== null) {
            $eligible = array_values(array_filter($gateways, static function (array $g) use ($orderCents): bool {
                $max = $g['max_order_cents'];
                return $max === null || $max === '' || $orderCents <= (int)$max;
            }));

            if (!empty($eligible)) {
                $gateways = $eligible;
            } else {
                Logger::getInstance()->warning('GatewayRouter: todos os gateways com teto abaixo do pedido — teto ignorado', [
                    'order_cents' => $orderCents,
                ]);
            }
        }

        // Deteccao de pico (plano 043): N pedidos pagos na ultima hora acima do
        // threshold configurado => janela quente de smurfing; gateways marcados
        // avoid_on_spike saem do sorteio ate a janela esfriar. Threshold 0 (default)
        // = detecao desligada. Mesma filosofia dos demais filtros: se esvaziar o
        // conjunto, ignora e loga — nunca trava a venda.
        $spikeSensitive = array_filter($gateways, static fn (array $g): bool => ($g['avoid_on_spike'] ?? 'no') === 'yes');
        if (!empty($spikeSensitive)) {
            $threshold = self::velocityThreshold();
            if ($threshold > 0 && self::paidOrdersLastHour() >= $threshold) {
                $calm = array_values(array_filter($gateways, static fn (array $g): bool => ($g['avoid_on_spike'] ?? 'no') !== 'yes'));
                if (!empty($calm)) {
                    Logger::getInstance()->warning('GatewayRouter: pico de pedidos pagos na ultima hora — desviando de gateways avoid_on_spike', [
                        'threshold' => $threshold,
                    ]);
                    $gateways = $calm;
                } else {
                    Logger::getInstance()->warning('GatewayRouter: pico detectado mas todos os gateways sao avoid_on_spike — desvio ignorado', [
                        'threshold' => $threshold,
                    ]);
                }
            }
        }

        $monthStart = date('Y-m-01 00:00:00');

        $chargesModel = new pix_charges_model();
        $stmt = $chargesModel->select(
            [" c.payment_gateways_id AS g ", " COALESCE(SUM(o.total_cents), 0) AS mtd "],
            "WHERE c.active = 'yes' AND o.status = 'pago' AND o.paid_at >= ? GROUP BY c.payment_gateways_id",
            [$monthStart],
            "c",
            "JOIN orders o ON o.idx = c.orders_id"
        );

        $mtdByGateway = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $mtdByGateway[(int)$row['g']] = (int)$row['mtd'];
        }

        $headrooms = [];
        $totalHeadroom = 0;
        foreach ($gateways as $gateway) {
            $idx = (int)$gateway['idx'];
            $limit = (int)$gateway['monthly_limit_cents'];
            $mtd = $mtdByGateway[$idx] ?? 0;
            $headroom = max(0, $limit - $mtd);
            $headrooms[$idx] = $headroom;
            $totalHeadroom += $headroom;
        }

        if ($totalHeadroom > 0) {
            $draw = random_int(1, $totalHeadroom);
            $cumulative = 0;
            foreach ($gateways as $gateway) {
                $idx = (int)$gateway['idx'];
                $cumulative += $headrooms[$idx];
                if ($draw <= $cumulative) {
                    return [
                        'idx'  => $idx,
                        'slug' => (string)$gateway['slug'],
                        'mode' => (string)$gateway['mode'],
                    ];
                }
            }
        }

        // Todos com headroom = 0 (inclui monthly_limit_cents = 0): escolhe o de
        // menor mtd / max(1, monthly_limit_cents). Nunca bloqueia a venda.
        $chosen = null;
        $chosenRatio = null;
        foreach ($gateways as $gateway) {
            $idx = (int)$gateway['idx'];
            $limit = (int)$gateway['monthly_limit_cents'];
            $mtd = $mtdByGateway[$idx] ?? 0;
            $ratio = $mtd / max(1, $limit);
            if ($chosenRatio === null || $ratio < $chosenRatio) {
                $chosenRatio = $ratio;
                $chosen = $gateway;
            }
        }

        Logger::getInstance()->warning('Todos os gateways estouraram o limite mensal', [
            'escolhido' => $chosen['slug'] ?? null,
        ]);

        return [
            'idx'  => (int)$chosen['idx'],
            'slug' => (string)$chosen['slug'],
            'mode' => (string)$chosen['mode'],
        ];
    }

    /**
     * Le settings.velocity_paid_orders_per_hour (plano 043). Mesmo padrao de
     * validacao do OrderPricing::intSetting(): valor ausente (sem row) ou
     * invalido (nao numerico) cai no default seguro (0 = detecao desligada).
     * Log de erro so quando o valor existe e e invalido — ausencia e estado
     * normal antes do dono ligar a deteccao. Fail-open: qualquer excecao na
     * query (mesmo padrao de paidOrdersLastHour()) tambem cai no default —
     * uma falha transiente aqui nao pode travar o checkout.
     */
    private static function velocityThreshold(): int
    {
        try {
            $model = new settings_model();
            $stmt = $model->select(
                [" svalue "],
                "WHERE active = 'yes' AND skey = ?",
                ['velocity_paid_orders_per_hour']
            );
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('GatewayRouter: falha ao ler threshold de velocity — detecao de pico desligada', [
                'erro' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($row === false) {
            return 0;
        }

        $rawValue = (string)$row['svalue'];
        if (ctype_digit($rawValue)) {
            return (int)$rawValue;
        }

        Logger::getInstance()->error('GatewayRouter: valor invalido em settings, detecao de pico desligada', [
            'skey'   => 'velocity_paid_orders_per_hour',
            'svalue' => $rawValue,
        ]);

        return 0;
    }

    /**
     * Conta pedidos pagos na ultima hora. Janela calculada em PHP em vez de
     * depender do horario do servidor MySQL — o fuso da conexao ja e alinhado ao
     * do PHP em localPDO (plans/005), mas calcular a janela em PHP continua mais
     * direto/estavel. Fail-open: qualquer excecao na query retorna 0 e loga —
     * deteccao indisponivel nao pode derrubar o checkout.
     */
    private static function paidOrdersLastHour(): int
    {
        try {
            $windowStart = date('Y-m-d H:i:s', strtotime('-' . self::VELOCITY_WINDOW_MINUTES . ' minutes'));

            $model = new orders_model();
            $stmt = $model->select(
                [" COUNT(*) AS c "],
                "WHERE active = 'yes' AND status = 'pago' AND paid_at >= ?",
                [$windowStart]
            );
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row !== false ? (int)$row['c'] : 0;
        } catch (\Throwable $e) {
            Logger::getInstance()->error('GatewayRouter: falha ao contar pedidos pagos na ultima hora — detecao de pico desligada', [
                'erro' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
