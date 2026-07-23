<?php

/**
 * Autoload de classes
 * Carrega automaticamente classes de controller, lib ou model quando são instanciadas
 */
function m_autoload(string $name): bool
{
  // Ignorar classes do namespace ou do Composer
  if (strpos($name, '\\') !== false || strpos($name, 'Composer') !== false) {
    return false;
  }

  // Verificar se constante necessária existe (modo CLI pode não ter)
  if (!defined('cRootServer_APP')) {
    return false;
  }

  try {
    foreach (["controller", "lib", "model"] as $dir) {
      $file = sprintf(
        "%s/inc/%s/%s.php",
        constant("cRootServer_APP"),
        $dir,
        $name
      );

      if (file_exists($file)) {
        if (! class_exists($name, false)) {
          require_once($file);
        }
        return true;
      }
    }
    throw new Exception('Class ' . $name . ' not exists');
  } catch (Exception $e) {
    error_log("Autoload Error: " . $e->getMessage());
    return false;
  }
}
spl_autoload_register('m_autoload');

/**
 * Gera uma chave aleatoria criptograficamente segura.
 * Retorna uma string hexadecimal de tamanho definido (padrao: 10 caracteres)
 */
function generate_key(int $size = 10): string
{
  return substr(bin2hex(random_bytes((int)ceil($size / 2))), 0, $size);
}

/**
 * Converte entidades HTML para caracteres acentuados
 * Transforma &aacute; em á, &ccedil; em ç, etc
 */
function html_accents(?string $text = null): string
{
  return strtr($text ?? '',  array_flip($GLOBALS["html_entities_list"]));
}

/**
 * Converte texto para maiúsculas mantendo acentos
 * Transforma "josé" em "JOSÉ" preservando acentuação
 */
function up_accents(?string $text = null): string
{
  return strtoupper(strtr($text ?? '', $GLOBALS["accents_lists"]));
}

/**
 * Converte texto para minúsculas mantendo acentos
 * Transforma "JOSÉ" em "josé" preservando acentuação
 */
function down_accents(?string $text = null): string
{
  return strtolower(strtr($text ?? '', array_flip($GLOBALS["accents_lists"])));
}

/**
 * Remove todos os acentos de um texto
 * Transforma "José" em "Jose", "São" em "Sao", etc
 */
function remove_accents(?string $text = null): string
{
  return  strtr($text ?? '', $GLOBALS["withoutaccents_lists"]);
}

/**
 * Gera slug amigável para URLs
 * Converte "São Paulo" em "sao-paulo", remove acentos e caracteres especiais
 */
function generate_slug(?string $text = null): string
{
  $text = strtolower(remove_accents($text ?? ''));
  $text = preg_replace("/[^0-9A-z]+/", "_", $text);
  $text = preg_replace("/\s+?|_+|-+/", "-", $text);
  return $text;
}

/**
 * Monta URL com parâmetros GET
 * Adiciona ou substitui parâmetros na query string da URL
 */
function set_url(string $url = "", array $params = []): string
{
  $tmp = preg_split('/\?/', $url);
  if ($tmp === false) {
    return $url;
  }
  $url = $tmp[0];
  $p = "";
  if (isset($tmp[1])) {
    $p .= "?";
    foreach (explode("&", $tmp[1]) as $tmp_params) {
      if ($tmp_params === "") {
        continue;
      }
      $parts = explode("=", $tmp_params, 2);
      $kp = $parts[0];
      $vp = $parts[1] ?? "";
      if (! array_key_exists($kp, $params)) {
        $p .= $kp . "=" . $vp . "&";
      }
    }
  }
  foreach ($params as $kp => $vp) {
    if (is_array($vp)) {
      // Valor multi (ex.: status[]=a&status[]=b) — repete a chave com sufixo [].
      foreach ($vp as $v) {
        if ($p == "") {
          $p = "?";
        }
        $p .= $kp . "[]=" . urlencode((string) $v) . "&";
      }
      continue;
    }
    if ($p == "") {
      $p = "?";
    }
    $p .= $kp . "=" . $vp . "&";
  }
  $p = preg_replace("/\&$/", "", $p);
  return $url . $p;
}

