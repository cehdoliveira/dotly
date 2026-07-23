<?php
/**
 * @method void set_con(localPDO $con)
 * @method void set_table(string $table)
 * @method void set_schema(array $schema)
 * @method void set_keys(array $keys)
 * @method void set_field(array $field)
 * @method void set_filter(array $filter, array $params = [])
 * @method void set_order(array $order)
 * @method void set_group(array $group)
 * @method void set_paginate(array $paginate)
 * @method void set_data(array $data)
 * @method void set_recordset(int $recordset)
 * @method array get_data()
 */
class DOLModel extends rootOBJ
{
	function __construct(string $table)
	{
		$c = localPDO::getInstance();
		$this->set_con($c);
		$this->set_table($table);
		$this->set_schema($this->con->fields_config($this->table));
		$keys = array();
		foreach ($this->schema as $key => $value) {
			if (isset($value["PK"])) {
				$keys["pk"][] = $key;
			}
			if (isset($value["UNI"])) {
				$keys["UNI"][] = $key;
			}
		}
		$this->set_keys($keys);
	}

	public function beginTransaction(): bool
	{
		return $this->con->beginTransaction();
	}

	public function commit(): bool
	{
		return $this->con->commit();
	}

	public function rollback(): bool
	{
		return $this->con->rollback();
	}

	public function getCon(): localPDO
	{
		return $this->con;
	}

	public function save(): int|bool|\PDOStatement
	{
		if (empty($this->values)) {
			return false;
		}

		if (isset($this->values["idx"])) {
			unset($this->values["idx"]);
		}
		if (isset($this->field["idx"])) {
			unset($this->field["idx"]);
		}

		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$assignments = [];
		$params = [];
		foreach ($this->values as $col => $val) {
			$assignments[] = sprintf(" %s = ? ", $col);
			$params[] = $val;
		}

		// Se o filtro for APENAS o default "active = 'yes'" (definido na classe do model),
		// trata como INSERT, nao UPDATE — mesma logica do codigo original.
		$isUpdateFilter = !(count($this->filter) === 1 && ltrim(rtrim($this->filter[0])) === "active = 'yes'");

		if ($isUpdateFilter) {
			$fi = " where " . implode(" and ", $this->filter) . " ";
			$pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";
			$assignments[] = " modified_at = now() ";
			$assignments[] = " modified_by = ? ";
			$params[] = $userId;

			// Append filter params (WHERE placeholders) after SET params
			if (!empty($this->filterParams)) {
				$params = array_merge($params, $this->filterParams);
			}

			$sql = sprintf(
				"UPDATE %s SET %s %s",
				$this->table,
				implode(" , ", $assignments),
				$fi . $pa
			);
			return $this->con->executePrepared($sql, $params);
		} else {
			$assignments[] = " created_at = now() ";
			$assignments[] = " created_by = ? ";
			$params[] = $userId;

			$sql = sprintf(
				"INSERT INTO %s SET %s",
				$this->table,
				implode(" , ", $assignments)
			);
			$this->con->executePrepared($sql, $params);
			return $this->con->lastInsertId();
		}
	}

	public function remove(): ?\PDOStatement
	{
		$fi = " where " . implode(" and ", $this->filter) . " ";
		$pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$params = [$userId];
		if (!empty($this->filterParams)) {
			$params = array_merge($params, $this->filterParams);
		}

		$sql = sprintf(
			"UPDATE %s SET active = 'no', removed_at = now(), removed_by = ? %s",
			$this->table,
			$fi . $pa
		);
		return $this->con->executePrepared($sql, $params);
	}

	/**
	 * Define as condicoes WHERE da query.
	 *
	 * Sem $params: comportamento legado — strings SQL cruas no array.
	 *   Ex: $model->set_filter(["active = 'yes'", "idx > 0"]);
	 *
	 * Com $params: prepared statement — use ? nos templates.
	 *   Ex: $model->set_filter(["active = 'yes'", "mail = ? OR login = ?"], ['yes', $mail, $login]);
	 *
	 * @param array $conditions Condicoes SQL (strings com ? opcionais)
	 * @param array $params     Valores para bind (opcional)
	 */
	public function set_filter(array $conditions, array $params = []): void
	{
		$this->filter = $conditions;
		$this->filterParams = $params;
	}

