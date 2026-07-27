<?php

/**
 * Etiqueta de envio (endereçamento padrão Correios) — página standalone,
 * pronta para impressão. Renderizada por orders_controller::label(), fora do
 * layout do manager (sem sidebar/header).
 *
 * Consome $order (destinatário) e, desde o plano 025, $sender (remetente da
 * loja, cadastrado em /config). São DUAS etiquetas independentes e do mesmo
 * tamanho na mesma página: um Ctrl+P imprime as duas. Sem remetente cadastrado
 * a 2ª etiqueta não é impressa e um aviso (que não sai na impressão) aponta
 * para Configurações.
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

// Plano 025: remetente. A view tolera $sender ausente (a suite de teste
// renderiza so com $order).
$sender = is_array($sender ?? null) ? $sender : [];

$remNome        = strtoupper(trim((string)($sender['sender_name'] ?? '')));
$remLogradouro  = trim(($sender['sender_street'] ?? '') . ', ' . ($sender['sender_number'] ?? ''), ', ');
$remComplemento = trim((string)($sender['sender_complement'] ?? ''));
$remBairro      = trim((string)($sender['sender_district'] ?? ''));
$remCidadeUf    = trim(trim((string)($sender['sender_city'] ?? '')) . ' - ' . trim((string)($sender['sender_uf'] ?? '')), ' -');
$remCep         = $fmtCep($sender['sender_zip'] ?? null);

// Etiqueta de remetente só é impressa com o mínimo utilizável preenchido —
// mesmo conjunto que config_controller::SENDER_REQUIRED_KEYS exige. Checagem
// campo a campo (nao pelas strings concatenadas $remLogradouro/$remCidadeUf):
// um unico campo preenchido (ex.: so sender_number) bastaria pra essas strings
// darem nao-vazias mesmo com sender_street/sender_city ausentes.
$temRemetente = trim((string)($sender['sender_name'] ?? '')) !== ''
    && trim((string)($sender['sender_street'] ?? '')) !== ''
    && trim((string)($sender['sender_number'] ?? '')) !== ''
    && trim((string)($sender['sender_city'] ?? '')) !== ''
    && trim((string)($sender['sender_uf'] ?? '')) !== ''
    && $remCep !== '';

$configUrl = (string)($GLOBALS['config_url'] ?? '');
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

        .no-sender {
            width: 105mm;
            max-width: 96vw;
            margin: 0 auto 1.5rem;
            font-size: 0.8rem;
            line-height: 1.4;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #d97706;
            border-radius: 4px;
            padding: 0.6rem 0.8rem;
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
                margin: 0 0 6mm;
                border-width: 1.5px;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .no-sender {
                display: none;
            }

            @page {
                margin: 8mm;
            }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <button type="button" id="label-print">Imprimir etiquetas</button>
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

    <?php if ($temRemetente): ?>
        <div class="label">
            <div class="dest-tag">Remetente</div>
            <div class="dest-name"><?php echo $e($remNome); ?></div>
            <div class="dest-line"><?php echo $e($remLogradouro); ?></div>
            <?php if ($remComplemento !== ''): ?>
                <div class="dest-line"><?php echo $e($remComplemento); ?></div>
            <?php endif; ?>
            <?php if ($remBairro !== ''): ?>
                <div class="dest-line"><?php echo $e($remBairro); ?></div>
            <?php endif; ?>

            <div class="cep-row">
                <span class="cep-box"><?php echo $e($remCep); ?></span>
                <span class="cep-city"><?php echo $e($remCidadeUf); ?></span>
            </div>
        </div>
    <?php else: ?>
        <div class="no-sender">
            Nenhum endereço de remetente cadastrado — só a etiqueta do destinatário será impressa.
            <?php if ($configUrl !== ''): ?>
                Cadastre em <a href="<?php echo $e($configUrl); ?>">Configurações → Endereço do Remetente</a>.
            <?php else: ?>
                Cadastre em Configurações → Endereço do Remetente.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- CSP bloqueia handlers inline; listener via <script nonce> -->
    <script nonce="<?php echo $e($GLOBALS['cspNonce'] ?? ''); ?>">
        document.getElementById('label-print').addEventListener('click', function () {
            window.print();
        });
    </script>
</body>

</html>
