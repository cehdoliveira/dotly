<?php

/**
 * Etiqueta de envio (endereçamento padrão Correios) — página standalone,
 * pronta para impressão. Renderizada por orders_controller::label(), fora do
 * layout do manager (sem sidebar/header). Consome apenas $order.
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtCep = static function (?string $raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5, 3) : ($raw ?: '');
};

$nome        = strtoupper(trim((string)($order['customer_name'] ?? '')));
$logradouro  = trim(($order['ship_street'] ?? '') . ', ' . ($order['ship_number'] ?? ''), ', ');
$complemento = trim((string)($order['ship_complement'] ?? ''));
$bairro      = trim((string)($order['ship_district'] ?? ''));
$cidadeUf    = trim(trim((string)($order['ship_city'] ?? '')) . ' - ' . trim((string)($order['ship_uf'] ?? '')), ' -');
$cep         = $fmtCep($order['ship_zip'] ?? null);
$refPedido   = '#' . (int)($order['idx'] ?? 0);
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="<?php echo $e(constant('cFrontend') . 'assets/img/favicon.svg'); ?>">
    <title>Etiqueta de envio — Pedido <?php echo $e($refPedido); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            background: #f3f4f6;
            font-family: Arial, Helvetica, "Segoe UI", sans-serif;
            color: #000;
        }

        .toolbar {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            padding: 1rem;
        }

        .toolbar button,
        .toolbar a {
            font: inherit;
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
            border: 1px solid #111;
            border-radius: 4px;
            background: #111;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
        }

        .toolbar a.secondary {
            background: #fff;
            color: #111;
        }

        .label {
            width: 105mm;
            max-width: 96vw;
            margin: 0 auto 1.5rem;
            background: #fff;
            border: 2px solid #000;
            padding: 6mm;
        }

        .dest-tag {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 2mm;
        }

        .dest-name {
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 2mm;
        }

        .dest-line {
            font-size: 13px;
            line-height: 1.5;
        }

        .cep-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4mm;
            margin-top: 4mm;
            padding-top: 3mm;
            border-top: 1px dashed #000;
        }

        .cep-box {
            font-size: 18px;
            font-weight: 700;
            font-family: "Courier New", monospace;
            letter-spacing: 0.06em;
            border: 1.5px solid #000;
            padding: 1.5mm 3mm;
            white-space: nowrap;
        }

        .cep-city {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }

        @media print {

            html,
            body {
                background: #fff;
            }

            .toolbar {
                display: none;
            }

            .label {
                margin: 0;
                border-width: 1.5px;
            }

            @page {
                margin: 8mm;
            }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <button type="button" id="label-print">Imprimir etiqueta</button>
        <a class="secondary" href="<?php echo $e(sprintf($GLOBALS['order_url'], (int)($order['idx'] ?? 0))); ?>">Voltar ao pedido</a>
    </div>

    <div class="label">
        <div class="dest-tag">Destinatário</div>
        <div class="dest-name"><?php echo $e($nome !== '' ? $nome : '—'); ?></div>
        <div class="dest-line"><?php echo $e($logradouro !== '' ? $logradouro : '—'); ?></div>
        <?php if ($complemento !== ''): ?>
            <div class="dest-line"><?php echo $e($complemento); ?></div>
        <?php endif; ?>
        <?php if ($bairro !== ''): ?>
            <div class="dest-line"><?php echo $e($bairro); ?></div>
        <?php endif; ?>

        <div class="cep-row">
            <span class="cep-box"><?php echo $e($cep !== '' ? $cep : '—'); ?></span>
            <span class="cep-city"><?php echo $e($cidadeUf !== '' ? $cidadeUf : '—'); ?></span>
        </div>
    </div>

    <!-- CSP bloqueia handlers inline; listener via <script nonce> -->
    <script nonce="<?php echo $e($GLOBALS['cspNonce'] ?? ''); ?>">
        document.getElementById('label-print').addEventListener('click', function () {
            window.print();
        });
    </script>
</body>

</html>
