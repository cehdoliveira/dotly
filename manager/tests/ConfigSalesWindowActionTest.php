<?php

declare(strict_types=1);

/**
 * Cobre a escrita feita por config_controller::saveSalesWindow() (secao 'janela' da
 * tela de Configuracoes). O metodo nao e chamado diretamente: action() termina em
 * basic_redir(), que faz exit() e mataria o processo do PHPUnit — mesmo motivo
 * documentado em ConfigActionTest/GatewaysActionTest. Reproduz-se aqui exatamente a
 * mesma sequencia de normalizacao/validacao/escrita que o controller monta a partir
 * do $post.
 */
final class ConfigSalesWindowActionTest extends DBTestCase
{
    /** Mesma normalizacao que config_controller::normalizeLocalDatetime() executa. */
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

    /** Mesmo upsert 2-passos que config_controller::saveSalesWindow() executa. */
    private function upsertSalesSettings(string $override, string $start, string $end, int $adminId): void
    {
        $model = new settings_model();
        foreach ([
            'sales_override'        => $override,
            'sales_window_start_at' => $start,
            'sales_window_end_at'   => $end,
        ] as $key => $value) {
            $model->execute_raw_prepared(
                "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, ?, 'yes', ?, '')",
                [date('Y-m-d H:i:s'), $adminId, $key]
            );
            $model->execute_raw_prepared(
                "UPDATE settings SET svalue = ?, active = 'yes', modified_at = ?, modified_by = ? WHERE skey = ?",
                [$value, date('Y-m-d H:i:s'), $adminId, $key]
            );
        }
    }

    private function readSetting(string $key): array
    {
        $model = new settings_model();
        $stmt = $model->select(
            [" svalue ", " active "],
            "WHERE skey = ?",
            [$key]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : ['svalue' => null, 'active' => null];
    }

    public function testUpsertWritesValueAndReactivatesSoftDeletedKey(): void
    {
        $model = new settings_model();
        // Pre-condicao: soft-deleta uma das chaves antes do upsert.
        $model->execute_raw_prepared(
            "UPDATE settings SET active = 'no' WHERE skey = 'sales_override'",
            []
        );

        $this->upsertSalesSettings('closed', '', '', 1);

        $row = $this->readSetting('sales_override');
        $this->assertSame('closed', $row['svalue']);
        $this->assertSame('yes', $row['active']);
    }

    public function testNormalizeConvertsDatetimeLocalFormat(): void
    {
        $this->assertSame('2026-07-25 14:30:00', $this->normalizeLocalDatetime('2026-07-25T14:30'));
    }

    public function testNormalizeEmptyStringStaysEmpty(): void
    {
        $this->assertSame('', $this->normalizeLocalDatetime(''));
    }

    public function testNormalizeInvalidFormatReturnsNull(): void
    {
        $this->assertNull($this->normalizeLocalDatetime('banana'));
    }

    public function testInvertedWindowIsRejectedBeforeWrite(): void
    {
        $start = $this->normalizeLocalDatetime('2026-07-25T14:30');
        $end   = $this->normalizeLocalDatetime('2026-07-25T10:00');

        // Pre-condicao: valor conhecido, para provar que a escrita NAO acontece.
        $this->upsertSalesSettings('', '', '', 1);

        $rejected = ($start !== '' && $end !== '' && $end <= $start);
        $this->assertTrue($rejected, 'end <= start deveria ser rejeitado pelo controller');

        if (!$rejected) {
            $this->upsertSalesSettings('', (string) $start, (string) $end, 1);
        }

        $row = $this->readSetting('sales_window_start_at');
        $this->assertSame('', $row['svalue'], 'escrita nao deveria ter acontecido com janela invertida');
    }

    public function testInvalidOverrideIsSavedAsEmptyString(): void
    {
        $override = 'talvez';
        $normalizedOverride = in_array($override, ['', 'open', 'closed'], true) ? $override : '';

        $this->assertSame('', $normalizedOverride);

        $this->upsertSalesSettings($normalizedOverride, '', '', 1);

        $row = $this->readSetting('sales_override');
        $this->assertSame('', $row['svalue']);
    }
}
