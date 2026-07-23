<?php

declare(strict_types=1);

/**
 * Cobre a query agregada de contadores do dashboard (site_controller::dashboard)
 * e a paginacao da listagem de usuarios, que substituiram os count()/array_filter()
 * em PHP sobre a base inteira.
 */
final class DashboardCountsTest extends DBTestCase
{
    private function makeUser(string $active, string $enabled): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => 'Dashboard Test',
            'mail'     => 'dashboard_' . uniqid() . '@example.com',
            'login'    => 'dashboard_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        $update = new users_model();
        $update->set_filter(['idx = ?'], [$id]);
        $update->populate(['active' => $active, 'enabled' => $enabled]);
        $update->save();

        return $id;
    }

    public function testAggregateCountsMatchExpectedBreakdown(): void
    {
        $ids = [
            $this->makeUser('yes', 'yes'),
            $this->makeUser('yes', 'yes'),
            $this->makeUser('yes', 'no'),
            $this->makeUser('no', 'yes'),
        ];

        $model = new users_model();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $model->execute_raw_prepared(
            "SELECT COUNT(*) AS total, SUM(active = 'yes') AS ativos, SUM(active = 'yes' AND enabled = 'yes') AS habilitados FROM users WHERE idx IN ($placeholders)",
            $ids
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total   = (int) $row['total'];
        $ativos  = (int) $row['ativos'];
        $habilitados = (int) $row['habilitados'];
        $removidos = $total - $ativos;

        $this->assertSame(4, $total);
        $this->assertSame(3, $ativos, 'active=yes deve contar 3 linhas');
        $this->assertSame(2, $habilitados, "active='yes' AND enabled='yes' deve contar 2 linhas");
        $this->assertSame(1, $removidos, 'total - ativos deve contar 1 linha removida (active=no)');
    }

    public function testPaginatedListingReturnsOnlyRequestedSlice(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeUser('yes', 'yes');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $page1 = new users_model();
        $page1->set_field([' idx ']);
        $page1->set_filter(["idx IN ($placeholders)"], $ids);
        $page1->set_order([' idx ASC ']);
        $page1->set_paginate([0, 2]);
        $page1->load_data(false);

        $page2 = new users_model();
        $page2->set_field([' idx ']);
        $page2->set_filter(["idx IN ($placeholders)"], $ids);
        $page2->set_order([' idx ASC ']);
        $page2->set_paginate([2, 2]);
        $page2->load_data(false);

        $this->assertCount(2, $page1->data, 'primeira pagina deve trazer 2 linhas (perPage=2)');
        $this->assertCount(2, $page2->data, 'segunda pagina deve trazer 2 linhas (perPage=2)');
        $this->assertSame(2, $page1->get_recordset(), 'load_data(false) deve usar count(data) como recordset');

        $page1Ids = array_column($page1->data, 'idx');
        $page2Ids = array_column($page2->data, 'idx');
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'paginas nao devem se sobrepor');
    }
}
