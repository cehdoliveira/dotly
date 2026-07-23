<?php
$name      = isset($name)      ? (string) $name      : '';
$login     = isset($login)     ? (string) $login     : '';
$loginLink = isset($loginLink) ? (string) $loginLink : '#';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Seus dados de acesso &mdash; <?php echo htmlspecialchars(constant('cTitle')); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#060b11;font-family:Arial,Helvetica,sans-serif;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#060b11;">
  <tr>
    <td align="center" style="padding:48px 16px;">
      <table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#0d1520;border-radius:10px;overflow:hidden;border:1px solid rgba(37,99,235,0.2);">
        <tr>
          <td style="background-color:#060b11;padding:36px 40px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td align="left">
                  <span style="font-family:Arial,Helvetica,sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#2563eb;"><?php echo htmlspecialchars(constant('cTitle')); ?></span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="background-color:#2563eb;height:2px;font-size:0;line-height:0;">&nbsp;</td>
        </tr>
        <tr>
          <td style="background-color:#0d1520;padding:44px 48px 36px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td style="font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:700;color:#f1f5f9;padding-bottom:20px;">
                  Bem-vindo ao painel administrativo!
                </td>
              </tr>
              <tr>
                <td style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#e2e8f0;line-height:1.75;padding-bottom:16px;">
                  Olá, <strong style="color:#3b82f6;font-family:Arial,Helvetica,sans-serif;"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>!
                </td>
              </tr>
              <tr>
                <td style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#b0bec5;line-height:1.75;padding-bottom:32px;">
                  Seu cadastro no <strong style="color:#e2e8f0;font-family:Arial,Helvetica,sans-serif;"><?php echo htmlspecialchars(constant('cTitle')); ?></strong> foi realizado com sucesso. Abaixo estão seus dados de acesso ao painel administrativo. Guarde-os em local seguro.
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:32px;">
                  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#142030;border-radius:8px;border:1px solid rgba(255,255,255,0.06);">
                    <tr>
                      <td style="padding:28px 32px;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#7a8ba0;letter-spacing:1px;text-transform:uppercase;padding-bottom:6px;">
                              LOGIN / E-MAIL
                            </td>
                          </tr>
                          <tr>
                            <td style="font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#3b82f6;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,0.06);">
                              <?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                          </tr>
                          <tr>
                            <td style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#7a8ba0;letter-spacing:1px;text-transform:uppercase;padding-top:20px;padding-bottom:6px;">
                              ACESSO
                            </td>
                          </tr>
                          <tr>
                            <td style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#b0bec5;line-height:1.65;">
                              Para ativar sua conta e acessar o painel, defina sua senha clicando no botão abaixo.
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="background-color:#142030;border-left:3px solid #2563eb;padding:14px 18px;border-radius:0 4px 4px 0;">
                  <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a8ba0;line-height:1.65;">&#128274; <strong style="color:#b0bec5;font-family:Arial,Helvetica,sans-serif;">Segurança:</strong> Nunca compartilhe suas credenciais de acesso. Recomendamos alterar a senha imediatamente após o primeiro login.</p>
                </td>
              </tr>
              <tr>
                <td style="padding-top:28px;padding-bottom:8px;">
                  <table border="0" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="background-color:#2563eb;border-radius:8px;">
                        <a href="<?php echo htmlspecialchars($setPasswordLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="display:inline-block;padding:15px 40px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">Definir minha senha</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="background-color:#060b11;padding:24px 48px;border-top:1px solid rgba(255,255,255,0.06);">
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#7a8ba0;line-height:1.65;text-align:center;">Este é um e-mail automático. Por favor, não responda a esta mensagem.<br>Se você não solicitou este acesso, entre em contato imediatamente com o administrador do sistema.<br><br>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(constant('cTitle')); ?> &mdash; Todos os direitos reservados.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