	public function populate(array $data, bool $encode = false)
	{
		$array = array();
		foreach ($this->schema as $key => $value) {
			if (array_key_exists($key, $data)) {
				if ($encode === true && $data[$key] !== null) {
					$data[$key] = mb_convert_encoding($data[$key], 'ISO-8859-1', 'UTF-8');
				}
				if ($data[$key] !== '') {
					$array[$key] = sprintf(" %s ", $key);
					$this->values[$key] = $data[$key];
				}
			}
		}
		if (count($array)) {
			$this->set_field($array);
		}
	}

	public function return_data(): array
	{
		$this->load_data();
		return array($this->recordset, $this->data);
	}

	public function _list_data(string $value = "name", array $filter = array(), string $key = "idx", string $order = ""): array
	{
		$this->set_field(array($key, $value));
		$this->set_filter(count($filter) ? array_merge(array(" active = 'yes' "), $filter) : array(" active = 'yes' "));
		$this->set_order(array($order == "" ? preg_replace("/.+ as (.+)$/", "$1", $value) . " asc " : $order));
		$this->load_data();
		return $this->data;
	}

	public function _current_data(array $filter = array(), array $fields = array(), array $attach = array(), array $attach_son = array(), bool|array $availabled = false): array|false
	{
		$field = array(" idx ", " DATE_FORMAT( created_at , '%d/%m/%Y %H:%i' ) as created_at ", " DATE_FORMAT( modified_at , '%d/%m/%Y %H:%i' ) as modified_at ");
		if (!count($filter)) {
			$filter = array(" idx = -1 ");
		}
		if (count($fields)) {
			$field = array_merge($field, $fields);
		}
		$this->set_field($field);
		$this->set_filter($filter);
		$this->set_paginate(array(1));
		$this->load_data(false);

		if (count($attach)) {
			foreach ($attach as $k => $v) {
				$this->attach(array($v["name"]), isset($v["direction"]) ? $v["direction"] : false, isset($v["specific"]) ? $v["specific"] : "");
			}
		}
		if (count($attach_son)) {
			foreach ($attach_son as $k => $v) {
				$classesfather = $v[0];
				$soon = $v[1];
				$classes = array($soon["name"]);
				$reverse_table = isset($soon["direction"]) ? $soon["direction"] : "";
				$options = isset($soon["options"]) ? $soon["options"] : "";
				$this->attach_son($classesfather, $classes, $reverse_table, $options);
			}
		}
		if ($availabled != false && count($availabled)) {
			$this->data[0]["_availabe_attach"] = $availabled;
			foreach ($availabled as $key => $value) {
				if (isset($this->data[0][$key . "_attach"][0])) {
					foreach ($this->data[0][$key . "_attach"] as $k => $v) {
						$this->data[0]["_availabe_attach"][$key]["data"][] = $v["idx"];
					}
				}
			}
		}
		return current($this->data);
	}

	public function load_data(bool $withCount = true): void
	{
		$ff = implode(",", $this->field);
		$fi = " where " . implode(" and ", $this->filter) . " ";
		$or = isset($this->order) ? " order by " . implode(" , ", $this->order) . " " : "";
		$gp = isset($this->group) ? " group by " . implode(" , ", $this->group) . " " : "";
		$pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";

		$countExpr = (isset($this->keys["pk"]) && !empty($this->keys["pk"]))
			? implode(",", $this->keys["pk"])
			: "*";

		$sql = sprintf("SELECT %s FROM %s %s %s %s %s", $ff, $this->table, $fi, $gp, $or, $pa);
		$r = $this->con->executePrepared($sql, $this->filterParams);
		$this->set_data($this->con->results($r));

		if ($withCount) {
			$countSql = sprintf("SELECT count( %s ) as q FROM %s %s %s",
				$countExpr, $this->table, $fi, $gp);
			$countStmt = $this->con->executePrepared($countSql, $this->filterParams);
			$this->set_recordset($this->con->result($countStmt, "q", 0));
		} else {
			$this->set_recordset(count($this->data));
		}
	}

