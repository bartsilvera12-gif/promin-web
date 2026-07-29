<?php
// TEMPORAL — diagnóstico de entorno/analítica. Se ELIMINA al finalizar la verificación.
require __DIR__ . '/analytics_lib.php';
header('Content-Type: application/json; charset=utf-8');
$o = [
  'php'     => PHP_VERSION,
  'pdo'     => class_exists('PDO'),
  'drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
];
try {
  $db = an_db();
  $today = date('Y-m-d') . ' 00:00:00';
  $o['sqlite']         = true;
  $o['page_views']     = (int)$db->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();
  $o['visits']         = (int)$db->query('SELECT COUNT(DISTINCT session_id) FROM page_visits')->fetchColumn();
  $o['unique_visitors']= (int)$db->query('SELECT COUNT(DISTINCT visitor_id) FROM page_visits')->fetchColumn();
  $st = $db->prepare('SELECT COUNT(DISTINCT session_id) FROM page_visits WHERE visited_at >= ?'); $st->execute([$today]);
  $o['today_visits']   = (int)$st->fetchColumn();
  $st = $db->prepare('SELECT COUNT(*) FROM page_visits WHERE visited_at >= ?'); $st->execute([$today]);
  $o['today_page_views'] = (int)$st->fetchColumn();
  $o['last'] = $db->query('SELECT visited_at FROM page_visits ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
} catch (Throwable $e) {
  $o['sqlite'] = false;
  $o['err']    = $e->getMessage();
}
echo json_encode($o);