/**
 * Redireciona para outra URL
 * Realiza redirecionamento com código HTTP (302 temporário por padrão).
 *
 * IMPORTANTE: usa header("Location: ...") em vez de JavaScript para garantir
 * que o cookie de sessão seja enviado corretamente e que não haja race condition
 * entre a escrita da sessão no Redis e o próximo request do browser.
 */
function basic_redir(string|array $url, int $code = 302, bool $use_html = false, bool $rollback = false): never
{
  if (is_array($url)) {
    $url = $url[0];
  }

  try {
    if ($rollback) {
      localPDO::getInstance()->rollback();
    } else {
      localPDO::getInstance()->commit();
    }
  } catch (\Throwable) {
    // localPDO might not be initialized if no DB ops occurred
  }

  // Cache-Control: no-store impede que o browser cache 302s.
  // Sem isso, um redirect para /login poderia ser cacheado e reproduzido
  // em requisições futuras mesmo com o usuário já autenticado.
  if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
  }

  if ($use_html) {
    $dir = constant("AppLayout");
    ob_start();
    switch ($code) {
      case 301:
        require($dir . "301.html");
        break;
      case 404:
        require($dir . "404.html");
        break;
      default:
        require($dir . "302.html");
        break;
    }
    $out = ob_get_contents();
    ob_end_clean();
    print str_replace("{location}", $url, $out);
  } else {
    header("Location: " . $url, true, $code);
  }
  exit();
}

/**
 * Retorna URL completa do servidor
 * Monta http://exemplo.com ou https://exemplo.com com porta se necessário
 */
function get_request_server(string|false $auth = false, ?bool $https = null): string
{
  $server_name = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_NAME")))), 0, 255);
  $server_protocol = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_PROTOCOL")))), 0, 255);
  $server_port = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_PORT")))), 0, 255);

  if (strtoupper(getenv("HTTPS")) == strtoupper("on") || (isset($https) && $https == true)) {
    $server_protocol = "https";
    $server_port = "443";
  }

  if (isset($https) && $https === false) {
    $server_protocol = "http";
    if ($server_port == "443") {
      $server_port = "80";
    }
  }

  list($server_protocol,) = explode("/", $server_protocol);
  $server_protocol = strtolower($server_protocol);

  if ($auth !== false) {
    $request_server = $server_protocol . "://" . $auth . "@" . $server_name;
  } else {
    $request_server = $server_protocol . "://" . $server_name;
  }

  if ($server_port != "80" && $server_protocol == "http") {
    $request_server .= ":" . $server_port;
  }

  if ($server_port != "443" && $server_protocol == "https") {
    $request_server .= ":" . $server_port;
  }
  return $request_server;
}

/**
 * Converte array para UTF-8 recursivamente
 * Percorre todo o array e garante encoding UTF-8 em todos os valores
 */
function a_walk(array &$array): array
{
  foreach ($array as $k => $v) {
    if (is_array($v)) {
      $array[$k] = a_walk($v);
    } elseif ($v !== null) {
      // toUtf8() exige string; null passa direto (vira `null` no JSON, nao
      // um TypeError) — colunas de banco opcionais (dosage, purity_label
      // etc.) chegam null com frequencia em qualquer json_response().
      $array[$k] = toUtf8($v);
    }
  }
  return $array;
}

/**
 * Converte string para UTF-8 se necessário
 * Detecta se já está em UTF-8, caso contrário converte
 */