	/**
	 * Executa uma query raw com parametros vinculados.
	 * Para comandos que nao passam por load_data/save (ex: updates diretos).
	 */
	public function execute_raw_prepared(string $sql, array $params = []): \PDOStatement
	{
		return $this->con->executePrepared($sql, $params);
	}

	/**
	 * SELECT na tabela do model. $alias apelida a tabela base; $join recebe a
	 * clausula de junção crua (sem placeholders). Placeholders (?) sao
	 * permitidos em $fields e $where e casam com $params na ordem em que
	 * aparecem no SQL.
	 */
	public function select(array $fields = array(), ?string $where = null, ?array $params = null, ?string $alias = null, ?string $join = null): \PDOStatement
	{
		if ($join !== null && str_contains($join, '?')) {
			throw new \InvalidArgumentException('select() nao suporta placeholders no $join; mova a condicao para o $where.');
		}
		$sql = sprintf(
			"SELECT %s FROM %s%s%s %s",
			implode(", ", $fields),
			$this->table,
			$alias !== null ? " " . $alias : '',
			$join !== null ? " " . $join : '',
			$where ?? ''
		);
		return $this->execute_raw_prepared($sql, $params ?? []);
	}

	/**
	 * UPDATE na tabela do model. Sempre carimba modified_at/modified_by.
	 * Ordem de bind: [...$joinParams, userId, ...$params] — `?` no $join
	 * casam com $joinParams; `?` em $fields e $where casam com $params.
	 */
	public function update(array $fields = array(), ?string $where = null, ?array $params = null, ?string $alias = null, ?string $join = null, ?array $joinParams = null): \PDOStatement
	{
		if ($where === null || trim($where) === '') {
			throw new \InvalidArgumentException('update() requires a WHERE clause; use "WHERE 1=1" to affect all rows intentionally.');
		}
		if (empty($fields)) {
			throw new \InvalidArgumentException('update() requires at least one field to set.');
		}
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$assignments = array_merge([" modified_at = now() ", " modified_by = ? "], $fields);
		$bindParams  = array_merge($joinParams ?? [], [$userId], $params ?? []);

		$sql = sprintf(
			"UPDATE %s%s%s SET %s %s",
			$this->table,
			$alias !== null ? " " . $alias : '',
			$join !== null ? " " . $join : '',
			implode(", ", $assignments),
			$where
		);
		return $this->execute_raw_prepared($sql, $bindParams);
	}

	/**
	 * INSERT na tabela do model. Sempre carimba created_at/created_by.
	 * $suffix e appendado cru ao final (ex. "ON DUPLICATE KEY UPDATE idx = idx")
	 * e nao pode conter placeholders. Retorna o lastInsertId (0 se o
	 * ON DUPLICATE nao inseriu linha nova — verificado empiricamente via
	 * PDO::lastInsertId() real, MySQL 8.0: o self-assign "idx = idx" nao
	 * conta como tocar a coluna auto_increment pro driver, diferente de
	 * "idx = LAST_INSERT_ID(idx)", que retornaria o id existente).
	 */
	public function insert(array $data, ?string $suffix = null): int
	{
		if (empty($data)) {
			throw new \InvalidArgumentException('insert() requires at least one column.');
		}
		if ($suffix !== null && str_contains($suffix, '?')) {
			throw new \InvalidArgumentException('insert() nao suporta placeholders no $suffix.');
		}
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$assignments = [];
		$params = [];
		foreach ($data as $col => $val) {
			$assignments[] = sprintf(" %s = ? ", $col);
			$params[] = $val;
		}
		$assignments[] = " created_at = now() ";
		$assignments[] = " created_by = ? ";
		$params[] = $userId;

		$sql = sprintf(
			"INSERT INTO %s SET %s %s",
			$this->table,
			implode(" , ", $assignments),
			$suffix ?? ''
		);
		$this->execute_raw_prepared($sql, $params);
		return (int)$this->con->lastInsertId();
	}

