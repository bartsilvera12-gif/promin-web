<?php
/**
 * ProMin — Estadísticas de visitas (endpoint PROTEGIDO, solo admin).
 * Reutiliza la sesión administrativa que crea api/gallery.php ($_SESSION['gal_admin']).
 * Sin sesión válida → 401. Nunca registra visitas; solo consulta.
 */
require __DIR__ . '/../analytics_lib.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function vs_out($a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if (empty($_SESSION['gal_admin'])) vs_out(['ok' => false, 'error' => 'auth'], 401);

$range = (string)($_GET['range'] ?? '30');
if (!in_array($range, ['today', '7', '30', 'all'], true)) $range = '30';

try {
  $db    = an_db();
  $today = date('Y-m-d');

  $distinctSessionsSince = function ($since) use ($db) {
    $st = $db->prepare('SELECT COUNT(DISTINCT session_id) FROM page_visits WHERE visited_at >= ?');
    $st->execute([$since]);
    return (int)$st->fetchColumn();
  };

  // --- Métricas fijas de las tarjetas ---
  $today_visits   = $distinctSessionsSince($today . ' 00:00:00');
  $last_7_days    = $distinctSessionsSince(date('Y-m-d', strtotime('-6 days'))  . ' 00:00:00');
  $last_30_days   = $distinctSessionsSince(date('Y-m-d', strtotime('-29 days')) . ' 00:00:00');
  $total_visits   = (int)$db->query('SELECT COUNT(DISTINCT session_id) FROM page_visits')->fetchColumn();
  $unique_visitors= (int)$db->query('SELECT COUNT(DISTINCT visitor_id) FROM page_visits')->fetchColumn();
  $page_views     = (int)$db->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();

  // --- Rango seleccionado (gráfico + top páginas) ---
  if ($range === 'today') {
    $rangeStart = $today . ' 00:00:00';
    $chartDays  = 1;
  } elseif ($range === 'all') {
    $first      = $db->query('SELECT MIN(visited_at) FROM page_visits')->fetchColumn();
    $rangeStart = $first ?: ($today . ' 00:00:00');
    $chartDays  = $first ? ((int)floor((strtotime($today) - strtotime(substr($first, 0, 10))) / 86400) + 1) : 1;
    $chartDays  = max(1, min(180, $chartDays)); // tope defensivo
  } else {
    $days       = (int)$range;
    $chartDays  = $days;
    $rangeStart = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00';
  }

  // Serie diaria (rellenando días sin datos con 0).
  $st = $db->prepare(
    'SELECT substr(visited_at,1,10) d, COUNT(DISTINCT session_id) v, COUNT(*) pv
     FROM page_visits WHERE visited_at >= ? GROUP BY d'
  );
  $st->execute([$rangeStart]);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['d']] = ['v' => (int)$r['v'], 'pv' => (int)$r['pv']];
  $daily = [];
  for ($i = $chartDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[] = ['date' => $d, 'visits' => $map[$d]['v'] ?? 0, 'page_views' => $map[$d]['pv'] ?? 0];
  }

  // Top páginas dentro del rango.
  $st = $db->prepare(
    'SELECT page_path, COUNT(*) views FROM page_visits WHERE visited_at >= ?
     GROUP BY page_path ORDER BY views DESC LIMIT 10'
  );
  $st->execute([$rangeStart]);
  $top = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $top[] = ['page' => $r['page_path'], 'views' => (int)$r['views']];

  vs_out([
    'ok'              => true,
    'today'           => $today_visits,
    'last_7_days'     => $last_7_days,
    'last_30_days'    => $last_30_days,
    'total_visits'    => $total_visits,
    'unique_visitors' => $unique_visitors,
    'page_views'      => $page_views,
    'range'           => $range,
    'daily'           => $daily,
    'top_pages'       => $top,
  ]);
} catch (Throwable $e) {
  vs_out(['ok' => false, 'error' => 'stats', 'detail' => $e->getMessage()]);
}
