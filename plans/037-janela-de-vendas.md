# Plan 037: Janela de vendas do site (período de compras + fechamento por estoque, controlado em /config)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat faa18f8..HEAD -- site/public_html/index.php manager/app/inc/controller/config_controller.php manager/public_html/ui/page/config.php site/app/inc/lib site/app/inc/model manager/app/inc/lib manager/app/inc/model migrations/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (intercepta TODA requisição do site público; um erro no gate derruba a loja)
- **Depends on**: none
- **Category**: direction (feature pedida pelo dono)
- **Planned at**: commit `faa18f8`, 2026-07-22

## Why this matters

A loja opera por **janela de vendas**: compras só acontecem dentro de um
período configurado pelo dono na tela **Configurações** do manager. Fora da
janela — ou quando o **estoque acaba** — o site fica "desabilitado para
compras": o visitante vê uma página informando que as vendas estão encerradas,
com a **data de reabertura** (quando conhecida) e **um único botão** que leva
ao WhatsApp do Atendimento.

**Isto NÃO é "modo manutenção"** — o site não está quebrado nem em obras; é um
estado normal do negócio (ciclo de vendas encerrado / estoque esgotado). Toda a
copy, nomes de chave, classe e página refletem "vendas/janela", nunca
"manutenção". (Uma versão anterior deste plano usava o frame "manutenção"; o
dono corrigiu — não reintroduzir esse vocabulário.)

Decisões já tomadas pelo dono (não reabrir):

1. **Fechamento por estoque é AUTOMÁTICO**: nenhum produto ativo com
   `stock > 0` ⇒ compras fecham sozinhas, mesmo dentro da janela. Existe
   override manual no /config para forçar aberto/fechado.
2. **Fora da janela, página única**: qualquer acesso às rotas de compra cai na
   página "vendas encerradas". O catálogo NÃO fica navegável fora da janela.
3. **Escopo do bloqueio = "pós-venda vivo"**: bloqueia home, produto, carrinho
   e checkout (GET e POST). Ficam acessíveis: `/pagamento/*`, `/pedido/*`,
   `/acompanhar-pedido` e `/webhook/pix/*` — quem tem PIX pendente consegue
   pagar e acompanhar; o PSP consegue confirmar pagamentos.
4. **No máximo UM botão** na página de vendas encerradas: o CTA do WhatsApp.
   Um link textual discreto para `/acompanhar-pedido` é permitido (não é
   botão), nada além disso.
5. **A skill `frontend-design:frontend-design` DEVE ser invocada** (via Skill
   tool) antes de escrever a UI — exigência explícita do dono. Ver "Suggested
   executor toolkit".

## Current state

### Arquitetura relevante (fatos do framework LEGGO)

- **Duas cópias byte-idênticas**: `manager/app/inc/lib` + `manager/app/inc/model`
  DEVEM ser idênticos a `site/app/inc/lib` + `site/app/inc/model`. O guard
  `bin/check-shared-sync.sh` roda no pre-commit e falha se divergirem
  (`diff -rq --exclude=vendor --exclude=tests`). Controllers, `index.php`,
  `urls.php`, `kernel.php` e `ui/` são por ambiente e podem divergir. Qualquer
  classe nova em `app/inc/lib/` precisa ser copiada byte a byte para o outro
  ambiente.
- **Autoload**: classes de `lib/`, `model/` e `controller/` carregam por nome
  de arquivo via `m_autoload` (`site/app/inc/lib/CommonFunctions.php:7-32`) —
  criar `SalesWindow.php` em `app/inc/lib/` torna `SalesWindow` instanciável
  sem tocar em composer.json.
- **Transação global única por request**: `localPDO` abre transação no início;
  `basic_redir($url)` comita; `basic_redir($url, rollback: true)` reverte;
  destructor faz rollback de segurança. Controllers nunca chamam
  commit/rollback manualmente. **Cuidado com clock skew**: o MySQL do container
  tem ~3h de skew contra o PHP (`America/Sao_Paulo`) — toda comparação de
  data/hora da janela de vendas deve acontecer **em PHP** (`date('Y-m-d H:i:s')`),
  nunca via `NOW()` do MySQL; e carimbos `modified_at` são passados como
  parâmetro PHP, não `NOW()`.
- **Soft-delete universal**: `active = 'yes'/'no'`, nunca `DELETE FROM`. A
  UNIQUE de `settings.skey` abrange linhas soft-deletadas — um upsert precisa
  reativar (`active='yes'`) em vez de tentar re-inserir.
- **CSRF**: todo POST valida `validate_csrf($post['_csrf_token'] ?? null, $redir_url)`
  (com grace de 10s). NÃO modificar `validate_csrf` — é compartilhada e o
  checkout depende do comportamento atual.
- **Estoque**: `products.stock` (INT) existe e o checkout já valida estoque por
  item (`CheckoutStockTest`). A checagem "existe algo vendível?" é
  `SELECT idx FROM products WHERE active='yes' AND stock > 0 LIMIT 1`.

### Tabela `settings` (migrations/018_create_table_settings.sql)

```sql
CREATE TABLE IF NOT EXISTS `settings` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `skey` VARCHAR(60) NOT NULL UNIQUE,
    `svalue` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB ...;
```

