# Plan 011: Dashboard de vendas como tela inicial pós-login do manager

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- manager/public_html/index.php manager/app/inc/controller/site_controller.php manager/public_html/ui/page/dashboard.php manager/app/inc/model/orders_model.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW/MED
- **Depends on**: none obrigatório. **Soft**: 008 (breakdown de taxas) e 010
  (`stock_min`) enriquecem KPIs — sem eles, use só os dados que já existem.
- **Category**: direction
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

Hoje a tela inicial após o login no manager
(`site_controller::dashboard`, rota `/`) é a **gestão de usuários** — irrelevante
para operar uma loja. O pedido de produto é: a home pós-login deve ser um
**dashboard de e-commerce focado em vendas**. Este plano cria um dashboard de
KPIs de vendas a partir dos dados **já modelados** (`orders`, `order_items`,
`products`), aponta `/` para ele, e mantém a gestão de usuários acessível em
`/usuarios` (rota que já existe).

## KPIs propostos (todos derivam de dados existentes)

Fonte: `orders` (status, `total_cents`, `created_at`, `paid_at`),
`order_items` (`qty`, `product_name`, `line_total_cents`), `products` (`stock`).

1. **Faturamento pago (mês corrente)**: `SUM(total_cents)` de `orders` com
   `status='pago'` e `paid_at` no mês.
2. **Pedidos pagos (mês)** e **ticket médio** (faturamento ÷ nº pedidos pagos).
3. **Aguardando pagamento agora**: count de `status='aguardando_pagamento'` e
   `expires_at > NOW()`.
4. **Pedidos por status (30d)**: pago / aguardando / expirado / cancelado.
5. **Top 5 produtos por quantidade vendida (30d)**: `order_items` join `orders`
   pago, `GROUP BY products_id`, `SUM(qty)`.
6. **Últimos 10 pedidos**: id, cliente, total, status, data.
7. **Produtos acabando**: count de `products` ativos com estoque baixo. **[requer
   Plano 010]** — se `stock_min` não existir ainda, use um limiar fixo
   `stock <= 5` e marque com um TODO no código apontando pra `stock_min`.

> **Métrica que exige dado ainda não modelado — marcada explicitamente**:
> - "Faturamento líquido de taxas" e "receita por tipo de taxa" exigem o breakdown
>   do **Plano 008** (`subtotal_cents`, `fee_*`). **[requer Plano 008]** — inclua
>   só se as colunas existirem; senão, deixe de fora.
> - "Vendas por categoria" fica melhor com o **Plano 007** (juntando por
>   `products_categories`); sem ele, agrupar por `products.category` string
>   (rótulo denormalizado). **[requer Plano 007 p/ versão boa]**

## Fatos arquiteturais (LEGGO)

- Framework custom. Rotas em `manager/public_html/index.php` via
  `$dispatcher->add_route(method, regex, "controller:metodo", $authGuard, $params)`.
- Controller inclui views manualmente: `head.php` → `header.php` → `ui/page/X.php`
  → `footer.php` → `foot.php`. Passa dados por variáveis locais que a view lê.
- ORM = SQL cru via `DOLModel::execute_raw_prepared("... ?", [$vals])`.
- `manager/` mantém login. `$authGuard = fn() => auth_controller::check_login()`.
- Escape todo output com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

## Current state

- **Rotas** (`manager/public_html/index.php`):
  ```php
  $dispatcher->add_route("GET",  "/?",     "site_controller:dashboard", $authGuard, $params);
  $dispatcher->add_route("GET",  "/admin", "site_controller:dashboard", $authGuard, $params);
  $dispatcher->add_route("GET",  "/usuarios", "site_controller:dashboard",    $authGuard, $params);
  $dispatcher->add_route("POST", "/usuarios", "site_controller:users_action", $authGuard, $params);
  ```
  Ou seja, `/`, `/admin` e `/usuarios` renderizam a MESMA tela de usuários hoje.
- **`site_controller::dashboard`** (`manager/app/inc/controller/site_controller.php:4-53`)
  monta contagem de usuários e inclui `ui/page/dashboard.php`. Usa
  `$alpineControllers = ['dashboard']`.
