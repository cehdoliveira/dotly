<?php
class localPDO
{
	private \PDO $pdo;
	public string $error = "";
	private bool $inTransaction = false;
	private static ?localPDO $instance = null;
	private bool $ownsTransaction = false;
	private static array $schemaCache = [];

	public function __construct()
	{
		$host = constant("DB_HOST");
		$user = constant("DB_USER");
		$pass = constant("DB_PASS");
		$database = constant("DB_NAME");

		try {
			$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
			$pdoOptions = [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			];
			if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
				// time_zone da SESSAO alinhado ao fuso do PHP (kernel.php:
				// date_default_timezone_set). Sem isto, now()/NOW() do MySQL roda no
				// fuso do container (UTC) enquanto todo carimbo feito em PHP usa
				// America/Sao_Paulo: created_at/modified_at (carimbados pelo DOLModel
				// via now()) ficavam 3h a frente de paid_at/expires_at/shipped_at, e
				// time_ago(created_at) exibia pedido novo como "em 3 horas".
				// date('P') devolve o offset atual ("-03:00"), entao a marca decide o
				// fuso em um lugar so (kernel.php) e a conexao acompanha.
				$pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] =
					"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . date('P') . "'";
			}
			$this->pdo = new PDO($dsn, $user, $pass, $pdoOptions);
		} catch (PDOException $e) {
			throw $e;
		}
	}

	public static function getInstance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
			self::$instance->beginTransaction();
			self::$instance->ownsTransaction = true;
		}
		return self::$instance;
	}

	public function __destruct()
	{
		if ($this->ownsTransaction && $this->inTransaction) {
			$this->rollback();
		}
	}

	public function beginTransaction(): bool
	{
		if (!$this->inTransaction) {
			$this->pdo->beginTransaction();
			$this->inTransaction = true;
		}
		return true;
	}

	public function commit(): bool
	{
		if ($this->inTransaction) {
			$this->pdo->commit();
			$this->inTransaction = false;
		}
		return true;
	}

	public function rollback(): bool
	{
		if ($this->inTransaction) {
			$this->pdo->rollBack();
			$this->inTransaction = false;
		}
		return true;
	}

	public function recordcount(\PDOStatement|false $res): int
	{
		if (!is_object($res)) return 0;
		try {
			return (int)$res->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public function result(\PDOStatement|false $res, string $name, int $position): mixed
	{
		if ($res === false) return false;
		try {
			$rows = $res->fetchAll(PDO::FETCH_ASSOC);
			if ($position >= count($rows)) return false;
			return isset($rows[$position][$name]) ? $rows[$position][$name] : false;
		} catch (PDOException $e) {
			return false;
		}
	}

	public function results(\PDOStatement|false $res): array
	{
		$obj = [];
		if (is_object($res)) {
			try {
				$obj = $res->fetchAll(PDO::FETCH_ASSOC);
			} catch (PDOException $e) {
				$this->error = $e->getMessage();
			}
		}
		return $obj;
	}

	public function fields_config(string $table): array
	{
		if (isset(self::$schemaCache[$table])) {
			return self::$schemaCache[$table];
		}

		$object = [];
		try {
			$res = $this->pdo->query(sprintf("SHOW COLUMNS FROM %s", $table));
		} catch (PDOException $e) {
			$this->error = $e->getMessage();
			if ($this->inTransaction) {
				$this->rollback();
			}
			Logger::getInstance()->error("SQL error", ['error' => $this->error]);
			throw new RuntimeException("Database error");
		}

		foreach ($this->results($res) as $key => $data) {
			if ($data["Key"] == "PRI") {
				$object[$data["Field"]]["PK"] = true;
			}
			if ($data["Key"] == "UNI") {
				$object[$data["Field"]]["UNI"] = true;
			}
			if (preg_match("/(?P<TYPE>\w+)\((?P<SIZE>.+)\)/", $data["Type"], $match)) {
				$object[$data["Field"]]["type"] = $match["TYPE"];
				$object[$data["Field"]]["size"] = $match["SIZE"];
			} else {
				$object[$data["Field"]]["type"] = $data["Type"];
			}

			if ($data["Default"] !== NULL) {
				$object[$data["Field"]]["default"] = $data["Default"];
			}
			if ($data["Extra"] == "auto_increment") {
				$object[$data["Field"]]["auto_increment"] = true;
			}
		}

		self::$schemaCache[$table] = $object;
		return $object;
	}

	public function getPdo(): \PDO
	{
		return $this->pdo;
	}

	public function lastInsertId(): int
	{
		try {
			return (int)$this->pdo->lastInsertId();
		} catch (PDOException $e) {
		Logger::getInstance()->warning('lastInsertId failed', ['error' => $e->getMessage()]);
			return 0;
		}
	}

	/**
	 * Executa uma query parametrizada com prepared statement.
	 * Substitui o uso manual de real_escape_string() + concatenação.
	 *
	 * @param string $sql    SQL com placeholders (?) ou named (:name)
	 * @param array  $params Valores para bind (sequenciais ou associativos)
	 * @return \PDOStatement
	 * @throws RuntimeException
	 */
	public function executePrepared(string $sql, array $params = []): \PDOStatement
	{
		try {
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
			return $stmt;
		} catch (PDOException $e) {
			$this->error = $e->getMessage();
			if ($this->inTransaction) {
				$this->rollback();
			}
			Logger::getInstance()->error("SQL prepared error", ['error' => $this->error]);
			throw new RuntimeException("Database error");
		}
	}
}