function toUtf8(string $item): string
{
  return preg_match('%(?:
        [\xC2-\xDF][\x80-\xBF]        # non-overlong 2-byte
        |\xE0[\xA0-\xBF][\x80-\xBF]               # excluding overlongs
        |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}      # straight 3-byte
        |\xED[\x80-\x9F][\x80-\xBF]               # excluding surrogates
        |\xF0[\x90-\xBF][\x80-\xBF]{2}    # planes 1-3
        |[\xF1-\xF3][\x80-\xBF]{3}                  # planes 4-15
        |\xF4[\x80-\x8F][\x80-\xBF]{2}    # plane 16
        )+%xs', $item) == 1 ? $item : mb_convert_encoding($item, 'UTF-8', 'ISO-8859-1');
}

// Variáveis de ambiente do servidor
$remote_address = substr(addslashes(stripslashes(strip_tags(getenv("REMOTE_ADDR")))), 0, 15);
$http_user_agent = substr(addslashes(stripslashes(strip_tags(getenv("HTTP_USER_AGENT")))), 0, 255);
$referrer = substr(addslashes(stripslashes(strip_tags(getenv("HTTP_REFERER")))), 0, 255);
$request_uri = substr(addslashes(stripslashes(strip_tags(getenv("SCRIPT_NAME")))), 0, 255);
$request_server = get_request_server();
$path_info = getenv("PATH_INFO");

/**
 * Exibe notificações armazenadas na sessão
 * Mostra mensagens de sucesso, erro, etc em formato Bootstrap alert
 */
function html_notification_print(): void
{
  if (isset($_SESSION["messages_app"])) {
    foreach ($_SESSION["messages_app"] as $type => $context) {
      $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
      print('<div class="text-center alert alert-' . $safeType . '" role="alert">');
      print(implode("<br>", array_map(fn($m) => htmlspecialchars($m, ENT_QUOTES, 'UTF-8'), $context)));
      print('</div>');
    }
    unset($_SESSION["messages_app"]);
  }
}

/**
 * Sanitiza uma string removendo acentos e caracteres indesejados.
 * Se `$digitsOnly` for true, retorna apenas os dígitos (útil para campos numéricos).
 * Pode ser reutilizada para limpar outros campos antes de salvar ou comparar.
 */
function sanitize_string(mixed $value, bool $digitsOnly = false): ?string
{
  if (!isset($value)) {
    return null;
  }

  // Garantir string
  $value = trim((string)$value);

  // Remover entidades HTML e acentos
  $value = html_accents($value);
  $value = remove_accents($value);

  if ($digitsOnly) {
    // Retornar apenas números
    return preg_replace('/\D+/', '', $value);
  }

  // Remover caracteres não alfanuméricos (manter letras e números)
  $value = preg_replace('/[^\p{L}0-9]+/u', '', $value);

  return $value;
}

function render_xml(array $data, string $root): void
{
  $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
  array_to_xml($data, $xml);
  echo $xml->asXML();
}

function array_to_xml(array $data, SimpleXMLElement &$xml): void
{
  foreach ($data as $key => $value) {
    $key = is_int($key) ? 'item' : $key;
    if (is_array($value)) {
      $child = $xml->addChild($key);
      array_to_xml($value, $child);
    } else {
      $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
    }
  }
}

