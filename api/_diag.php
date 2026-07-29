<?php
// TEMPORAL — diagnóstico de entorno para la analítica. Se elimina al finalizar.
require __DIR__ . '/analytics_lib.php';
header('Content-Type: application/json; charset=utf-8');
$o = [
  'php'     => PHP_VERSION,
  'pdo'     => class_exists('PDO'),
  'drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
];
try {
  $db = an_db();
  $o['sqlite'] = true;
  $o['rows']   = (int)$db->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();
  $o['last']   = $db->query('SELECT visited_at FROM page_visits ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
} catch (Throwable $e) {
  $o['sqlite'] = false;
  $o['err']    = $e->getMessage();
}
echo json_encode($o);