Model (idêntico nos dois ambientes), `site/app/inc/model/settings_model.php`:

```php
class settings_model extends DOLModel
{
    protected array $field = [" idx ", " skey ", " svalue "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("settings"); }
}
```

### Padrão de leitura de settings — exemplar `site/app/inc/lib/OrderPricing.php:60-78`

```php
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
```

`DOLModel` também expõe `execute_raw_prepared(string $sql, array $params): \PDOStatement`
(`site/app/inc/lib/DOLModel.php:263`) para escrita crua com prepared statement.
Padrão de checagem de existência de produto vendível — exemplar
`OrderPricing::cartHasInfinity()` (`site/app/inc/lib/OrderPricing.php:117-124`):

```php
$model = new products_model();
$stmt = $model->select(
    [" idx "],
    "WHERE active = 'yes' AND is_infinity = 'yes' AND idx IN ($placeholders)",
    $productIds
);
return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
```

### Site — front controller `site/public_html/index.php`

Sequência atual (arquivo completo tem 99 linhas):

```php
43  require_once($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/main.php");
...
48  $GLOBALS["cspNonce"] = random_token(16);
49  header("Content-Security-Policy: ...");   // uma linha longa
...
52  $params = [ "sr" => ..., "format" => ".html", "post" => $_POST ?? null, "get" => $_GET ?? null ];
...
63  $dispatcher = new Dispatcher(true);
...   // rotas:
69  $dispatcher->add_route("GET", "/?", "site_controller:home", null, $params);
74  $dispatcher->add_route("GET",  "/carrinho", "cart_controller:index",  null, $params);
75  $dispatcher->add_route("POST", "/carrinho", "cart_controller:action", null, $params);
78  $dispatcher->add_route("GET", "/produto/([a-z0-9]+(?:[-_][a-z0-9]+)*)", "shop_controller:product", null, $params);
81  $dispatcher->add_route("GET",  "/checkout", "checkout_controller:index", null, $params);
82  $dispatcher->add_route("GET",  "/checkout/cep/([0-9]{8})", "checkout_controller:cep", null, $params);
83  $dispatcher->add_route("POST", "/checkout", "checkout_controller:finalize", null, $params);
84  $dispatcher->add_route("GET",  "/pagamento/([a-f0-9]{32})/status", "checkout_controller:status", null, $params);
85  $dispatcher->add_route("GET",  "/pagamento/([a-f0-9]{32})", "checkout_controller:payment", null, $params);
86  $dispatcher->add_route("GET",  "/pedido/([a-f0-9]{32})", "checkout_controller:done", null, $params);
90  $dispatcher->add_route("POST", "/webhook/pix/(mercadopago|pagbank|infinitepay)", "webhook_controller:receive", null, $params);
93  $dispatcher->add_route("GET",  "/acompanhar-pedido", "track_order_controller:index",  null, $params);
94  $dispatcher->add_route("POST", "/acompanhar-pedido", "track_order_controller:search", null, $params);
97  if (!$dispatcher->exec()) { basic_redir($home_url); }
```

O `Dispatcher` (`app/inc/lib/Dispatcher.php`) é **lib compartilhada** — o gate
da janela NÃO entra nele; entra no `site/public_html/index.php`, que é
por-ambiente. O manager NÃO recebe gate nenhum (admin precisa acessar `/config`
justamente com as vendas fechadas).

### Views do site

- `site/public_html/ui/common/head.php` (25 linhas): `<!DOCTYPE html>` até o
  fim do `<head>` (sem abrir `<body>` — o `<body>` é aberto por
  `ui/common/header.php`, que este plano NÃO usa na página de vendas
  encerradas). Suporta `$noindex` (linhas 10-11: emite
  `<meta name="robots" content="noindex, nofollow">` se `!empty($noindex)`).
  Carrega Bootstrap 5.3 + bootstrap-icons via CDN, fontes Plus Jakarta Sans/
  DM Mono e `assets/css/main.css`.
- CSP do site (linha 49 do index.php): `script-src 'self' 'nonce-{$GLOBALS['cspNonce']}' ...`;
  `style-src` permite `'unsafe-inline'`. `connect-src 'self' https://cdn.jsdelivr.net`
  — **fetch externo é bloqueado**; a página de vendas encerradas não deve
  fazer fetch.
- Constantes já existentes no kernel (`site/app/inc/kernel.php.example:39-42, 68`):
  `whatsapp_number` (só dígitos, para `wa.me/`), `whatsapp_display` (formatado),
  `cStoreName` (marca da loja voltada ao cliente). Exemplar de uso:
  `site/public_html/ui/common/footer.php:11`:

```php
<a href="https://wa.me/<?php echo htmlspecialchars(constant('whatsapp_number'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Atendimento: <?php echo htmlspecialchars(constant('whatsapp_display'), ENT_QUOTES, 'UTF-8'); ?></a>
```

- O tema da vitrine é **claro** (`data-theme="light"`), decisão registrada em
  `plans/README.md` ("Decisões já tomadas"). A página de vendas encerradas
  segue o design system existente de `site/public_html/assets/css/main.css`.

### Manager — tela /config

- Rotas (`manager/public_html/index.php:100-103`):