- **View** `manager/public_html/ui/page/dashboard.php` tem uma **sidebar** de nav
  (linhas 11-56) — os links "Usuários / E-mails / Perfis / Produtos / Pedidos /
  Gateways" — e um `<main>` que hoje mostra "Gerenciar Usuários" (linha 67+). A
  sidebar deve ser **preservada** e reaproveitada.
- **`orders_model`, `order_items_model`, `products_model`** existem nas 2 cópias.
- **Manager URLs** (`manager/app/inc/urls.php`): `$users_url` já existe (= `usuarios`),
  `$orders_url`, `$order_url`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0 |
| PHPUnit manager | `docker exec -w /var/www/infinnityimportacao/manager -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |

## Scope

**In scope**:
- `manager/app/inc/controller/site_controller.php` (adicionar método `salesDashboard()`)
- `manager/public_html/index.php` (apontar `/` e `/admin` para o novo método; manter `/usuarios` no antigo)
- `manager/public_html/ui/page/sales_dashboard.php` (criar view do dashboard de vendas)
- `manager/public_html/ui/page/dashboard.php` (marcar "Usuários" como link comum na sidebar, adicionar item ativo "Início/Vendas"; se a sidebar for extraída, ver Step 2)
- `manager/public_html/assets/css/dashboard.css` (estilos dos cards de KPI — opcional, reusar tokens)
- `manager/tests/SalesDashboardTest.php` (criar — testar os métodos de agregação)

**Out of scope**:
- `site/` (nada — é dashboard do manager). Exceto se você extrair a sidebar para
  um partial compartilhado, o que NÃO é necessário; prefira manter simples.
- Não mexa em `users_action` nem na tela de usuários em si (só muda a rota que a
  serve, de `/` para `/usuarios`).
- Não crie migrations. Este plano usa só dados existentes.

## Git workflow

- Branch: `advisor/011-dashboard-vendas`
- Commits PT-BR Conventional Commits. Sem push/PR sem ordem.

## Steps

### Step 1: Método `salesDashboard()` no `site_controller`

Em `manager/app/inc/controller/site_controller.php`, adicione um método
`salesDashboard(array $info): void` que roda as queries de KPI via
`execute_raw_prepared` e passa os resultados por variáveis locais para a view.
Extraia cada agregação em um método privado testável (padrão do repo — ver
`checkout_controller::lockAndValidateCart` extraído p/ teste). Ex.:
```php
public function salesDashboard(array $info): void
{
    if (empty($_SESSION['_csrf_token'])) { $_SESSION['_csrf_token'] = random_token(); }

    $kpis      = $this->salesKpis();       // faturamento/pedidos/ticket/aguardando
    $byStatus  = $this->ordersByStatus();  // 30d
    $topProd   = $this->topProducts();     // top 5, 30d
    $recent    = $this->recentOrders();    // ultimos 10
    $lowStock  = $this->lowStockCount();   // acabando

    $alpineControllers = ['dashboard'];

    include(constant("cRootServer") . "ui/common/head.php");
    include(constant("cRootServer") . "ui/common/header.php");
    include(constant("cRootServer") . "ui/page/sales_dashboard.php");
    include(constant("cRootServer") . "ui/common/footer.php");
    include(constant("cRootServer") . "ui/common/foot.php");
}
```
Cada método privado usa `new orders_model()` / etc. + `execute_raw_prepared`. Todo
input é interno (datas via `NOW()`/`date()`), então há pouco parâmetro de usuário;
ainda assim use bound params onde houver. Envolva as queries em `try/catch
(RuntimeException)` devolvendo zeros (padrão de `site_controller::dashboard`).

`lowStockCount()`: se a coluna `products.stock_min` existir (Plano 010 mergeado),
`WHERE stock_min>0 AND stock<=stock_min`; senão `WHERE stock<=5` com um comentário
`// TODO: usar stock_min quando o Plano 010 mergear`.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

### Step 2: Rotas — `/` e `/admin` → dashboard de vendas; `/usuarios` continua nos usuários

