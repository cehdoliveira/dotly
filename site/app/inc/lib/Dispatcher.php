<?php
class Dispatcher
{
	private array $_class_args = [];
	private string $_request_server = "";
	private string $_request_uri = "";
	private string $_path_info = "";
	private array $_file_default_list = ["index", "index.php", "Dispatcher.php", "webapp.php", "index.html"];
	private bool $_rewrite = true;
	private array $_routes = [];

	public function __construct(bool $rewrite = true, array $class_args = [])
	{
		$this->_rewrite = $rewrite;
		$this->_class_args = $class_args;
		$this->_request_server = get_request_server();
		$this->_request_uri = $this->get_request_uri();
		$this->_path_info = $this->get_path_info();
		$this->normalize_request();
	}

	private function normalize_request(): void
	{
		$path_length = strlen($this->_path_info);
		if ($path_length > 0 && $this->_path_info[$path_length - 1] == "/") {
			basic_redir($this->_request_server . rtrim($_SERVER["REQUEST_URI"], "/"));
		}
	}

	public function set_file_default_list(array $value): void
	{
		$this->_file_default_list = array_merge($this->_file_default_list, $value);
	}

	public function get_path_info($levels = false)
	{
		if (! empty($this->_path_info)) {
			$path = $this->_path_info;
		} else {
			$path = getenv("PATH_INFO");
			$path = getenv("REQUEST_URI");
			$path = preg_replace("/^(.+)\?.+$/", "$1", getenv("REQUEST_URI"));
			if ($path == "/") {
				$path = "index.php";
			}
			if (in_array(trim($path, "/"), $this->_file_default_list)) {
				$path = "";
			}
		}
		if ($levels) {
			return (array) explode("/", trim($path, "/"));
		}
		return $path;
	}

	public function get_request_uri(): string
	{
		if (empty($_SERVER["SCRIPT_NAME"])) {
			return "";
		}
		$tmp_script_name = $_SERVER["SCRIPT_NAME"];
		$tmp_file_name = basename($_SERVER["SCRIPT_NAME"]);
		if (in_array($tmp_file_name, $this->_file_default_list)) {
			if ($this->_rewrite) {
				$tmp_script_name = str_replace($tmp_file_name, "", $tmp_script_name);
			}
		}
		return rtrim($tmp_script_name, "/");
	}

	public function get_request_full_uri(): string
	{
		return $this->_request_server . $this->_request_uri;
	}

	public function add_route($http_method, $url_pattern, $exec, $check = null, $args = [], $name = null)
	{
		if (($http_method === "POST" || $http_method === "GET") && ! empty($exec)) {
			$this->_routes[($name == null ? count($this->_routes) : $name)] = [
				"http_method" => $http_method,
				"url_pattern" => $url_pattern,
				"exec" => $exec,
				"check" => $check,
				"args" => is_array($args) ? $args : [$args]
			];
		}
	}

	private function evaluateCheck($check): bool
	{
		if (is_null($check)) {
			return true;
		}

		if (is_callable($check)) {
			return (bool) call_user_func($check);
		}

		return (bool) $check;
	}

	public function exec(): bool
	{
		$server_method = $_SERVER["REQUEST_METHOD"];
		foreach ($this->_routes as $entry) {
			if ($server_method === $entry["http_method"]) {
				if (preg_match("/^" . str_replace("/", "\\/", $entry["url_pattern"]) . "$/", $this->_path_info, $matches)) {
					if (! $this->evaluateCheck($entry["check"])) {
						if (isset($GLOBALS["login_url"])) {
							basic_redir($GLOBALS["login_url"]);
						}
						return false;
					}

					$class = $method_name = NULL;
					if (is_string($entry["exec"])) {
						if (($pos = strpos($entry["exec"], "function:")) !== false) {
							$function_name = substr($entry["exec"], $pos + strlen("function:"));
							if (function_exists($function_name)) {
								$entry["args"] = array_merge($entry["args"], $matches);
								return call_user_func($function_name, $entry["args"]);
							}
						} else {
							$class_method = explode(":", $entry["exec"]);
							if (count($class_method) == 2) {
								list($class_name, $method_name) = $class_method;

								if (class_exists($class_name)) {
									if (empty($this->_class_args)) {
										$class = new $class_name;
									} else {
										$class = new $class_name($this->_class_args);
									}
								}
							}
						}
					}
					$class_address_num_elements = 2;
					if (is_array($entry["exec"]) && count($entry["exec"]) == $class_address_num_elements) {
						list($class, $method_name) = $entry["exec"];
					}
					if (isset($class) && isset($method_name) && is_string($method_name) && is_object($class) && method_exists($class, $method_name)) {
						$matches["server_uri"] = $this->_path_info;
						$matches = array_merge($entry["args"], $matches);
						$class->{$method_name}($matches);
						return true;
					}
				}
			}
		}
		return false;
	}
}