```php
$dispatcher->add_route("GET",  "/config", "config_controller:index",  $authGuard, $params);
$dispatcher->add_route("POST", "/config", "config_controller:action", $authGuard, $params);
$dispatcher->add_route("POST", "/config/usuarios", "config_controller:users_action", $authGuard, $params);
```

- `manager/app/inc/controller/config_controller.php` — dispatch do POST
  (linhas 118-141): lê `$post['action']`, chama `validate_csrf(...)`, roteia
  para `saveProfile` / `savePassword` / `saveGateway`. Padrão de um save
  (linhas 238-267, `saveGateway`):

```php
private function saveGateway(array $post, string $config_url): never
{
    $idx = (int)($post['idx'] ?? 0);
    if ($idx <= 0) { basic_redir($config_url); }
    $enabled = (($post['enabled'] ?? 'no') === 'yes') ? 'yes' : 'no';
    ...
    $rollback = false;
    try {
        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$idx]);
        $update->populate([...]);
        $update->save();
        $_SESSION["messages_app"]["success"] = ["Gateway atualizado com sucesso."];
    } catch (RuntimeException $e) {
        $rollback = true;
        Logger::getInstance()->error("config_action(gateway) failed", [...]);
        $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar gateway."];
    }
    basic_redir($config_url, rollback: $rollback);
}
```

- View `manager/public_html/ui/page/config.php` (409 linhas): cards
  `content-panel` com `content-panel-header` (ícone bootstrap-icons + título) e
  `content-panel-body`; formulários POST para `$GLOBALS['config_url']` com
  `<input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">`
  e `<input type="hidden" name="action" value="...">`. Exemplar: card "Dados da
  Conta" (linhas 42-78). Variáveis vindas do controller são defensivas no topo
  da view (`$users = $users ?? [];` etc., linhas 7-20).

### Testes

- DB-tests estendem `DBTestCase` (transação + rollback por teste). Bootstrap
  carrega `kernel.php` → precisa de DB viva (stack docker de pé).
- Exemplar de teste que escreve/lê `settings`:
  `site/tests/OrderPricingTest.php` (helper `setSetting` usa
  `execute_raw_prepared("UPDATE settings SET svalue = ? WHERE skey = ?", ...)`;
  helper `createProduct` popula `products_model` com
  `name/slug/category/is_infinity/price_unit_cents/box_qty/stock`).
- Exemplar de teste de action do config:
  `manager/tests/ConfigActionTest.php` — **não chama** `action()` diretamente
  (o `basic_redir()` final dá `exit()` e mataria o PHPUnit); reproduz a mesma
  sequência de escrita que o controller monta a partir do `$post`.
- Precedente de teste espelhado nos dois ambientes: `DOLModelQueryHelpersTest.php`
  existe em `site/tests/` e `manager/tests/`.
- **`bin/test.sh` está quebrado** (falta `-w` no `docker exec`; o PHPUnit
  imprime o help e o script parece verde). NÃO usar como verificação — rodar
  PHPUnit direto em cada ambiente como abaixo.

### Migrations

Numeradas (`NNN_desc.sql`), idempotentes (log em `migrations_log`), uma
transação por arquivo. Última existente: `043_add_index_active_status_pix_charges.sql`.
Próxima livre: **044**. Exemplar de seed idempotente: o próprio
`018_create_table_settings.sql` usa `INSERT IGNORE`.

## Semântica da janela (contrato — implementar exatamente isto)

Três chaves novas em `settings`:

| skey | valores | significado |
|---|---|---|
| `sales_override` | `''` / `'open'` / `'closed'` | `''` = automático (janela + estoque); `'open'` = força vendas abertas; `'closed'` = força vendas fechadas (pontual) |
| `sales_window_start_at` | `''` ou `'Y-m-d H:i:s'` | início da janela de vendas; `''` = sem restrição de início |
| `sales_window_end_at` | `''` ou `'Y-m-d H:i:s'` | fim da janela de vendas; `''` = sem restrição de fim |

Avaliação (**em PHP**, `$now = date('Y-m-d H:i:s')`), nesta ordem de
precedência — a primeira regra que casa decide:

```
1. override == 'open'   => ABERTO  (ignora janela e estoque; checkout continua
                                    validando estoque por item, então pedido
                                    inválido não nasce)
2. override == 'closed' => FECHADO (reason 'override', reopens_at = null)
3. start != '' e now < start        => FECHADO (reason 'window', reopens_at = start)
4. end   != '' e now > end          => FECHADO (reason 'window', reopens_at = null —
                                       só o dono sabe quando abre a próxima janela)
5. nenhum produto active='yes' com stock > 0
                                    => FECHADO (reason 'stock', reopens_at = null)
6. caso contrário                   => ABERTO
```

- Janela vazia (`start` e `end` = `''`) = sem restrição de data — só estoque
  e override decidem. **Atenção: com o seed default (tudo vazio), a regra 5
  já vale — estoque zerado fecha as vendas automaticamente a partir do deploy.
  Isso é intencional (decisão do dono).**
- Retorno da avaliação: `['open' => bool, 'reopens_at' => ?string, 'reason' => ?string]`
  com `reason ∈ {null, 'override', 'window', 'stock'}` (null quando aberto).
  `reopens_at` só é não-nulo quando é uma data futura conhecida (caso 3).