function validate_csrf(?string $token, string $redirectUrl): void
{
  $now = time();
  $graceSeconds = 10;
  $validTokens = [];

  if (isset($_SESSION['_csrf_token'])) {
    $validTokens[] = $_SESSION['_csrf_token'];
  }

  if (!empty($_SESSION['_csrf_used']) && is_array($_SESSION['_csrf_used'])) {
    foreach ($_SESSION['_csrf_used'] as $usedToken => $usedAt) {
      if ($now - $usedAt <= $graceSeconds) {
        $validTokens[] = $usedToken;
      } else {
        unset($_SESSION['_csrf_used'][$usedToken]);
      }
    }
  }

  $isValid = false;
  foreach ($validTokens as $validToken) {
    if (!empty($token) && hash_equals($validToken, $token)) {
      $isValid = true;
      break;
    }
  }

  if (!$isValid) {
    $_SESSION["messages_app"]["danger"] = ["Requisição inválida. Tente novamente."];
    basic_redir($redirectUrl);
  }

  if (isset($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_used'][$_SESSION['_csrf_token']] = $now;
    unset($_SESSION['_csrf_token']);
  }
}

function canonical_url(string $canonicalConstant): string
{
  if (defined($canonicalConstant) && constant($canonicalConstant) !== '') {
    return rtrim(constant($canonicalConstant), '/');
  }

  // cFrontend só é confiável se já tiver sido validado contra ALLOWED_HOSTS no bootstrap.
  if (defined('ALLOWED_HOSTS') && constant('ALLOWED_HOSTS') !== '') {
    return rtrim(constant('cFrontend'), '/');
  }

  // Fail closed: sem URL canônica e sem ALLOWED_HOSTS, cFrontend deriva de HTTP_HOST
  // (controlável pelo atacante) — recusar em vez de gerar link envenenável.
  Logger::getInstance()->error("canonical_url sem configuração segura — recusando", [
    "constant" => $canonicalConstant,
    "host"     => $_SERVER['HTTP_HOST'] ?? 'unknown',
  ]);
  throw new RuntimeException(
    "URL canônica não configurada: defina {$canonicalConstant} ou ALLOWED_HOSTS no kernel.php"
  );
}

function check_rate_limit(?object $redis, string $key, int $max): bool
{
  if (!$redis) return false;
  return (int)($redis->get($key) ?? 0) >= $max;
}

function increment_rate_limit(?object $redis, string $key, int $window): void
{
  if (!$redis) return;
  // RedisCache expoe increment(), nao incr() — mesmo bug corrigido em
  // check_and_increment_rate_limit() (ver comentario la). Esta funcao nao tem
  // nenhuma chamada em controller/teste hoje — nunca foi exercida de verdade.
  $count = $redis->increment($key);
  if ($count === 1) {
    $redis->expire($key, $window);
  }
}

function ratelimit_fallback_dir(): string
{
  if (defined('RATELIMIT_FALLBACK_DIR') && constant('RATELIMIT_FALLBACK_DIR') !== '') {
    return rtrim(constant('RATELIMIT_FALLBACK_DIR'), '/');
  }
  return sys_get_temp_dir() . '/infinnityimportacao_ratelimit';
}

// Atomic check+increment: increments first to prevent race condition bypass.
// Returns true if the new count exceeds $max (i.e., request should be blocked).
// Falls back to file-based locking when Redis is unavailable.
//
// Design choice — fail-open: if both Redis AND the file fallback are unavailable,
// the function returns false (does not block). This prioritizes availability over
// rate-limit enforcement during infrastructure outages. A warning is logged so
// operators can detect when rate limiting is completely bypassed.
function check_and_increment_rate_limit(?object $redis, string $key, int $max, int $window): bool
{
  // $redis pode ser um objeto existente mas desconectado (ex.: CI sem extensao
  // redis instalada, ou Redis fora do ar) — nesse caso if ($redis) sozinho e
  // verdadeiro (objeto truthy) mas setIfNotExists()/increment() retornam false
  // internamente, e `false > $max` sempre da false: o rate limit fica
  // permanentemente sem efeito, sem nunca cair no fallback de arquivo abaixo.
  // Achado ao rodar em CI (kernel.php.example, sem extensao redis).
  if ($redis && (!method_exists($redis, 'isConnected') || $redis->isConnected())) {
    // Atomico: setIfNotExists() (SET NX EX) so cria a chave com count=1 se ela
    // nao existir, ja com TTL na mesma chamada — elimina a janela de crash de um
    // incr()+expire() em duas chamadas separadas (se o processo morresse entre
    // as duas, a chave ficava sem TTL e bloqueava aquela chave pra sempre, exigindo
    // DEL manual no Redis). So incrementa via increment() quando a chave ja existe
    // (e portanto ja tem TTL valido de quando foi criada).
    if ($redis->setIfNotExists($key, 1, $window)) {
      return 1 > $max;
    }
    $count = $redis->increment($key);
    return $count > $max;
  }

  $dir = ratelimit_fallback_dir();
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
      Logger::getInstance()->warning("Rate limit fallback dir unavailable", [
        "dir"    => $dir,
        "key"    => $key,
      ]);
      return false;
    }
  }
  $file = $dir . '/' . md5($key) . '.lock';

  $fp = @fopen($file, 'c+');
  if (!$fp) {
    Logger::getInstance()->warning("Rate limit fallback file open failed", [
      "file"   => $file,
      "key"    => $key,
    ]);
    return false;
  }

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    Logger::getInstance()->warning("Rate limit fallback lock failed", [
      "file"   => $file,
      "key"    => $key,
    ]);
    return false;
  }

  $raw = '';
  while (!feof($fp)) {
    $raw .= fread($fp, 8192);
  }
  $data = @json_decode($raw ?: '{}', true) ?: [];
  $now = time();

  if (!isset($data['window_start']) || ($now - $data['window_start']) > $window) {
    $data = ['count' => 1, 'window_start' => $now];
  } else {
    $data['count']++;
  }

  $blocked = $data['count'] > $max;

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return $blocked;
}