	public function attach(array $classes = array(), ?string $reverse_table = null, ?string $options = null, ?array $class_field = null): void
	{
		$new_data = array();
		$_data = $this->data;
		foreach ($_data as $key => $value) {
			$new_data[$key] = $value;
			foreach ($classes as $class) {
				$junctionTable = sprintf("%s_%s",
					$reverse_table ? $class : $this->table,
					$reverse_table ? $this->table : $class);
				$parentCol = sprintf("%s_id", $this->table);
				$childCol  = sprintf("%s_id", $class);

				$r = $this->con->executePrepared(
					sprintf("SELECT %s as k FROM %s WHERE active = 'yes' AND %s = ?", $childCol, $junctionTable, $parentCol),
					[(int)$value["idx"]]
				);
				$filter_key_vals = array();
				foreach ($this->con->results($r) as $key_r => $data) {
					$filter_key_vals[] = $data["k"];
				}
				if (empty($filter_key_vals)) {
					$new_data[$key][$class . "_attach"] = array();
					continue;
				}
				$placeholders = implode(',', array_fill(0, count($filter_key_vals), '?'));
				$fields = isset($class_field) ? implode(", ", $class_field) : "*";
				$sql = sprintf("SELECT %s FROM %s WHERE active = 'yes' AND idx IN (%s) %s",
					$fields, $class, $placeholders, $options ?? '');
				$r = $this->con->executePrepared($sql, $filter_key_vals);
				$new_data[$key][$class . "_attach"] = $this->con->results($r);
			}
		}
		$this->set_data($new_data);
	}

