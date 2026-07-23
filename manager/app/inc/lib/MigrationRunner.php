<?php

class MigrationRunner
{
    private ?\PDO $pdo;
    private string $migrations_dir = '/var/www/dotly/migrations';
    private \Closure|Logger $logger;

    public function __construct(localPDO|\PDO $pdo, ?string $migrations_dir = null)
    {
        // Suporte para local_pdo ou PDO nativo
        if (method_exists($pdo, 'getPdo')) {
            $this->pdo = $pdo->getPdo();
        } else {
            $this->pdo = $pdo;
        }

        // Determinar diretório de migrations
        if ($migrations_dir === null) {
            $baseDir = dirname(dirname(dirname(__DIR__)));

            $possiblePaths = [
                realpath(__DIR__ . '/../../../../migrations'),
                $baseDir . '/../migrations',
                '/var/www/dotly/migrations',
                getenv('APP_ROOT') ? getenv('APP_ROOT') . '/migrations' : null,
            ];

            foreach ($possiblePaths as $path) {
                if ($path && is_dir($path)) {
                    $this->migrations_dir = $path;
                    break;
                }
            }

            if (!$this->migrations_dir) {
                $fallback = $baseDir . '/../migrations';
                if (is_dir($fallback)) {
                    $this->migrations_dir = $fallback;
                } else {
                    $this->migrations_dir = '/var/www/dotly/migrations';
                }
            }
        } else {
            $this->migrations_dir = $migrations_dir;
        }

        $this->logger = function ($message) {
            echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
        };
    }

