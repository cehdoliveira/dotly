# 001 — Plano de dados (migrations)

**Commit base:** `47e8535` · **Depende de:** nada · **Bloqueia:** 002, 003, 004

## Por que isso importa

Nenhum model existe sem tabela. Todo o resto do projeto (carrinho, checkout, PIX,
manager) lê e escreve nestas 6 tabelas. Errar o schema aqui custa uma migration
corretiva depois — e o banco de produção já roda migrations por cron, então não há
"apagar e refazer".

## Contexto obrigatório (leia antes de escrever qualquer linha)

### Convenções do repositório

As migrations vivem em `migrations/`, são numeradas sequencialmente, **idempotentes**
(o runner registra em `migrations_log` e não reexecuta, mas um estado inconsistente pode
forçar reexecução — então a própria migration não pode explodir se rodar 2x), e são
executadas **uma transação por arquivo** por `site/cgi-bin/run_migrations.php`.

**Armadilha do runner:** o splitter de statements é ingênuo (`explode(';')`). **Nunca
escreva `;` dentro de uma string literal SQL** — quebra o arquivo em pedaços inválidos.
O comentário no topo de `migrations/006_add_unique_constraints.sql` documenta isso.

Últimas migrations existentes: `001` … `008_lock_seed_profiles.sql`. As novas começam em
**`009`**.

### Boilerplate padrão de toda tabela

Copiado literalmente de `migrations/003_create_table_profiles.sql` (linhas 3–17) — siga
exatamente, incluindo `ENGINE` e `COLLATE`:

```sql
CREATE TABLE IF NOT EXISTS `profiles` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    ...
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

Essas 7 colunas de auditoria **não são opcionais**: `DOLModel::save()` escreve
`created_at`/`created_by` no INSERT e `modified_at`/`modified_by` no UPDATE;
`DOLModel::remove()` escreve `active='no'`, `removed_at`, `removed_by`.
Uma tabela sem elas quebra o ORM em runtime.

**Soft-delete é universal.** Nunca `DELETE FROM`. `active = 'yes'/'no'`.

### Idiom de seed idempotente

De `003_create_table_profiles.sql` (linhas 21–24): `INSERT IGNORE` apoiado numa constraint
`UNIQUE`. Use isso para semear `payment_gateways`.

### Dinheiro

**Todo valor monetário é `INT UNSIGNED` em centavos**, sufixo `_cents`. Nunca `FLOAT`/
`DECIMAL` no PHP deste framework — não há camada de casting e `DOLModel::populate()`
passa o valor cru pro bind. Centavos elimina a classe inteira de bug de arredondamento.

## Escopo

**Em escopo:** criar 6 arquivos novos em `migrations/`.
**Fora de escopo — não toque:** `migrations/001`–`008` (já rodaram em produção), qualquer
arquivo PHP (os models são o plano 002/003), `docker/`, `bin/`, `.githooks/`.

## ⚠️ Pare e reporte antes de criar as migrations

Este plano descreve os arquivos. **Criar migration é uma condição de parada explícita do
projeto.** Apresente o DDL abaixo ao dono do repo e obtenha um "ok" antes de escrever os
arquivos. Se qualquer coluna abaixo for renomeada na revisão, atualize os planos 002/003
antes de codar — eles citam estes nomes literalmente.

## Passos

### Passo 1 — `migrations/009_create_table_products.sql`

```sql
CREATE TABLE IF NOT EXISTS `products` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `category` VARCHAR(60) NOT NULL DEFAULT '',
    `description` TEXT DEFAULT NULL,
    `dosage` VARCHAR(40) DEFAULT NULL,
    `purity_label` VARCHAR(20) DEFAULT NULL,
    `price_unit_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `price_box_cents` INT UNSIGNED DEFAULT NULL,
    `box_qty` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `stock` INT NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_products_active_sort` (`active`, `sort_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

Notas de desenho, para você entender e não "melhorar":

- `category` é **VARCHAR, não tabela**. Os chips de filtro da home (`GH SECRETAGOGO`,
  `NOOTRÓPICO`, …) saem de um `SELECT DISTINCT category`. Uma tabela `categories` com CRUD
  próprio é escopo que ninguém pediu.
- `price_box_cents` **NULL = produto não vende em caixa** — o card esconde o botão
  "Caixa ×10". Não use `0` como sentinela.
- `box_qty` alimenta o rótulo "Caixa ×10" e a baixa de estoque (1 caixa = `box_qty`
  unidades). `stock` é sempre **em unidades**.
- `slug` é `UNIQUE` e validado por `valid_slug()` (já existe em `CommonFunctions.php`,
  linha 852).

### Passo 2 — `migrations/010_create_table_product_images.sql`

Boilerplate + :

```sql
    `products_id` INT NOT NULL,
    `path` VARCHAR(255) NOT NULL,
    `is_cover` ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_product_images_product` (`products_id`, `active`)
```

- `products_id` (plural + `_id`) **não é estilo livre**: `DOLModel::join()` monta a chave
  como `<table>_id`. Renomear quebra o attach.
- `path` guarda o retorno de `handle_upload()` (caminho relativo), nunca o binário.
- **Sem FOREIGN KEY.** Nenhuma tabela existente do repo usa FK (confira `002`–`005`); e com
  soft-delete universal um `ON DELETE CASCADE` nunca dispararia mesmo. Mantenha a
  consistência do repo.

### Passo 3 — `migrations/011_create_table_payment_gateways.sql`

Boilerplate + :

```sql
    `name` VARCHAR(40) NOT NULL,
    `slug` VARCHAR(40) NOT NULL UNIQUE,
    `mode` ENUM('qr', 'redirect') NOT NULL DEFAULT 'qr',
    `enabled` ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    `monthly_limit_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`)
```

Seed (idempotente via `INSERT IGNORE` + `slug UNIQUE`, idiom do `003`):

```sql
INSERT IGNORE INTO `payment_gateways`
    (`created_at`, `created_by`, `active`, `name`, `slug`, `mode`, `enabled`, `monthly_limit_cents`)
VALUES
    (NOW(), 0, 'yes', 'Mercado Pago', 'mercadopago', 'qr',       'no', 0),
    (NOW(), 0, 'yes', 'PagBank',      'pagbank',     'qr',       'no', 0),
    (NOW(), 0, 'yes', 'InfinitePay',  'infinitepay', 'redirect', 'no', 0);
```

- `enabled = 'no'` no seed é deliberado: **fail-closed**. Ninguém cobra por um gateway
  sem credencial configurada. O admin liga no manager depois de preencher o `kernel.php`.
- `mode` existe porque InfinitePay não tem API de PIX inline — ele devolve um link de
  checkout hospedado. A tela de pagamento usa este campo pra decidir entre "mostrar QR" e
  "botão que leva ao pagamento". Ver plano 002.
- `monthly_limit_cents` é `BIGINT` (um limite mensal pode passar de R$ 21 milhões, o teto
  do `INT UNSIGNED` em centavos).
- **Credencial não entra aqui.** Token/secret ficam em `kernel.php` (gitignored). O banco
  guarda só política (limite, liga/desliga).

### Passo 4 — `migrations/012_create_table_orders.sql`

Boilerplate + :

```sql
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `status` ENUM('aguardando_pagamento', 'pago', 'cancelado', 'expirado') NOT NULL DEFAULT 'aguardando_pagamento',
    `customer_name` VARCHAR(255) NOT NULL,
    `customer_mail` VARCHAR(255) NOT NULL,
    `customer_phone` VARCHAR(20) NOT NULL,
    `ship_zip` VARCHAR(9) NOT NULL,
    `ship_street` VARCHAR(255) NOT NULL,
    `ship_number` VARCHAR(20) NOT NULL,
    `ship_complement` VARCHAR(120) DEFAULT NULL,
    `ship_district` VARCHAR(120) NOT NULL,
    `ship_city` VARCHAR(120) NOT NULL,
    `ship_uf` CHAR(2) NOT NULL,
    `total_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `paid_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_orders_status_expires` (`status`, `expires_at`),
    KEY `idx_orders_created` (`created_at`)
```

- `token` é a **única credencial do comprador** (não há login). Vem de `random_token(16)`
  → 32 chars hex. `UNIQUE` garante colisão = erro de INSERT, não pedido sobrescrito.
- `idx_orders_status_expires` serve o job de expiração (`WHERE status =
  'aguardando_pagamento' AND expires_at < NOW()`). Sem ele o job faz full scan.
- `ship_uf` valida contra `$GLOBALS['ufbr_lists']` (já existe em `app/inc/lists.php`).
- **Não adicione** coluna de CPF agora. Ver item aberto #2 no `plans/README.md`.

### Passo 5 — `migrations/013_create_table_order_items.sql`

Boilerplate + :

```sql
    `orders_id` INT NOT NULL,
    `products_id` INT NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `variant` ENUM('unit', 'box') NOT NULL DEFAULT 'unit',
    `qty` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `line_total_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_order_items_order` (`orders_id`, `active`)
```

`product_name` e `unit_price_cents` são **snapshots deliberados**. O admin muda o preço
amanhã; o pedido de hoje precisa continuar mostrando o que foi cobrado. Não substitua por
um JOIN em `products` — isso é uma feature, não duplicação.

### Passo 6 — `migrations/014_create_table_pix_charges.sql`

Boilerplate + :

```sql
    `orders_id` INT NOT NULL,
    `payment_gateways_id` INT NOT NULL,
    `gateway_charge_id` VARCHAR(120) DEFAULT NULL,
    `status` ENUM('pendente', 'pago', 'expirado', 'erro') NOT NULL DEFAULT 'pendente',
    `qr_payload` TEXT DEFAULT NULL,
    `qr_image_base64` LONGTEXT DEFAULT NULL,
    `redirect_url` VARCHAR(500) DEFAULT NULL,
    `amount_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_pix_charge_gateway` (`payment_gateways_id`, `gateway_charge_id`),
    KEY `idx_pix_charges_order` (`orders_id`, `active`)
```

- `uq_pix_charge_gateway` é a **idempotência do webhook**: o mesmo evento reentregue não
  cria uma segunda cobrança. Gateways reentregam — MP e PagBank ambos fazem retry.
- `qr_payload` = copia-e-cola. `qr_image_base64` = PNG base64 devolvido pelo PSP — é o que
  nos livra de uma lib de QR Code. `redirect_url` só é preenchido no modo `redirect`
  (InfinitePay); nesse caso `qr_payload`/`qr_image_base64` ficam NULL.
- **Não crie coluna `raw_response`.** A resposta crua do PSP carrega dados do payer e
  eventualmente eco de credencial; guardá-la é passivo de LGPD sem ganho.

## Critérios de aceite (binários)

Rode do host, com o stack de pé:

```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
```

1. A saída lista `009` … `014` como `success`, sem nenhum `error`.
2. Rodar o **mesmo comando de novo** não produz nenhum `error` e nenhuma tabela duplicada
   (idempotência).
3. As 6 tabelas existem com o boilerplate completo:
   ```bash
   docker exec infinnityimportacao mysql -u user_infinnityimportacao -p db_infinnityimportacao \
     -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() \
         AND table_name IN ('products','product_images','payment_gateways','orders','order_items','pix_charges');"
   ```
   → devolve exatamente 6 linhas.
4. O seed é idempotente:
   ```bash
   docker exec infinnityimportacao mysql -u user_infinnityimportacao -p db_infinnityimportacao \
     -e "SELECT COUNT(*) FROM payment_gateways;"
   ```
   → `3`, e continua `3` depois de reexecutar o runner.
5. `bin/test.sh` continua passando (nenhum teste existente regride).

## Teste

Nenhum teste novo neste plano — não há PHP. A cobertura vem no plano 002, onde os
models leem estas tabelas (`site/tests/` seguindo o padrão de `site/tests/UsersModelTest.php`,
que estende `DBTestCase`).

## Manutenção

- Toda migration futura que mexer nestas tabelas continua a partir de `015`.
- Se um dia entrar um 4º gateway: `INSERT IGNORE` em `payment_gateways` numa migration
  nova, **nunca** editando a `011` (ela já rodou; não reexecuta).
- Ao revisar: rejeite qualquer `DELETE FROM`, qualquer coluna de dinheiro que não seja
  `INT`/`BIGINT` em centavos, e qualquer `;` dentro de literal SQL.

## Escape hatches — pare e reporte, não improvise

- Se `run_migrations.php` falhar com "Duplicate key name" ou similar, o banco já tem
  estado dessas tabelas. **Não** apague nada. Reporte o estado encontrado.
- Se o dono do repo pedir FOREIGN KEYs, pare: isso muda a convenção do schema inteiro e é
  decisão dele, não sua.
- Se qualquer coluna acima colidir com uma tabela já existente, pare e reporte.