function reset_rate_limit(?object $redis, string $key): void
{
  if ($redis) {
    $redis->delete($key);
    return;
  }

  $dir = ratelimit_fallback_dir();
  $file = $dir . '/' . md5($key) . '.lock';
  @unlink($file);
}

function verify_password_with_migration(string $storedHash, string $inputPassword, string $userId): bool
{
  if (password_get_info($storedHash)['algoName'] !== 'unknown') {
    return password_verify($inputPassword, $storedHash);
  }
  if (strlen($inputPassword) > 1024) return false;
  $authenticated = hash_equals($storedHash, md5($inputPassword));
  if ($authenticated) {
    $m = new users_model();
    $m->set_filter(["idx = ?"], [$userId]);
    $m->populate(["password" => password_hash($inputPassword, PASSWORD_BCRYPT)]);
    $m->save();
  }
  return $authenticated;
}

function handle_upload(array $file, string $subDir, array $options = []): string|false
{
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    Logger::getInstance()->warning("Upload error", ["error_code" => $file['error'] ?? 'no_file']);
    return false;
  }

  if (!is_uploaded_file($file['tmp_name'])) {
    Logger::getInstance()->warning("Upload bypass attempt", ["name" => $file['name'] ?? 'unknown']);
    return false;
  }

  $subDir = trim($subDir, '/');
  if ($subDir === '' || str_contains($subDir, '..') || str_contains($subDir, '\\')) {
    Logger::getInstance()->warning("Upload invalid subDir", ["subDir" => $subDir]);
    return false;
  }

  $allowedStr = $options['allowed_types']
    ?? (defined('UPLOAD_ALLOWED_TYPES') ? constant('UPLOAD_ALLOWED_TYPES') : 'jpg,jpeg,png,gif,pdf');
  $allowedTypes = array_map('trim', explode(',', strtolower($allowedStr)));
  $maxSize  = ($options['max_size_mb'] ?? (defined('UPLOAD_MAX_SIZE') ? constant('UPLOAD_MAX_SIZE') : 10)) * 1024 * 1024;
  $convert    = $options['convert'] ?? null;
  $maxWidth   = $options['max_width'] ?? 0;
  $maxHeight  = $options['max_height'] ?? 0;
  $quality    = $options['quality'] ?? 80;

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $mimeMap = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/avif'    => 'avif',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/csv'      => 'csv',
  ];

  $realExt = $mimeMap[$mime] ?? null;
  if (!$realExt || !in_array($realExt, $allowedTypes)) {
    Logger::getInstance()->warning("Upload invalid type", [
      "mime"        => $mime,
      "allowed"     => $allowedTypes,
      "name"        => $file['name'] ?? '',
    ]);
    return false;
  }

  if ($file['size'] > $maxSize) {
    Logger::getInstance()->warning("Upload size exceeded", [
      "size"    => $file['size'],
      "max"     => $maxSize,
      "name"    => $file['name'] ?? '',
    ]);
    return false;
  }

  $uploadBase = defined('UPLOAD_DIR') ? rtrim(constant('UPLOAD_DIR'), '/') : sys_get_temp_dir() . '/infinnityimportacao_upload';
  $uploadDir  = $uploadBase . '/' . $subDir;

  if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    Logger::getInstance()->error("Upload dir creation failed", ["dir" => $uploadDir]);
    return false;
  }

  $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
  $originalName = generate_slug($originalName);
  $originalName = substr($originalName, 0, 80) ?: 'arquivo';

  $isImage     = str_starts_with($mime, 'image/');
  $shouldConvert = $convert && $isImage && extension_loaded('gd');
  $targetExt   = $shouldConvert ? $convert : $realExt;
  $filename    = sprintf('%s_%s.%s', $originalName, date('Y-m-d_H-i-s'), $targetExt);
  $destPath    = $uploadDir . '/' . $filename;

  if ($shouldConvert) {
    try {
      $srcImage = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/gif'  => imagecreatefromgif($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        'image/avif' => function_exists('imagecreatefromavif') ? imagecreatefromavif($file['tmp_name']) : false,
        default      => false,
      };

      if (!$srcImage) {
        move_uploaded_file($file['tmp_name'], $destPath);
        return '/assets/upload/' . $subDir . '/' . $filename;
      }

      $origW = imagesx($srcImage);
      $origH = imagesy($srcImage);

      if ($maxWidth > 0 || $maxHeight > 0) {
        $targetW = $maxWidth > 0 ? min($maxWidth, $origW) : $origW;
        $targetH = $maxHeight > 0 ? min($maxHeight, $origH) : $origH;
        $ratio   = min($targetW / $origW, $targetH / $origH);
        $newW    = (int)($origW * $ratio);
        $newH    = (int)($origH * $ratio);

        $dstImage = imagecreatetruecolor($newW, $newH);
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImage);
        $srcImage = $dstImage;
      }

      if ($convert === 'webp') {
        imagewebp($srcImage, $destPath, $quality);
      } elseif ($convert === 'avif' && function_exists('imageavif')) {
        imageavif($srcImage, $destPath, $quality);
      } else {
        imagejpeg($srcImage, $destPath, $quality);
      }

      imagedestroy($srcImage);
      return '/assets/upload/' . $subDir . '/' . $filename;
    } catch (\Throwable $e) {
      Logger::getInstance()->error("Image conversion failed", [
        "error" => $e->getMessage(),
        "name"  => $file['name'] ?? '',
      ]);
      @unlink($destPath);
      return false;
    }
  }

  if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    Logger::getInstance()->error("Upload move failed", ["dest" => $destPath]);
    return false;
  }

  return '/assets/upload/' . $subDir . '/' . $filename;
}

