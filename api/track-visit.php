<?php
/**
 * ProMin — Registro de visitas (endpoint PÚBLICO, solo POST).
 * NUNCA consulta estadísticas. NUNCA bloquea la carga de la página:
 * ante cualquier error responde 200 y sigue.
 */
require __DIR__ . '/analytics_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function tv_out($a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tv_out(['ok' => false, 'error' => 'method'], 405);

$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);

// Entrada: JSON (recomendado) o formulario.
$in  = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) { $j = json_decode($raw, true); if (is_array($j)) $in = $j; }
if (!$in) $in = $_POST;

$page     = (string)($in['page'] ?? '');
$referrer = trim((string)($in['referrer'] ?? ''));
$referrer = ($referrer !== '') ? substr($referrer, 0, 500) : null;

// Validaciones de entrada.
if (!an_valid_path($page))          tv_out(['ok' => false, 'reason' => 'path']);      // ruta inválida → no registra
if (preg_match('/admin/i', $page))  tv_out(['ok' => true, 'skipped' => 'admin']);     // no rastreamos el panel
if (an_is_bot($ua))                 tv_out(['ok' => true, 'bot' => true]);            // bots → ok pero sin registrar

try {
  $db  = an_db();
  $vid = an_visitor_id(an_client_ip(), $ua);
  $now = an_now();

  // Rate limit básico: máx. 60 registros por visitante en 60 s.
  $st = $db->prepare('SELECT COUNT(*) FROM page_visits WHERE visitor_id = ? AND visited_at >= ?');
  $st->execute([$vid, date('Y-m-d H:i:s', strtotime('-60 seconds'))]);
  if ((int)$st->fetchColumn() >= 60) tv_out(['ok' => true, 'skipped' => 'rate']);

  // Anti-duplicado inmediato: mismo visitante + misma ruta en < 3 s.
  $st = $db->prepare('SELECT visited_at FROM page_visits WHERE visitor_id = ? AND page_path = ? ORDER BY id DESC LIMIT 1');
  $st->execute([$vid, $page]);
  $lastSame = $st->fetchColumn();
  if ($lastSame && (strtotime($now) - strtotime($lastSame)) < 3) tv_out(['ok' => true, 'skipped' => 'dup']);

  // Sesión: reutilizar la última si la visita previa fue hace < 30 min; si no, nueva sesión.
  $st = $db->prepare('SELECT session_id, visited_at FROM page_visits WHERE visitor_id = ? ORDER BY id DESC LIMIT 1');
  $st->execute([$vid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $sid = ($row && (strtotime($now) - strtotime($row['visited_at'])) < 1800)
       ? $row['session_id']
       : 's' . bin2hex(random_bytes(12));

  // Inserción con consulta preparada.
  $st = $db->prepare(
    'INSERT INTO page_visits (visitor_id, session_id, page_path, referrer, device_type, browser, visited_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
  );
  $st->execute([$vid, $sid, $page, $referrer, an_device($ua), an_browser($ua), $now]);

  tv_out(['ok' => true]);
} catch (Throwable $e) {
  // El registro puede fallar sin afectar al visitante.
  tv_out(['ok' => false, 'error' => 'store']);
}
