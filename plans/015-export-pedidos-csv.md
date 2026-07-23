# Plan 015: Exportar a lista de pedidos (com filtros) como CSV Excel-compatível

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in "STOP conditions" occurs, stop and report. When
> done, update this plan's status row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fdb4216..HEAD -- manager/app/inc/controller/orders_controller.php manager/public_html/ui/page/orders.php manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php manager/public_html/index.php manager/app/inc/urls.php`
> On any change, compare the "Current state" excerpts to live code before proceeding.

## Status

- **Priority**: P1
- **Effort**: S/M
- **Risk**: LOW
- **Depends on**: none (independente de 014)
- **Category**: direction (feature — Fase 2 item #2)
- **Planned at**: commit `fdb4216`, 2026-07-16

## Why this matters

A tela de pedidos (`/pedidos`) é só leitura, com filtro por status e paginação de 25.
O operador precisa exportar **o que está listado, com os filtros aplicados**, para
abrir no Excel. Não há lib de planilha no projeto (só phpmailer/phpstan/phpunit), e
adicionar uma é proibido sem aprovação — então geramos CSV nativo compatível com Excel
pt-BR (separador `;` + BOM UTF-8). Já existe o helper `array_to_csv()`; ele só não emite
BOM ainda.

## Current state

- **`orders_controller::index`** (`manager/app/inc/controller/orders_controller.php:11-58`)
  monta o filtro **inline** — hoje só status + paginação:
  ```php
  private const VALID_STATUSES = ['aguardando_pagamento', 'pago', 'cancelado', 'expirado'];
  // ...
  $statusParam = trim($info['get']['status'] ?? '');
  $status      = in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;
  // ...
  if ($status !== null) {
      $model->set_filter([" active = 'yes' ", " status = ? "], [$status]);
  } else {
      $model->set_filter([" active = 'yes' "]);
  }
  $model->set_order([" created_at DESC "]);
  $model->set_paginate([$offset, $perPage]);
  ```
  O filtro **não** está extraído em método reutilizável. Se a export duplicar essa
  lógica, as duas divergem quando alguém mexer no filtro. → Vamos extrair um helper.

- **Helper de CSV já existe** — `array_to_csv(array $data, string $filename, ?array $headers): never`
  em `manager/app/inc/lib/CommonFunctions.php` (por volta da linha 775), **byte-idêntico**
  em `site/app/inc/lib/CommonFunctions.php`:
  ```php
  function array_to_csv(array $data, string $filename = 'export.csv', ?array $headers = null): never
  {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');
    $output = fopen('php://output', 'w');
    if (empty($data)) { fclose($output); exit(); }
    if ($headers === null) { $headers = array_keys(reset($data)); }
    fputcsv($output, $headers, ';', '"', '\\');
    foreach ($data as $row) {
      $csvRow = [];
      foreach ($headers as $key) { $csvRow[] = csv_sanitize_cell($row[$key] ?? ''); }
      fputcsv($output, $csvRow, ';', '"', '\\');
    }
    fclose($output);
    exit();
  }
  ```
  Já usa `;` e `csv_sanitize_cell` (proteção contra CSV injection). **Falta o BOM UTF-8**
  (`\xEF\xBB\xBF`) — sem ele o Excel pt-BR lê acentos errados.

- **Exemplar de uso** — export de usuários em `site_controller::users_action`
  (`manager/app/inc/controller/site_controller.php:268-277`):
  ```php
  if ($action === 'export-csv') {
      $model = new users_model();
      $model->set_field([...]);
      $model->set_filter([" idx > 0 "]);
      $model->set_order([" created_at DESC "]);
      $model->load_data();
      $headers = ['idx', 'name', 'mail', 'login', 'enabled', 'active', 'created_at', 'last_login'];
      array_to_csv($model->data, 'usuarios_' . date('Y-m-d') . '.csv', $headers);
  }
  ```
  (Note: `array_to_csv` termina em `exit()` — não retorna.)

- **`index.php` do manager** faz `ob_start()` global (por volta de `index.php:13`) e
  seta headers de segurança. Como `array_to_csv` seta os próprios headers e faz `exit()`,
  o buffer pendente não chega a ser enviado — mas por segurança a export deve **limpar o
  buffer** antes de emitir (`while (ob_get_level() > 0) { ob_end_clean(); }`). O exemplar
  de usuários funciona hoje sem isso; confirme durante o teste manual que o CSV baixa sem
  lixo no começo do arquivo.

- **View** `manager/public_html/ui/page/orders.php:81-91` tem o form GET de status.

## Commands you will need

| Purpose        | Command                                                     | Expected            |
|----------------|-------------------------------------------------------------|---------------------|
| PHPStan manager| `cd manager && php app/inc/lib/vendor/bin/phpstan analyse`  | `[OK] No errors`    |
| PHPStan site   | `cd site && php app/inc/lib/vendor/bin/phpstan analyse`     | `[OK] No errors`    |
| PHPUnit manager| `cd manager && php app/inc/lib/vendor/bin/phpunit`          | all pass            |
| Shared-sync    | `bin/check-shared-sync.sh`                                  | exit 0              |

## Scope

**In scope**:
- `manager/app/inc/lib/CommonFunctions.php` — add BOM to `array_to_csv` (1 linha)
- `site/app/inc/lib/CommonFunctions.php` — **mesma** edição byte-idêntica (shared-sync!)
- `manager/app/inc/controller/orders_controller.php` — extract `buildFilter()`, add `export()`
- `manager/public_html/index.php` — add GET route `/pedidos/exportar`
- `manager/app/inc/urls.php` — add `$orders_export_url`
- `manager/public_html/ui/page/orders.php` — add "Exportar CSV" link preserving filters
- `manager/tests/OrdersExportTest.php` — **create**

**Out of scope**:
- Adicionar dependência no composer (proibido) — CSV nativo só.
- Mudar `csv_sanitize_cell` ou o separador `;`.
- Adicionar novos filtros (data/busca) à lista — este plano só espelha o filtro atual
  (status). Se filtros novos forem pedidos, entram em `buildFilter()` e a export os herda
  de graça.
- `site_controller::users_action` — não mexa no export de usuários (ele só ganha o BOM
  de brinde pela edição no helper compartilhado; isso é benéfico e esperado).

## Git workflow

- Branch: `advisor/015-export-pedidos`
- Conventional Commits PT-BR, ex.: `feat: exporta lista de pedidos filtrada em CSV`
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: Add UTF-8 BOM to `array_to_csv` (nas DUAS cópias)

Em `manager/app/inc/lib/CommonFunctions.php` e `site/app/inc/lib/CommonFunctions.php`,
dentro de `array_to_csv`, logo depois de `$output = fopen('php://output', 'w');` e
**antes** do `if (empty($data))`, adicione:
```php
  fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8: Excel pt-BR lê acentos corretamente
```
A edição tem que ser **idêntica** nos dois arquivos (guard `check-shared-sync.sh`).

**Verify**: `diff manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php`
→ sem diferenças; `bin/check-shared-sync.sh` → exit 0.

### Step 2: Extract the filter into a reusable method

Em `orders_controller.php`, extraia um método privado que devolve as condições +
params do `set_filter`, e faça `index()` usá-lo (comportamento idêntico ao de hoje):
```php
/** @return array{0: string[], 1: array<int,mixed>} [conditions, params] */
private function buildFilter(array $info): array
{
    $statusParam = trim($info['get']['status'] ?? '');
    $status      = in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;
    if ($status !== null) {
        return [[" active = 'yes' ", " status = ? "], [$status]];
    }
    return [[" active = 'yes' "], []];
}
```
Em `index()`, troque o bloco `if ($status !== null) { set_filter(...) } else {...}` por:
```php
[$conds, $params] = $this->buildFilter($info);
$model->set_filter($conds, $params);
```
O COUNT de `index()` pode continuar como está (ou reusar `$conds` se ficar trivial —
não é obrigatório). **Não mude o comportamento da listagem.**

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter OrdersFilterTest`
→ ainda verde (o teste de filtro existente prova que não regrediu).

### Step 3: Add the `export()` action

Novo método em `orders_controller`:
```php
public function export(array $info): void
{
    [$conds, $params] = $this->buildFilter($info);
    $model = new orders_model();
    $model->set_field([" idx ", " token ", " customer_name ", " customer_mail ",
        " customer_phone ", " status ", " total_cents ", " created_at ", " paid_at "]);
    $model->set_filter($conds, $params);
    $model->set_order([" created_at DESC "]);
    $model->load_data(false); // SEM paginação: exporta tudo que casa o filtro

    $rows = array_map(static function (array $o): array {
        return [
            'idx'            => $o['idx'],
            'token'          => $o['token'],
            'cliente'        => $o['customer_name'],
            'email'          => $o['customer_mail'],
            'telefone'       => $o['customer_phone'],
            'status'         => $o['status'],
            'total'          => number_format((int)$o['total_cents'] / 100, 2, ',', '.'),
            'criado_em'      => $o['created_at'],
            'pago_em'        => $o['paid_at'] ?? '',
        ];
    }, $model->data);

    while (ob_get_level() > 0) { ob_end_clean(); } // limpa o ob_start() global do index.php
    array_to_csv($rows, 'pedidos_' . date('Y-m-d') . '.csv',
        ['idx', 'token', 'cliente', 'email', 'telefone', 'status', 'total', 'criado_em', 'pago_em']);
}
```
`load_data(false)` sem `set_paginate` traz **todas** as linhas do filtro (a lista na tela
pagina 25, mas a export cobre o filtro inteiro — comportamento esperado de "exportar o
que está filtrado").

**Verify**: `php -l manager/app/inc/controller/orders_controller.php` → `No syntax errors`.

### Step 4: Route + URL constant

- `manager/app/inc/urls.php`:
  ```php
  $orders_export_url = sprintf("%s%s", constant("cFrontend"), "pedidos/exportar");
  ```
- `manager/public_html/index.php`, junto das rotas de `/pedidos`, GET atrás de `$authGuard`:
  ```php
  $dispatcher->add_route("GET", <pattern p/ "pedidos/exportar">, "orders_controller:export", $authGuard, $params);
  ```
  ⚠️ Registre `/pedidos/exportar` **antes** de `/pedidos/([0-9]+)` se os padrões puderem
  colidir — confirme lendo como `show` (`/pedidos/{id}`) está registrado e garanta que
  `exportar` (texto) não caia no regex numérico do `show`. Se `show` usa `([0-9]+)`,
  não há colisão (exportar não é numérico), mas registre antes por segurança.

**Verify**: `grep -n "orders_controller:export" manager/public_html/index.php` → 1 linha.

### Step 5: Add the export link to the orders view

Em `manager/public_html/ui/page/orders.php`, ao lado do botão "Filtrar" (linhas 81-91),
adicione um link que **preserva o status filtrado atual**:
```php
<a href="<?php echo $GLOBALS['orders_export_url'] . ($currentStatus ? '?status=' . urlencode($currentStatus) : ''); ?>"
   class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-download" aria-hidden="true"></i> Exportar CSV
</a>
```
(`$currentStatus` já é passado à view pelo controller — confirme o nome da variável lendo
o topo de `orders.php`; se for outro nome, use o que existe.)

**Verify**: teste manual — filtrar por "pago", clicar Exportar, o CSV baixado contém só
pedidos pagos; abrir no Excel mostra acentos corretos e colunas separadas por `;`.

### Step 6: Test — `OrdersExportTest.php`

Estende `DBTestCase`. Como `export()` faz `exit()` (via `array_to_csv`), **não** chame
`export()` direto. Em vez disso, teste:
- **`buildFilter()`** via `ReflectionMethod` (padrão de `CustomerUpsertTest`): sem status
  → `[[" active = 'yes' "], []]`; com `status=pago` → inclui `" status = ? "` e `['pago']`;
  status inválido → cai no ramo sem status.
- **Montagem das linhas do CSV**: crie 2 pedidos (1 pago, 1 aguardando), rode a mesma
  query que `export()` usa (mesmo `set_field`/`set_filter`), aplique o `array_map` e
  verifique que `total` sai formatado (`R$` fora, vírgula decimal) e que o filtro por
  status retorna só o pedido certo.
- **BOM**: um teste unitário simples chamando `array_to_csv` não dá (ele faz `exit()`);
  então valide o BOM por leitura: um teste que faz `assertStringContainsString("\xEF\xBB\xBF", ...)`
  não é viável. Em vez disso, cubra o BOM no aceite manual (Step 5) e documente.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter OrdersExportTest` → passa.

### Step 7: Verificação final

Rode toda a tabela "Commands you will need".

## Test plan

- Novo: `manager/tests/OrdersExportTest.php` (`DBTestCase`) — casos do Step 6.
- Padrão: `manager/tests/OrdersFilterTest.php` (filtro) + `CustomerUpsertTest.php` (Reflection).
- `OrdersFilterTest` existente **deve continuar verde** após a extração do `buildFilter()`.

## Done criteria

- [ ] `array_to_csv` emite BOM UTF-8 nas 2 cópias e `bin/check-shared-sync.sh` → exit 0
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit` → verde (OrdersFilterTest + OrdersExportTest)
- [ ] `GET /pedidos/exportar?status=pago` baixa CSV só com pedidos pagos; Excel pt-BR abre com acentos e colunas corretas
- [ ] Sem paginação na export (exporta todo o filtro, não só a página)
- [ ] `git status` sem arquivos fora do In scope
- [ ] Status row atualizado em `plans/README.md`

## STOP conditions

- O bloco de filtro em `index()` não bate com o excerpt de "Current state" (drift) —
  reconcilie antes de extrair o `buildFilter()`.
- Depois do BOM, o CSV começa com caracteres estranhos **visíveis** no Excel (BOM duplicado
  ou header enviado 2×): pare e verifique o `ob_end_clean()` e se algum outro ponto já
  emitiu BOM.
- A rota `/pedidos/exportar` cai no handler de `show` (`/pedidos/{id}`): pare e ajuste a
  ordem/regex das rotas.
- Uma verificação falha 2× após uma tentativa de conserto.

## Maintenance notes

- **`buildFilter()` é agora o único ponto de verdade do filtro de pedidos.** Qualquer
  filtro futuro (data, busca por cliente) deve ser adicionado lá — `index()` e `export()`
  herdam juntos, sem divergir. Deixe um comentário nesse sentido no método.
- O BOM foi adicionado ao helper compartilhado, então **o export de usuários
  (`users_action`) também passa a ter BOM** — benéfico (Excel pt-BR), sem regressão de dados.
- `array_to_csv` faz `exit()`: qualquer chamador novo precisa limpar o `ob_start()` global
  antes (padrão do `while (ob_get_level() > 0) ob_end_clean();`).
- Revisor: confira que a export usa o **mesmo** `buildFilter()` da listagem e que não há
  concatenação de input em SQL.
