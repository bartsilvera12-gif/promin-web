<?php
/**
 * ProMin — Biblioteca compartida de analítica de visitas.
 *
 * Privacidad: NUNCA se almacena la IP completa. El visitante se identifica con
 * un hash anónimo (SHA-256 de IP + User-Agent + sal secreta del servidor).
 * No se guardan nombre, correo, ubicación precisa ni datos personales.
 *
 * Almacenamiento: SQLite (PDO) en /data/private/analytics.sqlite  (fuera del
 * alcance web por el .htaccess de esa carpeta; sin credenciales que exponer).
 */

date_default_timezone_set('America/Sao_Paulo'); // BR/PR

function an_root() { return dirname(__DIR__); }
function an_priv() { return an_root() . '/data/private'; }
function an_now()  { return date('Y-m-d H:i:s'); }

/** Asegura la carpeta privada + su .htaccess (deny). */
function an_ensure_priv() {
  $dir = an_priv();
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $ht = $dir . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
  }
  return $dir;
}

/** Conexión SQLite (crea tabla e índices si no existen). Lanza si falta el driver. */
function an_db() {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('sqlite-unavailable');
  }
  $dir = an_ensure_priv();
  $pdo = new PDO('sqlite:' . $dir . '/analytics.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  @$pdo->exec('PRAGMA busy_timeout=4000');
  @$pdo->exec('PRAGMA journal_mode=WAL');
  $pdo->exec("CREATE TABLE IF NOT EXISTS page_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitor_id  TEXT NOT NULL,
    session_id  TEXT NOT NULL,
    page_path   TEXT NOT NULL,
    referrer    TEXT,
    device_type TEXT,
    browser     TEXT,
    visited_at  TEXT NOT NULL
  )");
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visited_at ON page_visits(visited_at)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_path  ON page_visits(page_path)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitor_id ON page_visits(visitor_id)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_session_id ON page_visits(session_id)');
  return $pdo;
}

/** Sal secreta persistente (solo del lado del servidor; nunca viaja al cliente). */
function an_salt() {
  static $salt = null;
  if ($salt !== null) return $salt;
  an_ensure_priv();
  $f = an_priv() . '/analytics.salt';
  $s = @file_get_contents($f);
  if ($s === false || strlen(trim($s)) < 16) {
    $s = bin2hex(random_bytes(32));
    @file_put_contents($f, $s, LOCK_EX);
    @chmod($f, 0600);
  }
  return $salt = trim($s);
}

/** IP del cliente — SOLO para el hash anónimo; jamás se almacena. */
function an_client_ip() {
  return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Identificador anónimo e irreversible del visitante. */
function an_visitor_id($ip, $ua) {
  return substr(hash('sha256', $ip . '|' . $ua . '|' . an_salt()), 0, 40);
}

/** Detección de bots/crawlers conocidos por User-Agent. */
function an_is_bot($ua) {
  if ($ua === '') return true;
  return (bool) preg_match(
    '/bot|crawl|spider|slurp|mediapartners|adsbot|bingpreview|facebookexternalhit|whatsapp|telegram|embedly|quora|pinterest|headless|phantom|puppeteer|playwright|python-requests|curl|wget|libwww|go-http|okhttp|java\/|apache-httpclient|scrapy|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu|sogou|uptime|monitor|pingdom|gtmetrix|lighthouse|chrome-lighthouse|censys|masscan|zgrab/i',
    $ua
  );
}

/** Dispositivo general: mobile | tablet | desktop. */
function an_device($ua) {
  if (preg_match('/ipad|tablet|playbook|silk|kindle|(android(?!.*mobile))/i', $ua)) return 'tablet';
  if (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|blackberry|bb10|opera mini|iemobile/i', $ua)) return 'mobile';
  return 'desktop';
}

/** Navegador general. */
function an_browser($ua) {
  if (preg_match('/Edg(e|A|iOS)?\//i', $ua))              return 'Edge';
  if (preg_match('/OPR\/|Opera/i', $ua))                  return 'Opera';
  if (preg_match('/SamsungBrowser/i', $ua))               return 'Samsung Internet';
  if (preg_match('/Firefox\/|FxiOS/i', $ua))              return 'Firefox';
  if (preg_match('/CriOS\/|Chrome\//i', $ua))             return 'Chrome';
  if (preg_match('/Version\/.*Safari/i', $ua))            return 'Safari';
  return 'Otro';
}

/** Ruta de página válida y segura (sin path traversal, longitud acotada). */
function an_valid_path($p) {
  if (!is_string($p) || $p === '' || strlen($p) > 255) return false;
  if ($p[0] !== '/') return false;
  if (strpos($p, '..') !== false) return false;
  return (bool) preg_match('#^/[A-Za-z0-9._~%/\-]*$#', $p);
}
