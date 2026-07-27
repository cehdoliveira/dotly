<?php

declare(strict_types=1);

/**
 * Cobre a escrita feita por config_controller::saveSenderAddress() (secao
 * 'remetente' da tela de Configuracoes — plano 025). O metodo nao e chamado
 * diretamente: action() termina em basic_redir(), que faz exit() e mataria o
 * processo do PHPUnit — mesmo motivo documentado em ConfigSalesWindowActionTest/
 * ConfigActionTest. Reproduz-se aqui exatamente a mesma sequencia de
 * normalizacao/validacao/escrita que o controller monta a partir do $post.
 *
 * ATENCAO (ver DBTestCase::class docblock): as chaves sender_* sao globais e
 * NAO sao revertidas pelo rollback do tearDown() (fixtures via model usam a
 * conexao singleton, nao $this->con). Cada teste grava valores distintos
 * (uniqid()) e le de volta o que ELE MESMO acabou de gravar — nunca confia no
 * estado deixado por outro teste.
 */
final class ConfigSenderActionTest extends DBTestCase
{
    /** Mesma lista de config_controller::SENDER_KEYS. */
    private const SENDER_KEYS = [
        'sender_name', 'sender_zip', 'sender_street', 'sender_number',
        'sender_complement', 'sender_district', 'sender_city', 'sender_uf',
    ];

    /** Mesma lista de config_controller::SENDER_REQUIRED_KEYS. */
    private const SENDER_REQUIRED_KEYS = [
        'sender_name', 'sender_zip', 'sender_street', 'sender_number',
        'sender_city', 'sender_uf',
    ];

    /**
     * Mesma normalizacao que config_controller::saveSenderAddress() executa.
     *
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    private function normalizeSenderValues(array $post): array
    {
        $values = [];
        foreach (self::SENDER_KEYS as $key) {
            $values[$key] = mb_substr(trim((string)($post[$key] ?? '')), 0, 255);
        }

        $values['sender_zip'] = preg_replace('/\D+/', '', $values['sender_zip']) ?? '';
        $values['sender_uf']  = mb_strtoupper($values['sender_uf']);

        return $values;
    }

    /**
     * Mesma regra tudo-ou-nada que config_controller::saveSenderAddress() aplica
     * antes de gravar.
     *
     * @param array<string,string> $values
     */
    private function isValid(array $values): bool
    {
        $filled = array_filter($values, static fn(string $v): bool => $v !== '');
        if ($filled === []) {
            return true;
        }

        foreach (self::SENDER_REQUIRED_KEYS as $key) {
            if ($values[$key] === '') {
                return false;
            }
        }
        if (strlen($values['sender_zip']) !== 8) {
            return false;
        }
        if (preg_match('/^[A-Z]{2}$/', $values['sender_uf']) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Mesmo upsert 2-passos que config_controller::saveSenderAddress() executa.
     *
     * @param array<string,string> $values
     */
    private function upsertSender(array $values, int $adminId): void
    {
        $model = new settings_model();
        foreach ($values as $key => $value) {
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

    /** @return array{svalue: ?string, active: ?string} */
    private function readSetting(string $key): array
    {
        $model = new settings_model();
        $stmt  = $model->select([" svalue ", " active "], "WHERE skey = ?", [$key]);
        $row   = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : ['svalue' => null, 'active' => null];
    }

    public function testUpsertWritesAllEightKeys(): void
    {
        $suffix = uniqid();
        $values = $this->normalizeSenderValues([
            'sender_name' => "Loja $suffix", 'sender_zip' => '13010-100',
            'sender_street' => 'R. Exemplo', 'sender_number' => '45',
            'sender_complement' => "Sala $suffix", 'sender_district' => 'Centro',
            'sender_city' => 'Campinas', 'sender_uf' => 'sp',
        ]);
        $this->assertTrue($this->isValid($values));

        $this->upsertSender($values, 1);

        foreach (self::SENDER_KEYS as $key) {
            $row = $this->readSetting($key);
            $this->assertSame($values[$key], $row['svalue'], "chave $key");
            $this->assertSame('yes', $row['active'], "chave $key deveria estar ativa");
        }
    }

    public function testUpsertReactivatesSoftDeletedKey(): void
    {
        $model = new settings_model();
        $model->execute_raw_prepared("UPDATE settings SET active = 'no' WHERE skey = 'sender_name'", []);

        $suffix = uniqid();
        $values = $this->normalizeSenderValues([
            'sender_name' => "Reativada $suffix", 'sender_zip' => '13010100',
            'sender_street' => 'R. Exemplo', 'sender_number' => '45',
            'sender_city' => 'Campinas', 'sender_uf' => 'SP',
        ]);

        $this->upsertSender($values, 1);

        $row = $this->readSetting('sender_name');
        $this->assertSame("Reativada $suffix", $row['svalue']);
        $this->assertSame('yes', $row['active'], 'soft-delete deveria ser revertido pelo upsert');
    }

    public function testZipIsStoredDigitsOnly(): void
    {
        $suffix = uniqid();
        $values = $this->normalizeSenderValues([
            'sender_name' => "Loja $suffix", 'sender_zip' => '13010-100',
            'sender_street' => 'R. Exemplo', 'sender_number' => '45',
            'sender_city' => 'Campinas', 'sender_uf' => 'SP',
        ]);
        $this->assertSame('13010100', $values['sender_zip'], 'normalizacao deveria remover o hifen');

        $this->upsertSender($values, 1);

        $row = $this->readSetting('sender_zip');
        $this->assertSame('13010100', $row['svalue']);
    }

    public function testUfIsStoredUppercase(): void
    {
        $suffix = uniqid();
        $values = $this->normalizeSenderValues([
            'sender_name' => "Loja $suffix", 'sender_zip' => '13010100',
            'sender_street' => 'R. Exemplo', 'sender_number' => '45',
            'sender_city' => 'Campinas', 'sender_uf' => 'sp',
        ]);
        $this->assertSame('SP', $values['sender_uf'], 'normalizacao deveria maiusculizar');

        $this->upsertSender($values, 1);

        $row = $this->readSetting('sender_uf');
        $this->assertSame('SP', $row['svalue']);
    }

    public function testEmptyFormClearsAllKeys(): void
    {
        $values = $this->normalizeSenderValues([]);
        $this->assertTrue($this->isValid($values), 'form 100% vazio e uma operacao valida (limpar remetente)');

        $this->upsertSender($values, 1);

        foreach (self::SENDER_KEYS as $key) {
            $row = $this->readSetting($key);
            $this->assertSame('', $row['svalue'], "chave $key deveria estar vazia");
        }
    }

    public function testIncompleteSetIsRejected(): void
    {
        $values = $this->normalizeSenderValues(['sender_name' => 'Só o nome preenchido']);

        $this->assertFalse($this->isValid($values), 'conjunto minimo incompleto deveria ser recusado');
    }

    public function testFieldValuesAreTruncatedTo255(): void
    {
        $long = str_repeat('a', 300);
        $values = $this->normalizeSenderValues([
            'sender_name' => $long, 'sender_zip' => '13010100',
            'sender_street' => 'R. Exemplo', 'sender_number' => '45',
            'sender_city' => 'Campinas', 'sender_uf' => 'SP',
        ]);

        $this->assertSame(255, mb_strlen($values['sender_name']), 'normalizacao deveria truncar em 255 chars');

        $this->upsertSender($values, 1);

        $row = $this->readSetting('sender_name');
        $this->assertSame(255, mb_strlen((string)$row['svalue']), 'MySQL nao deveria estourar/truncar diferente do PHP');
    }
}