- Valor ilegível/corrompido em qualquer chave ⇒ trata como `''` (automático /
  sem restrição).
- Erro de banco na leitura ⇒ **fail-open: ABERTO** (mesma filosofia fail-open
  de Redis/Kafka do projeto; com o DB fora, o site já está quebrado de
  qualquer jeito — o gate não pode ser mais um ponto de falha).
- A página de vendas encerradas responde **HTTP 200 + noindex** (é um estado
  normal do negócio, não um erro; 503 sinalizaria outage para crawlers em
  fechamentos que podem durar semanas).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Stack | `docker compose -f docker/docker-compose.yml up -d --build` | containers up |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | menciona 044 aplicada (ou já aplicada) |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit site | `cd site && php app/inc/lib/vendor/bin/phpunit` | OK (ver nota abaixo) |
| PHPUnit manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | OK |
| Teste único | `php app/inc/lib/vendor/bin/phpunit --filter NomeDoTeste` | OK |
| Sync guard | `bin/check-shared-sync.sh` | exit 0, sem output |
| Lint sintaxe | `php -l <arquivo>` | `No syntax errors` |

Nota: rode a suíte inteira ANTES de mexer em qualquer coisa para registrar a
baseline — se algum teste já falhar em `main`, anote e não conte como regressão
sua (nem conserte: fora de escopo).

## Suggested executor toolkit

- **OBRIGATÓRIO (exigência do dono): invoque a skill `frontend-design:frontend-design`
  via Skill tool ANTES de escrever qualquer UI deste plano** (Step 5 — página
  de vendas encerradas; e reutilize a orientação no card do manager do Step 6).
  Se a skill não estiver disponível no seu ambiente, isso é STOP condition —
  reporte em vez de improvisar o design.