    /**
     * Executa todas as migrations pendentes
     */
    public function run(): array
    {
        $results = [
            'executed' => [],
            'skipped' => [],
            'failed' => []
        ];

        // Lock advisório no banco para evitar execução concorrente.
        // GET_LOCK(..., 0) retorna imediatamente: se outro processo (ex.: tick
        // anterior do cron ainda rodando) detém o lock, recebemos int(0) e pulamos.
        $got = $this->pdo
            ->query("SELECT GET_LOCK('app_migrations', 0) AS l")
            ->fetch(\PDO::FETCH_ASSOC);
        if ((int) ($got['l'] ?? 0) !== 1) {
            $this->log("Outro processo de migration está em execução — pulando este ciclo.");
            return $results;
        }

        try {
            // Criar tabela de log se não existir
            $this->createMigrationsTable();

            // Ler todos os .sql files da pasta
            $files = $this->getMigrationFiles();

            if (empty($files)) {
                $this->log("Nenhuma migration encontrada em {$this->migrations_dir}");
                return $results;
            }

            foreach ($files as $filename) {
                $migration_name = pathinfo($filename, PATHINFO_FILENAME);

                // Verificar se já foi executada
                if ($this->isExecuted($migration_name)) {
                    // $this->log("⏭️  SKIP: {$migration_name}"); Descomentar para Debug
                    $results['skipped'][] = $migration_name;
                    continue;
                }

                // Executar migration
                $filepath = $this->migrations_dir . '/' . $filename;
                $sql = file_get_contents($filepath);

                try {
                    $this->executeMigration($sql);
                    $this->recordMigration($migration_name, 'success', null);
                    // $this->log("✅ OK: {$migration_name}"); Descomentar para Debug
                    $results['executed'][] = $migration_name;
                } catch (Exception $e) {
                    $this->recordMigration($migration_name, 'failed', $e->getMessage());
                    // $this->log("❌ ERRO: {$migration_name} - {$e->getMessage()}"); Descomentar para Debug
                    $results['failed'][] = $migration_name;
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('app_migrations')");
        }

        return $results;
    }

    /**
     * Executa uma migration específica (ignora se já foi executada)
     */
    public function runSingle(string $migration_name): bool
    {
        $this->createMigrationsTable();

        if ($this->isExecuted($migration_name)) {
            $this->log("⏭️  Migration já foi executada: {$migration_name}");
            return false;
        }

        $filepath = $this->migrations_dir . '/' . $migration_name . '.sql';

        if (!file_exists($filepath)) {
            throw new Exception("Migration não encontrada: {$filepath}");
        }

        $sql = file_get_contents($filepath);

        try {
            $this->executeMigration($sql);
            $this->recordMigration($migration_name, 'success', null);
            $this->log("✅ OK: {$migration_name}");
            return true;
        } catch (Exception $e) {
            $this->recordMigration($migration_name, 'failed', $e->getMessage());
            $this->log("❌ ERRO: {$migration_name}");
            throw $e;
        }
    }

    /**
     * Cria a tabela de log se não existir
     */
    private function createMigrationsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `migrations_log` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `migration_name` VARCHAR(255) NOT NULL UNIQUE,
              `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `status` ENUM('success', 'failed') DEFAULT 'success',
              `error_message` TEXT,
              INDEX idx_migration (migration_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            // CREATE TABLE IF NOT EXISTS não deve falhar por "já existe".
            // Qualquer outro erro (permissão, conexão) precisa ser visível —
            // logar em vez de engolir, pois sem migrations_log nada funciona.
            $this->log("⚠️  Falha ao garantir migrations_log: {$e->getMessage()}");
        }
    }

    /**
     * Obtém lista de arquivos .sql da pasta migrations
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrations_dir)) {
            $this->log("⚠️  Diretório não existe: {$this->migrations_dir}");
            return [];
        }

        $files = glob($this->migrations_dir . '/*.sql');
        if (!is_array($files)) {
            $this->log("⚠️  Nenhum arquivo .sql encontrado em: {$this->migrations_dir}");
            return [];
        }

        // Ordenar alfabeticamente (importante para order)
        sort($files);

        return array_map('basename', $files);
    }

    /**
     * Verifica se uma migration já foi executada
     */
    private function isExecuted(string $migration_name): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM migrations_log
                WHERE migration_name = ? AND status = 'success'
            ");
            $stmt->execute([$migration_name]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Executa SQL contendo múltiplas queries
     */
	private function executeMigration(string $sql): void
	{
		// Remover comentários SQL
		$sql = preg_replace('/(\/\*[\s\S]*?\*\/)|(--[^\n]*)/m', '', $sql);
		$sql = trim($sql);

		if (empty($sql)) {
			return;
		}

		// Dividir por ; para executar queries separadamente
		$queries = array_filter(
			array_map('trim', explode(';', $sql)),
			fn($q) => !empty($q)
		);

		if (empty($queries)) {
			return;
		}

		// ATENÇÃO: este wrapper transacional NÃO torna migrations com DDL atômicas.
		// No MySQL, instruções DDL (CREATE TABLE, ALTER ...) disparam um COMMIT
		// implícito antes e depois, então um rollBack() após um DDL bem-sucedido é
		// um no-op. A transação só protege migrations puramente DML. Por isso cada
		// migration precisa ser idempotente por conta própria (INSERT IGNORE /
		// ON DUPLICATE KEY + constraints UNIQUE — ver migrations/006_*).
		$this->pdo->beginTransaction();
		try {
			foreach ($queries as $query) {
				$this->pdo->exec($query);
			}
			if ($this->pdo->inTransaction()) {
				$this->pdo->commit();
			}
		} catch (\Exception $e) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}

    /**
     * Registra execução da migration no banco
     */
    private function recordMigration(string $name, string $status, ?string $error): void
    {
        // NÃO engolir falhas aqui: se uma migration foi aplicada mas o registro
        // de 'success' não persistir, isExecuted() continuará falso e o arquivo
        // será re-executado a cada tick. Deixar a exceção propagar para o
        // operador ver o estado inconsistente.
        $stmt = $this->pdo->prepare("
            INSERT INTO migrations_log (migration_name, status, error_message)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
              status = VALUES(status),
              error_message = VALUES(error_message),
              executed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$name, $status, $error]);
    }

    /**
     * Define função custom para logging
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Log helper
     */
    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }

    /**
     * Retorna status de todas as migrations
     */
    public function status(): array
    {
        $this->createMigrationsTable();

        $files = $this->getMigrationFiles();
        $executed = [];

        try {
            $stmt = $this->pdo->query("
                SELECT migration_name, status, executed_at
                FROM migrations_log
                ORDER BY executed_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $executed[$row['migration_name']] = [
                    'status' => $row['status'],
                    'executed_at' => $row['executed_at']
                ];
            }
        } catch (PDOException $e) {
            // Tabela não existe ainda
        }

        $status = [];
        foreach ($files as $filename) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $status[$name] = [
                'file' => $filename,
                'executed' => isset($executed[$name]),
                'status' => isset($executed[$name]) ? $executed[$name]['status'] : 'pending',
                'executed_at' => isset($executed[$name]) ? $executed[$name]['executed_at'] : null
            ];
        }

        return $status;
    }

    /**
     * Retorna o diretório de migrations (útil para debug)
     */
    public function getMigrationsDir(): string
    {
        return $this->migrations_dir;
    }
}