	public function join(?string $name = null, ?string $table = null, array $fw_key = array(), ?string $options = null, ?array $field = null): void
	{
		$new_data = array();
		$_data = $this->get_data();

		// Determine the foreign key column name (first entry in $fw_key)
		$fwColumn = null;
		$dataKey  = null;
		foreach ((array)$fw_key as $col => $dk) {
			$fwColumn = $col;
			$dataKey  = $dk;
			break;
		}

		// Batch query when possible (no per-row #IDX# options)
		if ($options === null && $fwColumn !== null && $dataKey !== null && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fwColumn)) {
			$lookupIds = [];
			foreach ($_data as $value) {
				if (isset($value[$dataKey])) {
					$lookupIds[] = (int)$value[$dataKey];
				}
			}
			$lookupIds = array_unique($lookupIds);

			$batchResults = [];
			if (!empty($lookupIds)) {
				$placeholders = implode(',', array_fill(0, count($lookupIds), '?'));
				$fields = isset($field) ? implode(", ", $field) : "*";
				$sql = sprintf("SELECT %s FROM %s WHERE active = 'yes' AND %s IN (%s)",
					$fields, $table, $fwColumn, $placeholders);
				$r = $this->con->executePrepared($sql, $lookupIds);
				foreach ($this->con->results($r) as $row) {
					$batchResults[$row[$fwColumn]][] = $row;
				}
			}

			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				$lookupVal = isset($value[$dataKey]) ? (int)$value[$dataKey] : null;
				$new_data[$key][$name . "_attach"] = $batchResults[$lookupVal] ?? [];
			}
		} else {
			// Per-row fallback for queries with #IDX# options
			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				$flt = array(" active = 'yes' ");
				$params = array();
				foreach ((array)$fw_key as $fw_keys => $data_value) {
					if (isset($value[$data_value])) {
						if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fw_keys)) {
							continue;
						}
						$flt[] = $fw_keys . " = ? ";
						$params[] = $value[$data_value];
					}
				}
				if (count($flt) > 1 || ! empty($options)) {
					$optionsSql = '';
					if ($options !== null) {
						$optionsSql = ' ' . str_replace("#IDX#", "?", $options);
						$params[] = (int)$value["idx"];
					}
					$fields = isset($field) ? implode(", ", $field) : "*";
					$sql = sprintf("SELECT %s FROM %s WHERE %s%s", $fields, $table, implode(" and ", $flt), $optionsSql);
					$r = $this->con->executePrepared($sql, $params);
					$new_data[$key][$name . "_attach"] = $this->con->results($r);
				} else {
					$new_data[$key][$name . "_attach"] = array();
				}
			}
		}
		$this->set_data($new_data);
	}

	public function attach_son(string $classesfather = "", array $classes = array(), ?string $reverse_table = null, ?string $options = null, ?array $class_field = null): void
	{
		if ($classesfather != "" && count($classes)) {
			$new_data = array();
			$_data = $this->data;
			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				if (isset($new_data[$key][$classesfather . "_attach"]) && count($new_data[$key][$classesfather . "_attach"])) {
					foreach ($new_data[$key][$classesfather . "_attach"] as $k => $v) {
						foreach ($classes as $class) {
							$junctionTable = sprintf("%s_%s",
								$reverse_table ? $class : $classesfather,
								$reverse_table ? $classesfather : $class);
							$fatherCol = sprintf("%s_id", $classesfather);
							$childCol  = sprintf("%s_id", $class);

							$r = $this->con->executePrepared(
								sprintf("SELECT %s as k FROM %s WHERE active = 'yes' AND %s = ?", $childCol, $junctionTable, $fatherCol),
								[(int)$v["idx"]]
							);
							$filter_key_vals = array();
							foreach ($this->con->results($r) as $key_r => $data) {
								$filter_key_vals[] = $data["k"];
							}
							if (empty($filter_key_vals)) {
								$new_data[$key][$classesfather . "_attach"][$k][$class . "_attach"] = array();
								continue;
							}
							$placeholders = implode(',', array_fill(0, count($filter_key_vals), '?'));

							$optionsSql = '';
							$optionsParams = array();
							if ($options !== null) {
								$optionsSql = ' ' . preg_replace("/%s/im", "?", $options, -1, $count);
								$optionsParams = array_fill(0, $count, (int)$value["idx"]);
							}

							$fields = isset($class_field[$class]) ? implode(", ", $class_field[$class]) : "*";
							$sql = sprintf("SELECT %s FROM %s WHERE active = 'yes' AND idx IN (%s)%s",
								$fields, $class, $placeholders, $optionsSql);
							$r = $this->con->executePrepared($sql, array_merge($filter_key_vals, $optionsParams));
							$new_data[$key][$classesfather . "_attach"][$k][$class . "_attach"] = $this->con->results($r);
						}
					}
				}
			}
			$this->set_data($new_data);
		}
	}

	public function save_attach(array $info, array $classes = array(), ?string $reverse_table = null): void
	{
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		foreach ($classes as $class) {
			if (isset($info["post"][$class . "_id"])) {
				$execute = $info["post"][$class . "_id"];
				$varexecute = array();
				if (is_array($execute) && count($execute)) {
					$varexecute = $execute;
				} elseif (!is_array($execute) && (int)$execute > 0) {
					$varexecute[] = $execute;
				}

				if (count($varexecute)) {
					$junctionTable = sprintf(" %s_%s ",
						$reverse_table ? $class : $this->table,
						$reverse_table ? $this->table : $class);
					$tableIdCol = sprintf(" %s_id ", $this->table);

					$this->con->executePrepared(
						"UPDATE {$junctionTable} SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND {$tableIdCol} = ?",
						[$userId, $info["idx"]]
					);

					$classIdCol = sprintf(" %s_id ", $class);
					foreach ($varexecute as $var) {
						$this->con->executePrepared(
							"INSERT INTO {$junctionTable} ({$classIdCol}, {$tableIdCol}, created_by, created_at) VALUES (?, ?, ?, now()) ON DUPLICATE KEY UPDATE active = 'yes', removed_at = NULL, removed_by = NULL, modified_at = now(), modified_by = ?",
							[$var, $info["idx"], $userId, $userId]
						);
					}
				}
			}
		}
	}
}