- A skill orienta direção estética/tipografia; as **restrições de conteúdo**
  da página (um botão só, data de reabertura, WhatsApp, tom "vendas
  encerradas" e não "manutenção") estão no Step 5 e prevalecem sobre qualquer
  sugestão da skill.

## Scope

**In scope** (únicos arquivos a modificar/criar):

- `migrations/044_seed_sales_window_settings.sql` (criar)
- `site/app/inc/lib/SalesWindow.php` (criar)
- `manager/app/inc/lib/SalesWindow.php` (criar — cópia byte-idêntica)
- `site/public_html/index.php` (gate)
- `site/public_html/ui/page/sales_closed.php` (criar)
- `manager/app/inc/controller/config_controller.php` (action + load)
- `manager/public_html/ui/page/config.php` (novo card)
- `site/tests/SalesWindowTest.php` (criar)
- `manager/tests/SalesWindowTest.php` (criar — espelho)
- `manager/tests/ConfigSalesWindowActionTest.php` (criar)
- `plans/README.md` (status)

**Out of scope** (NÃO tocar, mesmo parecendo relacionado):

- `app/inc/lib/Dispatcher.php` (ambos) — o gate NÃO entra no dispatcher.
- `app/inc/lib/CommonFunctions.php` (ambos) — em particular `validate_csrf`,
  o checkout depende do comportamento atual (guarda anti-duplo-submit).
- `site/app/inc/controller/*` — nenhum controller do site muda; o gate é
  anterior ao dispatch. Em particular NÃO mexer na validação de estoque por
  item do `checkout_controller` — ela continua sendo a última linha de defesa.
- `manager/public_html/index.php` — manager não tem gate nem rota nova
  (o POST reaproveita `/config` + `action=janela`).
- `kernel.php.example` (ambos) — nenhuma constante nova; WhatsApp já existe.
- `app/inc/model/settings_model.php` e `products_model.php` (ambos) — já
  servem como estão.
- `site/public_html/ui/common/header.php` / `footer.php` — a página de vendas
  encerradas não usa o header de navegação (não deve exibir carrinho).
- `bin/test.sh` — quebrado, mas consertá-lo é outro trabalho.

## Git workflow

- Branch: `advisor/037-janela-de-vendas` a partir de `main`.
- Commits em PT-BR, Conventional Commits (padrão do repo: `feat:`, `fix:`,
  `test:` — ver `git log --oneline -10`). Um commit por passo lógico.
- NÃO fazer push nem abrir PR sem instrução do operador.

## Steps

### Step 0: Baseline

Rode a suíte inteira dos dois ambientes e o PHPStan dos dois ambientes
(comandos acima). Anote falhas pré-existentes, se houver.

**Verify**: os 4 comandos terminam; baseline anotada.

### Step 1: Migration 044 — seed das chaves

Crie `migrations/044_seed_sales_window_settings.sql`:

```sql
INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'sales_override',        ''),
    (NOW(), 0, 'yes', 'sales_window_start_at', ''),
    (NOW(), 0, 'yes', 'sales_window_end_at',   '');
```

**Verify**:
`docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php`
→ 044 aplicada. Rodar o runner duas vezes seguidas não pode dar erro
(idempotência via `INSERT IGNORE` + `migrations_log`). A presença das linhas é
confirmada pelos testes do Step 3.

### Step 2: `SalesWindow` (lib compartilhada)

Crie `site/app/inc/lib/SalesWindow.php`:

```php
<?php

/**
 * Janela de vendas do site publico. A loja opera por periodos de venda:
 * compras so acontecem dentro da janela configurada em /config (manager) e
 * enquanto houver estoque. Fora disso o site mostra "vendas encerradas" —
 * que e um estado normal do negocio, NAO modo manutencao.
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
```

Nota: confirme a assinatura de `DOLModel::select()` quanto ao `LIMIT` — se o
`select()` não aceitar `LIMIT` no sufixo do WHERE (veja como
`config_controller::index()` e `OrderPricing` o usam), rode sem `LIMIT 1` e
cheque só o primeiro fetch; o custo é irrelevante com índice em `active`.

Copie byte a byte para `manager/app/inc/lib/SalesWindow.php`
(`cp site/app/inc/lib/SalesWindow.php manager/app/inc/lib/SalesWindow.php`).

**Verify**:
- `php -l site/app/inc/lib/SalesWindow.php` → sem erros
- `bin/check-shared-sync.sh` → exit 0
- `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → OK (repita no manager)

### Step 3: Testes de `SalesWindow` (antes do gate)

Crie `site/tests/SalesWindowTest.php`, `final class SalesWindowTest extends
DBTestCase`, com helpers no padrão de `OrderPricingTest`:

```php
private function setSetting(string $key, string $value): void
{
    $model = new settings_model();
    $model->execute_raw_prepared(
        "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, 0, 'yes', ?, '')",
        [date('Y-m-d H:i:s'), $key]
    );
    $model->execute_raw_prepared(
        "UPDATE settings SET svalue = ?, active = 'yes' WHERE skey = ?",
        [$value, $key]
    );
}

/** Zera o estoque de TODOS os produtos (dentro da transacao do teste, com rollback automatico). */
private function drainAllStock(): void
{
    $model = new products_model();
    $model->execute_raw_prepared("UPDATE products SET stock = 0", []);
}

/** Garante que existe ao menos um produto vendivel. */
private function ensureSellableProduct(): void
{
    $model = new products_model();
    $model->populate([
        'name'             => 'Produto Janela ' . uniqid(),
        'slug'             => 'produto-janela-' . uniqid(),
        'category'         => 'peptideos',
        'is_infinity'      => 'no',
        'price_unit_cents' => 5000,
        'box_qty'          => 10,
        'stock'            => 10,
    ]);
    $model->save();
}
```

Casos (um método por linha; datas relativas com
`date('Y-m-d H:i:s', strtotime('-1 hour'))` etc.):

1. defaults do seed (tudo `''`) + produto vendível → `open === true`, `reason === null`
2. `sales_override='closed'` → `open === false`, `reason === 'override'`, `reopens_at === null`
3. `sales_override='closed'` vence janela corrente + estoque ok → fechado
4. `sales_override='open'` com janela já encerrada E estoque zerado → `open === true`
5. janela corrente (`start` = -1h, `end` = +1h) + estoque ok → aberto
6. janela futura (`start` = +1h) → fechado, `reason === 'window'`, `reopens_at === start`
7. janela encerrada (`end` = -1h) → fechado, `reason === 'window'`, `reopens_at === null`
8. só `end` futuro (start `''`) + estoque ok → aberto
9. estoque zerado (`drainAllStock()`) dentro da janela → fechado, `reason === 'stock'`
10. valores corrompidos (`sales_override='talvez'`, `start='banana'`) + estoque ok → aberto (fail-open)

Espelhe o arquivo em `manager/tests/SalesWindowTest.php` (precedente:
`DOLModelQueryHelpersTest.php` existe nos dois; `tests/` está fora do sync
guard, cópia idêntica é bem-vinda mas não obrigatória byte a byte).

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpunit --filter SalesWindowTest`
→ 10 testes OK; idem no manager.

### Step 4: Gate no front controller do site

Em `site/public_html/index.php`, logo APÓS o bloco da CSP (linha 49) e ANTES de
`$params` (linha 52), insira:

```php
// Janela de vendas (plano 037): fora da janela / sem estoque / override
// 'closed', as rotas de compra caem na pagina "vendas encerradas". Pos-venda
// segue vivo — /pagamento, /pedido e /acompanhar-pedido acessiveis (PIX
// pendente) e /webhook/pix recebendo confirmacoes do PSP. Manager nao passa
// por aqui. Fail-open: SalesWindow devolve aberto em erro de DB.
$salesPath = (string) (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/");
if (!preg_match("#^/(webhook/pix/|pagamento/|pedido/|acompanhar-pedido)#", $salesPath)) {
	$salesStatus = SalesWindow::status();
	if (!$salesStatus["open"]) {
		$noindex = true;
		include(constant("cRootServer") . "ui/common/head.php");
		include(constant("cRootServer") . "ui/page/sales_closed.php");
		exit;
	}
}
```

(Indentação com TAB — o arquivo usa tabs. HTTP 200 é intencional — ver
"Semântica". O `exit` é seguro: nenhuma escrita aconteceu, o rollback de
segurança do `localPDO::__destruct()` é no-op.)

Repare: o allowlist casa por **prefixo** exatamente os 4 grupos de rota
decididos. `GET/POST /carrinho`, `GET /produto/*`, `GET/POST /checkout*` e a
home caem todos no gate — inclusive o POST do checkout (bloqueio server-side,
não só visual).

**Verify**:
- `php -l site/public_html/index.php` → sem erros
- `cd site && php app/inc/lib/vendor/bin/phpunit` → suíte igual à baseline
  (o gate não pode quebrar nenhum teste: testes não passam pelo index.php)
- Com a stack de pé, smoke manual:
  `docker exec infinnityimportacao mysql -u<user> -p<pass> <db> -e "UPDATE settings SET svalue='closed' WHERE skey='sales_override'"`
  (credenciais: ver `site/app/inc/kernel.php` local — NÃO copiar valores para
  lugar nenhum) → `curl -s http://infinnityimportacao.local/ | grep -ci "encerrad"` → ≥1;
  `curl -s -o /dev/null -w '%{http_code}' .../acompanhar-pedido` → `200` com a
  tela normal; reverta para `''` → home volta à vitrine.
  Se não conseguir rodar o smoke (stack fora), registre isso no relato final.

### Step 5: Página "vendas encerradas" (INVOCAR frontend-design ANTES)

**Invoque a skill `frontend-design:frontend-design` via Skill tool AGORA** e
siga a orientação dela para o design. Depois crie
`site/public_html/ui/page/sales_closed.php`.

Contrato de conteúdo (prevalece sobre a skill):

- **Tom**: vendas encerradas / próximo período de vendas — NUNCA "manutenção",
  "em obras", "voltamos já" ou qualquer frame de site quebrado. É pausa
  planejada entre ciclos de venda.
- Página standalone: `head.php` já foi incluído pelo gate (ela deve abrir
  `<body ...>` e fechar `</body></html>` ela mesma — o header/footer de
  navegação NÃO são usados; sem carrinho, sem menu).
- Marca: `constant('cStoreName')` (não `cTitle`, que é o nome interno).
- A view recebe `$salesStatus` (`['open' => false, 'reopens_at' => ?string, 'reason' => ?string]`):
  - `reason === 'stock'` → mensagem tipo "Estoque esgotado! As vendas deste
    período foram encerradas."
  - caso contrário → "As vendas estão encerradas no momento."
  - `reopens_at` presente (`'Y-m-d H:i:s'`) → exiba a reabertura em pt-BR
    (`d/m/Y` + `H\hi`, ex.: "26/07/2026 às 14h30" — formate com `DateTime`,
    sem `IntlDateFormatter`, que pode não estar habilitado). Ausente →
    "avisaremos quando um novo período de vendas abrir" (sem data inventada).
- **UM único botão**: `https://wa.me/<?php echo htmlspecialchars(constant('whatsapp_number'), ENT_QUOTES, 'UTF-8'); ?>`,
  `target="_blank" rel="noopener"`, rótulo "Falar com o Atendimento" (padrão
  do exemplar `footer.php:11`). Nenhum outro botão/CTA.
- Permitido: um link textual discreto para `/acompanhar-pedido` ("Já comprou?
  Acompanhe seu pedido") — está no allowlist do gate.
- Tema claro, tokens/classes de `assets/css/main.css` (Bootstrap 5.3
  disponível). CSS inline em `<style>` é permitido pela CSP (`'unsafe-inline'`
  em style-src); `<script>` precisaria de `nonce="<?php echo $GLOBALS['cspNonce']; ?>"`
  — evite JS, a página não precisa.
- Todo output dinâmico com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

**Verify**:
- `php -l site/public_html/ui/page/sales_closed.php` → sem erros
- `grep -ci "manuten" site/public_html/ui/page/sales_closed.php` → `0`
- Smoke do Step 4 com override fechado: `curl -s http://infinnityimportacao.local/ | grep -c "wa.me"` → `1`;
  com `sales_window_start_at` futuro setado, o HTML contém a data formatada.

### Step 6: Manager — card + action em /config

Três mudanças:

**(a)** `config_controller::index()` — dentro do `try` principal (após o bloco
dos gateways, antes do `catch` da linha 104), carregue:

```php
$salesSettings = [
    'sales_override'        => '',
    'sales_window_start_at' => '',
    'sales_window_end_at'   => '',
];
$settingsModel = new settings_model();
$stmt = $settingsModel->select(
    [" skey ", " svalue "],
    "WHERE active = 'yes' AND skey IN (?, ?, ?)",
    array_keys($salesSettings)
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $salesSettings[$row['skey']] = (string) $row['svalue'];
}
$salesStatus = SalesWindow::status();
```

Inicialize `$salesSettings`/`$salesStatus` com esses defaults ANTES do `try`
(padrão do método: falha não derruba o resto da tela; default de status:
`['open' => true, 'reopens_at' => null, 'reason' => null]`).

**(b)** `config_controller::action()` — nova ramificação no dispatch
(linha ~136): `} elseif ($action === 'janela') { $this->saveSalesWindow($post, $adminId, $config_url); }`.
Novo método privado, no padrão de `saveGateway`:

```php
private function saveSalesWindow(array $post, int $adminId, string $config_url): never
{
    $override = (string)($post['sales_override'] ?? '');
    if (!in_array($override, ['', 'open', 'closed'], true)) {
        $override = '';
    }
    $start = $this->normalizeLocalDatetime((string)($post['sales_window_start_at'] ?? ''));
    $end   = $this->normalizeLocalDatetime((string)($post['sales_window_end_at'] ?? ''));

    if ($start === null || $end === null) {
        $_SESSION["messages_app"]["danger"] = ["Data/hora inválida na janela de vendas."];
        basic_redir($config_url);
    }
    if ($start !== '' && $end !== '' && $end <= $start) {
        $_SESSION["messages_app"]["danger"] = ["O fim da janela deve ser depois do início."];
        basic_redir($config_url);
    }

    $rollback = false;
    try {
        $model = new settings_model();
        foreach ([
            'sales_override'        => $override,
            'sales_window_start_at' => $start,
            'sales_window_end_at'   => $end,
        ] as $key => $value) {
            // Upsert em 2 passos: INSERT IGNORE cobre base sem o seed 044;
            // UPDATE grava valor, reativa soft-delete (UNIQUE de skey abrange
            // linhas removidas) e carimba modified_at em PHP (clock skew).
            $model->execute_raw_prepared(
                "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, ?, 'yes', ?, '')",
                [date('Y-m-d H:i:s'), $adminId, $key]
            );
            $model->execute_raw_prepared(
                "UPDATE settings SET svalue = ?, active = 'yes', modified_at = ?, modified_by = ? WHERE skey = ?",
                [$value, date('Y-m-d H:i:s'), $adminId, $key]
            );
        }
        $_SESSION["messages_app"]["success"] = ["Janela de vendas atualizada."];
    } catch (RuntimeException $e) {
        $rollback = true;
        Logger::getInstance()->error("config_action(janela) failed", ["error" => $e->getMessage()]);
        $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar a janela de vendas."];
    }

    basic_redir($config_url, rollback: $rollback);
}

/** '' => ''; 'Y-m-d\TH:i' (datetime-local) => 'Y-m-d H:i:00'; inválido => null. */
private function normalizeLocalDatetime(string $value): ?string
{
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if ($dt === false || $dt->format('Y-m-d\TH:i') !== $value) {
        return null;
    }
    return $dt->format('Y-m-d H:i:00');
}
```

**(c)** `manager/public_html/ui/page/config.php` — novo card "Janela de
Vendas" (sugestão: logo após o header da página, antes do row "Dados da
conta", linha ~41 — é a informação mais operacional da tela). Reuse a
orientação da skill frontend-design já invocada no Step 5, dentro do design
system do manager (`content-panel`, mesmos botões). Conteúdo:

- Defaults defensivos no topo da view (padrão das linhas 7-20):
  `$salesSettings = $salesSettings ?? ['sales_override' => '', 'sales_window_start_at' => '', 'sales_window_end_at' => ''];`
  `$salesStatus = $salesStatus ?? ['open' => true, 'reopens_at' => null, 'reason' => null];`
- Badge de estado atual a partir de `$salesStatus`: "Vendas ABERTAS" /
  "Vendas FECHADAS" + motivo legível (`override` → "fechado manualmente";
  `window` → "fora da janela"; `stock` → "estoque esgotado").
- Form POST para `$GLOBALS['config_url']` com `_csrf_token` + `action=janela`:
  - radio/select `name="sales_override"`: `''` "Automático (janela +
    estoque)", `'open'` "Forçar vendas abertas", `'closed'` "Forçar vendas
    fechadas";
  - `input type="datetime-local" name="sales_window_start_at"` e
    `sales_window_end_at`; prefill:
    `value="<?php echo htmlspecialchars(str_replace(' ', 'T', substr($salesSettings['sales_window_start_at'], 0, 16)), ENT_QUOTES, 'UTF-8'); ?>"`;
  - texto de ajuda: início futuro = data de reabertura exibida ao cliente;
    campos vazios = sem restrição de data; estoque zerado fecha as vendas
    automaticamente (a menos que "Forçar vendas abertas");
  - botão submit no padrão dos outros cards.

**Verify**:
- `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → OK
- `cd manager && php app/inc/lib/vendor/bin/phpunit` → baseline mantida
  (atenção a `ConfigViewTest`: se ele incluir a view, os defaults defensivos
  do item (c) evitam notice de variável indefinida)
- Smoke manual (stack de pé): logar no manager, `/config`, "Forçar vendas
  fechadas", salvar → site mostra vendas encerradas; "Automático" com janela
  corrente e estoque ok → vitrine volta.

### Step 7: Teste da action do manager

Crie `manager/tests/ConfigSalesWindowActionTest.php` extends `DBTestCase`,
modelado em `manager/tests/ConfigActionTest.php` (mesma técnica: reproduz a
sequência de escrita de `saveSalesWindow` — não chama `action()` porque
`basic_redir()` dá `exit()`):

1. upsert dos 3 skeys grava `svalue` e reativa `active='yes'`
   (pré-condição: marque um dos skeys como `active='no'` via
   `execute_raw_prepared` e confirme que o upsert ressuscita);
2. normalização: `'2026-07-25T14:30'` → `'2026-07-25 14:30:00'` (replique a
   chamada `DateTime::createFromFormat('Y-m-d\TH:i', ...)` do controller);
3. validação de janela invertida (`end <= start`) — replique o `if` e afirme
   que a escrita não acontece nesse caso;
4. override inválido (`'talvez'`) é salvo como `''`.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter ConfigSalesWindowActionTest` → OK.

### Step 8: Verificação final

**Verify** (todos):
1. `bin/check-shared-sync.sh` → exit 0
2. `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → OK
3. `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → OK
4. `cd site && php app/inc/lib/vendor/bin/phpunit` → sem regressão vs baseline
5. `cd manager && php app/inc/lib/vendor/bin/phpunit` → sem regressão vs baseline
6. `git status` → só arquivos do "In scope"
7. Atualizar linha de status em `plans/README.md` (Lote 7)

## Test plan

- `site/tests/SalesWindowTest.php` + espelho em `manager/tests/` — 10 casos
  da semântica (Step 3), padrão `OrderPricingTest` (inclui fixture de produto
  e dreno de estoque dentro da transação de teste).
- `manager/tests/ConfigSalesWindowActionTest.php` — escrita/normalização/
  validação/override (Step 7), padrão `ConfigActionTest`.
- Suítes completas + PHPStan nos dois ambientes = gate de regressão.
- Smoke HTTP manual (vitrine ↔ vendas encerradas) quando a stack estiver de
  pé — best-effort, registrado no relato.

## Done criteria

Machine-checkable. TODOS devem valer:

- [ ] `migrations/044_seed_sales_window_settings.sql` existe; runner roda 2x sem erro
- [ ] `diff site/app/inc/lib/SalesWindow.php manager/app/inc/lib/SalesWindow.php` → vazio
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] PHPStan site + manager → `[OK] No errors`
- [ ] PHPUnit site + manager → sem falhas novas vs baseline do Step 0; ≥14 testes novos passam
- [ ] `grep -n "SalesWindow::status" site/public_html/index.php` → 1 ocorrência antes de `new Dispatcher`
- [ ] `grep -n "webhook/pix/" site/public_html/index.php` → aparece no allowlist do gate E na rota
- [ ] `grep -c "wa.me" site/public_html/ui/page/sales_closed.php` → ≥1; um único CTA
- [ ] `grep -rci "manuten" site/public_html/ui/page/sales_closed.php site/app/inc/lib/SalesWindow.php` → 0 em ambos (o frame é "vendas", não "manutenção")
- [ ] `grep -n "janela" manager/app/inc/controller/config_controller.php` → dispatch + saveSalesWindow presentes
- [ ] `git status` → nenhum arquivo fora do "In scope"
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