function time_ago(?string $datetime): string
{
  if (empty($datetime)) {
    return '—';
  }

  $then = strtotime($datetime);
  if ($then === false || $then < 0) {
    return '—';
  }

  $now     = time();
  $diff    = $now - $then;
  $isFuture = $diff < 0;
  $seconds  = abs($diff);

  if ($seconds < 60) {
    return 'agora mesmo';
  }

  $minutes = (int)($seconds / 60);
  if ($minutes === 1) {
    return $isFuture ? 'em 1 minuto' : 'há 1 minuto';
  }
  if ($minutes < 60) {
    return $isFuture ? "em {$minutes} minutos" : "há {$minutes} minutos";
  }

  $hours = (int)($minutes / 60);
  if ($hours === 1) {
    return $isFuture ? 'em 1 hora' : 'há 1 hora';
  }
  if ($hours < 24) {
    return $isFuture ? "em {$hours} horas" : "há {$hours} horas";
  }

  $days = (int)($hours / 24);
  if ($days === 1) {
    $time = date('H:i', $then);
    return $isFuture ? "amanhã às {$time}" : "ontem às {$time}";
  }
  if ($days < 7) {
    return $isFuture ? "em {$days} dias" : "há {$days} dias";
  }

  if ($days < 30) {
    $weeks = (int)($days / 7);
    if ($weeks === 1) {
      return $isFuture ? 'em 1 semana' : 'há 1 semana';
    }
    return $isFuture ? "em {$weeks} semanas" : "há {$weeks} semanas";
  }

  if ($days < 365) {
    $months = (int)($days / 30);
    if ($months === 1) {
      return $isFuture ? 'em 1 mês' : 'há 1 mês';
    }
    return $isFuture ? "em {$months} meses" : "há {$months} meses";
  }

  $years = (int)($days / 365);
  if ($years === 1) {
    return $isFuture ? 'em 1 ano' : 'há 1 ano';
  }
  return $isFuture ? "em {$years} anos" : "há {$years} anos";
}

