<?php
$name        = isset($name)       ? (string) $name       : '';
$orderToken  = isset($orderToken) ? (string) $orderToken : '';
$doneLink    = isset($GLOBALS['done_url']) ? sprintf($GLOBALS['done_url'], $orderToken) : '#';
$brand       = htmlspecialchars(constant('cStoreName'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Pagamento confirmado — <?php echo $brand; ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f7f7fb;font-family:Arial,Helvetica,sans-serif;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f7f7fb;">
  <tr>
    <td align="center" style="padding:40px 16px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e2e1f0;border-radius:12px;overflow:hidden;">

        <!-- Marca -->
        <tr>
          <td style="padding:28px 40px 24px;">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:800;letter-spacing:0.5px;color:#2e2b6e;"><?php echo $brand; ?></span>
          </td>
        </tr>
        <tr><td style="background-color:#2e2b6e;height:3px;font-size:0;line-height:0;">&nbsp;</td></tr>

        <!-- Herói -->
        <tr>
          <td align="center" style="padding:44px 48px 8px;">
            <!-- Badge de sucesso (teal) -->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td width="60" height="60" align="center" valign="middle" style="background-color:#128c7e;border-radius:30px;font-family:Arial,Helvetica,sans-serif;font-size:30px;line-height:60px;color:#ffffff;font-weight:700;">&#10003;</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:20px 48px 0;">
            <div style="font-family:'Courier New',Courier,monospace;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#5855b0;padding-bottom:10px;">Pedido confirmado</div>
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:26px;font-weight:800;color:#2e2b6e;">Pagamento confirmado</div>
          </td>
        </tr>

        <!-- Corpo -->
        <tr>
          <td style="padding:24px 48px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1a1830;line-height:1.7;">
            Olá<?php echo $name !== '' ? ', <strong style="color:#2e2b6e;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>' : ''; ?>.
          </td>
        </tr>
        <tr>
          <td style="padding:12px 48px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#7a7890;line-height:1.7;">
            Recebemos seu pagamento e já começamos a preparar seu pedido. Você receberá um novo e-mail assim que ele for enviado.
          </td>
        </tr>

        <!-- Ação -->
        <tr>
          <td align="center" style="padding:0 48px 14px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background-color:#2e2b6e;border-radius:8px;">
                  <a href="<?php echo htmlspecialchars($doneLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="font-family:Arial,Helvetica,sans-serif;display:inline-block;padding:15px 44px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">Acompanhar meu pedido</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <?php if (constant('whatsapp_number') !== ''): ?>
        <!-- Fale conosco (WhatsApp) -->
        <tr>
          <td align="center" style="padding:0 48px 40px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td style="border:1.5px solid #2e2b6e;border-radius:8px;background-color:#ffffff;">
                  <a href="https://wa.me/<?php echo htmlspecialchars(constant('whatsapp_number'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="font-family:Arial,Helvetica,sans-serif;display:inline-block;padding:13px 40px;font-size:14px;font-weight:700;color:#2e2b6e;text-decoration:none;">Falar no WhatsApp</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <?php endif; ?>

        <!-- Fallback link -->
        <tr>
          <td style="padding:0 48px 40px;font-family:'Courier New',Courier,monospace;font-size:11px;color:#7a7890;line-height:1.6;word-break:break-all;border-top:1px solid #e2e1f0;padding-top:24px;">
            Se o botão não abrir, copie o endereço no seu navegador:<br>
            <a href="<?php echo htmlspecialchars($doneLink, ENT_QUOTES, 'UTF-8'); ?>" style="color:#5855b0;"><?php echo htmlspecialchars($doneLink, ENT_QUOTES, 'UTF-8'); ?></a>
          </td>
        </tr>

        <!-- Rodapé -->
        <tr>
          <td style="background-color:#f7f7fb;padding:22px 48px;border-top:1px solid #e2e1f0;">
            <p style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#7a7890;margin:0;text-align:center;line-height:1.6;">
              E-mail automático, não responda esta mensagem.<br>
              &copy; <?php echo date('Y'); ?> <?php echo $brand; ?>
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>

</body>
</html>