Pare e reporte (não improvise) se:

- O drift check inicial mostrar mudanças em arquivo in-scope, ou os excertos de
  "Current state" não baterem com o código vivo.
- A skill `frontend-design:frontend-design` não estiver disponível no seu
  ambiente (exigência explícita do dono — não desenhe a página sem ela).
- `settings` não tiver as colunas do DDL citado, ou `products` não tiver a
  coluna `stock` (schema divergiu).
- `DOLModel::select()` rejeitar a query de estoque do Step 2 e você não
  conseguir reproduzi-la com os padrões citados (`OrderPricing` como exemplar).
- `bin/check-shared-sync.sh` falhar por arquivos que você NÃO criou (drift
  pré-existente entre manager/ e site/ — não tente "consertar" copiando).
- A suíte baseline do Step 0 tiver falhas e você não conseguir distinguir
  regressão sua de falha pré-existente.
- Qualquer verificação falhar 2x após tentativa razoável de correção.
- Precisar tocar em arquivo out-of-scope (em especial `Dispatcher.php`,
  `CommonFunctions.php` ou `checkout_controller.php`).

## Maintenance notes

- **Toda rota nova no site** nasce bloqueada pelo gate, a menos que seja
  adicionada ao allowlist do `index.php` — revisar o allowlist é item de
  checklist para futuras rotas (ex.: um futuro endpoint de status público).
