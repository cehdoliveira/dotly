<?php
if (!class_exists('rootOBJ')) {
	/**
	 * @method void set_con(localPDO $con)
	 * @method void set_table(string $table)
	 * @method void set_schema(array $schema)
	 * @method void set_keys(array $keys)
	 * @method void set_field(array $field)
	 * @method void set_filter(array $filter)
	 * @method void set_order(array $order)
	 * @method void set_group(array $group)
	 * @method void set_paginate(array $paginate)
	 * @method void set_data(array $data)
	 * @method void set_recordset(int $recordset)
	 * @method array get_data()
	 * @method array|false _current_data(array $filter = [], array $fields = [], array $attach = [], array $attach_son = [], bool $availabled = false)
	 */
	class rootOBJ
	{
		// Propriedades base
		public array $data = [];

		// Propriedades usadas por DOLModel (evitar dynamic properties no PHP 8.2+)
		protected ?localPDO $con = null;
		protected ?string $table = null;
		protected ?array $schema = null;
		protected ?array $keys = null;
		protected ?array $paginate = null;
		protected ?int $recordset = null;
		protected array $filter = [];
		protected array $field = [];
		protected ?array $order = null;
		protected ?array $group = null;
		protected ?string $direct_query = null;
		protected array $values = [];
		protected array $filterParams = [];

		public function __call(string $method, array $paramters): mixed
		{
			if (preg_match("/(?P<type>[sg]et)_(?P<method>\w+)/", $method, $match)) {
				$var = $match["method"];
				switch ($match["type"]) {
					case 'set':
						$this->$var = $paramters[0];
						break;
				case 'get':
					return $this->$var;
				}
			}
			return null;
		}
		public function render(array $data, ?string $format = null): mixed
		{
			switch ($format) {
				case ".xml":
					header('Content-type: application/xml');
					render_xml(a_walk($data), "root");
					break;
				case ".json":
					header('Content-type: application/json');
					echo json_encode(a_walk($data));
					break;
				default:
					return $data;
			}
			return null;
		}

		public function loadcurrent_data(array $filters = [], array $fields = [], array $attach = [], array $attach_son = [], bool|array $availabled = false): array|false
		{
			$field = count($fields) ? array_merge($this->field, $fields) : $this->field;
			$filter = count($filters) ? array_merge($this->filter, $filters) : $this->filter;
			return $this->_current_data($filter, $field, $attach, $attach_son, $availabled);
		}
	}
}
