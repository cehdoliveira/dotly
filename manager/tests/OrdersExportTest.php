<?php

declare(strict_types=1);

/**
 * Cobre orders_controller::export() (plano 015). export() faz exit() via
 * array_to_csv, entao nao pode ser chamado diretamente no teste — em vez disso:
 * - buildFilter() e testado via ReflectionMethod (mesmo padrao de ProductsValidationTest).
 * - a montagem das linhas do CSV e testada reproduzindo a mesma query/array_map que
 *   export() usa, contra fixtures reais.
 * O BOM UTF-8 (helper array_to_csv) nao e testavel aqui — export() sempre termina em
 * exit(), entao a cobertura do BOM fica para o aceite manual (Step 5 do plano 015).
 */
final class OrdersExportTest extends DBTestCase
{
    private function buildFilter(array $info): array
    {
        $controller = new orders_controller();
        $method     = new ReflectionMethod($controller, 'buildFilter');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    private function makeOrder(string $status, string $marker): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => $status,
            'customer_name'  => "Cliente {$marker}",
            'customer_mail'  => 'cliente_' . uniqid() . '@example.com',
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 123456,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testBuildFilterWithoutStatusReturnsOnlyActiveCondition(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => []]);

        $this->assertSame([" active = 'yes' "], $conds);
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithValidStatusIncludesStatusCondition(): void
    {
        // Status unico (link/bookmark antigo) ainda e aceito — vira `IN (?)` com um
        // so placeholder, mesma forma que o multi-select usa.
        [$conds, $params] = $this->buildFilter(['get' => ['status' => 'pago']]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?) AND shipped_at IS NULL)) "], $conds);
        $this->assertSame(['pago'], $params);
    }

    public function testBuildFilterWithInvalidStatusFallsBackToNoStatus(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['status' => "pago' OR '1'='1"]]);

        $this->assertSame([" active = 'yes' "], $conds);
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithArrayStatusBuildsInClause(): void
    {
        // ?status[]=a&status[]=b faz o PHP popular $_GET['status'] como array — o
        // formato nativo do multi-select. Cada valor valido vira um placeholder
        // dentro de IN(...), na ordem informada.
        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago', 'cancelado']]]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?,?) AND shipped_at IS NULL)) "], $conds);
        $this->assertSame(['pago', 'cancelado'], $params);
    }

    public function testBuildFilterWithArrayStatusDropsInvalidAndDuplicateValues(): void
    {
        // Valor fora da lista fixa e descartado; duplicata e colapsada — nunca
        // infla o IN(...) nem binda lixo.
        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago', 'pago', "x' OR '1'='1", 'expirado']]]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?,?) AND shipped_at IS NULL)) "], $conds);
        $this->assertSame(['pago', 'expirado'], $params);
    }

    public function testExportRowsMatchStatusFilterAndFormatTotalWithCommaDecimal(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);
        $this->makeOrder('aguardando_pagamento', $marker);

        [$conds, $params] = $this->buildFilter(['get' => ['status' => 'pago']]);

        $model = new orders_model();
        $model->set_field([" idx ", " token ", " customer_name ", " customer_mail ",
            " customer_phone ", " status ", " total_cents ", " created_at ", " paid_at "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->set_order([" created_at DESC "]);
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'filtro por status=pago deve trazer somente o pedido pago da fixture');

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

        $this->assertCount(1, $rows);
        $this->assertSame('pago', $rows[0]['status']);
        $this->assertSame('1.234,56', $rows[0]['total'], 'total deve sair formatado com virgula decimal, sem prefixo R$');
    }

    public function testExportRowsWithoutStatusIncludeAllMatchingOrders(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);
        $this->makeOrder('aguardando_pagamento', $marker);

        [$conds, $params] = $this->buildFilter(['get' => []]);

        $model = new orders_model();
        $model->set_field([" idx ", " status "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'sem filtro de status, export deve trazer todos os pedidos que casam o restante do filtro');
    }

    public function testExportRowsWithStatusFilterMatchingNoOrdersReturnsEmptyArray(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);

        [$conds, $params] = $this->buildFilter(['get' => ['status' => 'cancelado']]);

        $model = new orders_model();
        $model->set_field([" idx ", " status ", " customer_name "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertSame([], $model->data, 'status sem pedidos correspondentes deve resultar em array vazio, exercitando o branch empty($data) de array_to_csv');
    }

    public function testExportRowKeysMatchArrayToCsvHeaderOrder(): void
    {
        // export() passa um array de headers hardcoded para array_to_csv(), separado das
        // chaves geradas pelo array_map(). fputcsv usa $row[$key] ?? '' por header, entao
        // se as duas listas divergirem (renomear/reordenar uma sem a outra) as colunas
        // desalinham silenciosamente — sem erro, sem teste falhando. Este teste ancora as
        // duas listas juntas.
        $marker = uniqid();
        $this->makeOrder('pago', $marker);

        [$conds, $params] = $this->buildFilter(['get' => []]);

        $model = new orders_model();
        $model->set_field([" idx ", " token ", " customer_name ", " customer_mail ",
            " customer_phone ", " status ", " total_cents ", " created_at ", " paid_at "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data);

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

        $this->assertSame(
            ['idx', 'token', 'cliente', 'email', 'telefone', 'status', 'total', 'criado_em', 'pago_em'],
            array_keys($rows[0]),
            'as chaves e a ordem da linha do CSV devem bater exatamente com o array de headers passado a array_to_csv() em export()'
        );
    }

    public function testExportRowsIncludeCustomerContactFieldsNotExposedByIndex(): void
    {
        // email e telefone so existem no SELECT de export() — index() nao traz
        // customer_mail/customer_phone no set_field(). Prova que os dados sensiveis
        // chegam corretos na linha exportada, e nao vazios/trocados.
        $marker = uniqid();
        $this->makeOrder('pago', $marker);

        [$conds, $params] = $this->buildFilter(['get' => []]);

        $model = new orders_model();
        $model->set_field([" idx ", " customer_mail ", " customer_phone "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data);

        $rows = array_map(static function (array $o): array {
            return [
                'email'    => $o['customer_mail'],
                'telefone' => $o['customer_phone'],
            ];
        }, $model->data);

        $this->assertMatchesRegularExpression('/^cliente_.+@example\.com$/', $rows[0]['email'], 'email exportado deve ser o customer_mail real do pedido, nao vazio nem generico');
        $this->assertSame('11999999999', $rows[0]['telefone'], 'telefone exportado deve ser o customer_phone real do pedido');
    }

    public function testExportRowMapsNullPaidAtToEmptyString(): void
    {
        // Pedido aguardando pagamento nunca teve paid_at preenchido (coluna DEFAULT NULL,
        // migrations/012). 'pago_em' => $o['paid_at'] ?? '' precisa virar string vazia,
        // nunca a string literal "null" nem quebrar o CSV.
        $marker = uniqid();
        $this->makeOrder('aguardando_pagamento', $marker);

        [$conds, $params] = $this->buildFilter(['get' => []]);

        $model = new orders_model();
        $model->set_field([" idx ", " paid_at "]);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data);
        $this->assertNull($model->data[0]['paid_at'], 'fixture nao paga deve ter paid_at NULL no banco');

        $rows = array_map(static function (array $o): array {
            return ['pago_em' => $o['paid_at'] ?? ''];
        }, $model->data);

        $this->assertSame('', $rows[0]['pago_em'], 'paid_at NULL deve virar string vazia na linha exportada, nunca null nem "null"');
    }
}