- O gate roda **até duas queries por request** no site (settings + estoque; a
  de estoque só quando override vazio e dentro da janela). Se virar gargalo, o
  caminho natural é cachear via `RedisCache` (fail-open já existente) —
  deliberadamente fora deste plano (tráfego atual não justifica).
- **Comportamento novo desde o deploy**: estoque todo zerado fecha as vendas
  automaticamente, mesmo sem janela configurada. Se isso surpreender o dono em
  produção, o escape imediato é "Forçar vendas abertas" no /config.
- Revisor deve escrutinar: (1) o allowlist do gate — um typo no regex derruba
  o pós-venda ou deixa o checkout aberto; (2) o `exit` antes do dispatcher —
  precisa vir depois do `require main.php` (kernel/DB/autoload) e depois da
  CSP; (3) datas comparadas só em PHP (clock skew do MySQL ~3h documentado);
  (4) a precedência override > janela > estoque, exatamente na ordem do
  contrato.
- Follow-ups deferidos: banner "novo período de vendas em DD/MM" na vitrine
  antes da janela abrir; contagem regressiva; mensagem customizável; cache
  Redis do status; e-mail de aviso de reabertura. Nenhum foi pedido.
- CI usa `kernel.php.example` (segredos vazios) — este plano não depende de
  segredo nenhum, os testes novos devem passar no CI como estão.
