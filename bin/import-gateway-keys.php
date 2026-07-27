#!/usr/bin/env php
<?php
/**
 * ONE-SHOT (plano 026). Le as constantes antigas de gateway do
 * site/app/inc/kernel.php e grava o mesmo conteudo cifrado em
 * payment_gateways.credentials_enc.
 *
 * ORDEM DE USO — nao inverta:
 *   1. adicione APP_ENCRYPTION_KEY (mesmo valor) nos DOIS kernel.php do servidor,
 *      SEM remover ainda as 5 constantes antigas;
 *   2. faça o deploy do codigo deste plano e rode as migrations pendentes;
 *   3. rode este script;
 *   4. confira em /config que os 3 gateways aparecem como "Configuradas";
 *   5. so entao apague MP_ACCESS_TOKEN, MP_WEBHOOK_SECRET, PAGBANK_API_BASE,
 *      PAGBANK_TOKEN e INFINITEPAY_HANDLE dos kernel.php do servidor.
 *
 * Idempotente: rodar 2x grava o mesmo conteudo. Nao imprime nenhum valor de
 * credencial — so o nome do campo e se foi importado ou pulado.
 */

define('APP_PATH', realpath(__DIR__ . '/../site/app'));

// kernel.php deriva cRootServer/cRootServer_APP de $_SERVER["DOCUMENT_ROOT"] —
// em CLI isso nao existe, entao setamos na mao antes do require (mesmo padrao
// dos snippets de diagnostico do bin/doctor.sh). Sem isto, o autoload de
// classes de app/inc/{controller,lib,model} (registrado em CommonFunctions.php,
// incluido pelo vendor/autoload.php) resolve caminho errado e GatewayCredentials
// nunca carrega.
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../site/public_html');
$_SERVER['HTTP_HOST'] = '';

require_once APP_PATH . '/inc/lib/vendor/autoload.php';
require_once APP_PATH . '/inc/kernel.php';

/**
 * Mapa slug => [campo do schema => nome da constante antiga do kernel]. So
 * este script conhece essa correspondencia — GatewayCredentials::SCHEMA nao
 * precisa saber de onde a credencial costumava vir.
 *
 * @var array<string, array<string, string>>
 */
$constantsBySlug = [
    'mercadopago' => [
        'access_token'   => 'MP_ACCESS_TOKEN',
        'webhook_secret' => 'MP_WEBHOOK_SECRET',
    ],
    'pagbank' => [
        'api_base' => 'PAGBANK_API_BASE',
        'token'    => 'PAGBANK_TOKEN',
    ],
    'infinitepay' => [
        'handle' => 'INFINITEPAY_HANDLE',
    ],
];

$importedAny = false;

foreach ($constantsBySlug as $slug => $fields) {
    $values = [];

    foreach ($fields as $field => $constantName) {
        $value = defined($constantName) ? (string)constant($constantName) : '';

        if ($value === '') {
            echo "{$slug}.{$field}: pulado (constante ausente ou vazia)\n";
            continue;
        }

        $values[$field] = $value;
        $importedAny = true;
        echo "{$slug}.{$field}: importado\n";
    }

    if ($values !== []) {
        GatewayCredentials::save($slug, $values);
    }
}

if (!$importedAny) {
    echo "\nNenhuma credencial importada (nenhuma constante antiga preenchida no kernel do site).\n";
}

// Os models usam localPDO::getInstance() (singleton com transacao propria) —
// sem commit explicito, localPDO::__destruct() faz rollback de seguranca ao
// fim do processo e nada seria gravado.
localPDO::getInstance()->commit();

exit(0);