function str_limit(?string $value, int $limit = 100, string $end = '...'): string
{
  if ($value === null || $value === '') {
    return '';
  }

  $value = strip_tags($value);

  if (mb_strlen($value) <= $limit) {
    return $value;
  }

  return mb_substr($value, 0, $limit) . $end;
}

function old(string $key, mixed $default = ''): string
{
  $value = $_POST[$key] ?? $default;

  if (is_string($value)) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csv_sanitize_cell(mixed $value): string
{
  $value = (string) ($value ?? '');
  if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
    return "'" . $value;
  }
  return $value;
}

function array_to_csv(array $data, string $filename = 'export.csv', ?array $headers = null): never
{
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
  header('Cache-Control: no-store, no-cache');
  header('Pragma: no-cache');

  $output = fopen('php://output', 'w');

  fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8: Excel pt-BR lê acentos corretamente

  if (empty($data)) {
    fclose($output);
    exit();
  }

  if ($headers === null) {
    $headers = array_keys(reset($data));
  }

  fputcsv($output, $headers, ';', '"', '\\');

  foreach ($data as $row) {
    $csvRow = [];
    foreach ($headers as $key) {
      $csvRow[] = csv_sanitize_cell($row[$key] ?? '');
    }
    fputcsv($output, $csvRow, ';', '"', '\\');
  }

  fclose($output);
  exit();
}

function json_response(mixed $data, int $code = 200): never
{
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache');
  header('Pragma: no-cache');

  $data = is_array($data) ? $data : ['data' => $data];
  $json = json_encode(
    a_walk($data),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );

  if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON encoding failed']);
    exit();
  }

  echo $json;
  exit();
}

function random_token(int $bytes = 32): string
{
  return bin2hex(random_bytes($bytes));
}

/**
 * Valida formato de slug: minúsculas/dígitos, separados por '-' ou '_',
 * sem separador nas pontas nem duplicado. Ex.: 'admin', 'meu-perfil', 'a_b1'.
 */
function valid_slug(?string $slug): bool
{
  return $slug !== null && preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $slug) === 1;
}

/**
 * Valida CPF pelo algoritmo modulo 11 da Receita Federal.
 * Aceita entrada com ou sem mascara (so os digitos sao considerados).
 * Rejeita comprimento != 11 e sequencias de digitos repetidos
 * (111.111.111-11 passa no modulo 11 aritmetico, mas e invalido).
 */
function validate_cpf(string $cpf): bool
{
  $cpf = preg_replace('/\D/', '', $cpf) ?? '';

  if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
    return false;
  }

  for ($t = 9; $t < 11; $t++) {
    $sum = 0;
    for ($i = 0; $i < $t; $i++) {
      $sum += (int)$cpf[$i] * (($t + 1) - $i);
    }
    $digit = ((10 * $sum) % 11) % 10;
    if ((int)$cpf[$t] !== $digit) {
      return false;
    }
  }

  return true;
}