Em `manager/public_html/index.php`, troque o alvo de `/` e `/admin`:
```php
$dispatcher->add_route("GET",  "/?",     "site_controller:salesDashboard", $authGuard, $params);
$dispatcher->add_route("GET",  "/admin", "site_controller:salesDashboard", $authGuard, $params);
```
Deixe `/usuarios` (GET e POST) **inalterado** apontando para `site_controller:dashboard`
/ `users_action` — a gestão de usuários passa a viver só em `/usuarios`.

**Verify**: logar no manager → cai no dashboard de vendas (não mais na lista de
usuários). `/usuarios` → ainda mostra a gestão de usuários.

### Step 3: View `sales_dashboard.php` (reusa a sidebar)

Crie `manager/public_html/ui/page/sales_dashboard.php`. **Reaproveite a mesma
sidebar** de `dashboard.php` (copie o bloco `<nav class="manager-sidebar">`), mas
adicione um item de topo "Início" / "Vendas" (link para `$home_url`, marcado
`active`) e transforme "Usuários" num link normal para `$GLOBALS['users_url']`.
No `<main>`, renderize: linha de cards de KPI (faturamento mês, pedidos pagos,
ticket médio, aguardando), grid de status 30d, top produtos, tabela dos últimos 10
pedidos (link para `$order_url`), e o card "produtos acabando". Escape todos os
valores; formate `*_cents` dividindo por 100 (padrão do repo).

> Para gráficos, **não adicione dependências novas** (o repo evita libs novas —
> ver `plans/README.md`). Cards numéricos + barras CSS bastam. Se quiser sparkline,
> use SVG inline.

**Verify**: subir o manager, abrir `/` → dashboard renderiza com os cards e as
tabelas; valores batem com queries manuais no banco de teste.

### Step 4 (opcional): estilos de KPI

Em `manager/public_html/assets/css/dashboard.css`, adicione classes para os cards
de KPI usando os tokens existentes (`var(--surface)`, `var(--accent)`,
`var(--text-muted)` etc). Não hardcode cores.

**Verify**: cards com aparência consistente; nenhuma cor hex nova fora dos tokens.

## Test plan

- `manager/tests/SalesDashboardTest.php` (`DBTestCase`): insere pedidos de teste
  (pago/aguardando/expirado) + itens, chama os métodos de agregação e verifica:
  faturamento soma só os pagos do mês; ticket médio correto; top produtos ordenado
  por qty; `recentOrders` retorna ≤10 mais recentes; `lowStockCount` conta certo.
  Molde em `manager/tests/OrdersFilterTest.php` (existe — confirme com `ls manager/tests`).
- Verificação: PHPUnit manager verde incl. `SalesDashboardTest`.

## Done criteria

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `bin/check-shared-sync.sh` → exit 0 (nenhum shared tocado)
- [ ] Login no manager cai em `/` = dashboard de vendas (não a lista de usuários)
- [ ] `/usuarios` ainda serve a gestão de usuários (GET + ações POST)
- [ ] Nenhuma dependência nova (`git diff` não toca `composer.json`/lock)
- [ ] PHPUnit manager verde incl. `SalesDashboardTest`
- [ ] Nenhum arquivo fora do In-scope modificado
- [ ] `plans/README.md` atualizado

## STOP conditions

- As rotas em "Current state" não baterem (drift) — releia `index.php`.
- Alguma agregação precisar de coluna inexistente (ex.: você assumiu `paid_at` e
  ele não existe) — confira o schema real (`orders` tem `paid_at`, `status`,
  `total_cents`, `created_at`, `expires_at`) antes de escrever a query; se faltar
  algo, reporte.
- Tentação de adicionar Chart.js/ApexCharts ou qualquer lib — NÃO adicione, reporte
  se achar necessário.

## Maintenance notes

- KPIs enriquecem quando **008** (breakdown de taxas → faturamento líquido),
  **010** (`stock_min` → "acabando" preciso) e **007** (`categories_id` → vendas por
  categoria) mergearem. O código já deixa TODOs nos pontos.
- A gestão de usuários migrou de `/` para `/usuarios`; qualquer link/bookmark
  antigo para `/` agora abre o dashboard de vendas — comportamento desejado.
- Revisor deve conferir que as agregações filtram por `active='yes'` e por status
  corretos (faturamento só de `pago`).
