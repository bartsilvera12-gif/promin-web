<?php
/**
 * ProMin — endpoint de subida de imágenes del sitio.
 *
 * Guarda las imágenes en /uploads/site/<slot>.<ext> (carpeta ignorada por git,
 * así sobrevive a cada deploy) y mantiene un manifest.json que las páginas leen
 * para reemplazar la imagen por defecto por la subida.
 *
 * Seguridad (sitio pequeño, sin backend de usuarios):
 *  - token compartido (deber coincidir con el del panel admin),
 *  - lista blanca de "slots" (no se pueden crear archivos arbitrarios),
 *  - solo imágenes reales (getimagesize) y tipos jpg/png/webp,
 *  - límite de tamaño. No hay riesgo de path traversal: el nombre lo fija el server.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const PM_TOKEN = 'pm_up_9Fk27Rd4Qz';                 // <-- debe coincidir con admin.html
const MAX_BYTES = 8 * 1024 * 1024;                    // 8 MB
$ALLOWED = ['hero-cover','face','hero-machinery','tech-monitors','aerial-right','logo-full'];
$EXT_BY_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$DIR = __DIR__ . '/uploads/site';

function out($arr, $code = 200) {
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

function build_manifest($dir, $allowed) {
  $map = [];
  foreach ($allowed as $slot) {
    foreach (['jpg','jpeg','png','webp'] as $ext) {
      $p = "$dir/$slot.$ext";
      if (file_exists($p)) { $map[$slot] = "$slot.$ext?v=" . filemtime($p); break; }
    }
  }
  file_put_contents("$dir/manifest.json", json_encode($map));
  return $map;
}

// --- Health check / estado (GET) -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $exists = is_dir($DIR);
  out([
    'ok' => true,
    'php' => PHP_VERSION,
    'dir_exists' => $exists,
    'writable' => $exists ? is_writable($DIR) : is_writable(__DIR__),
    'manifest' => $exists && file_exists("$DIR/manifest.json")
      ? json_decode(file_get_contents("$DIR/manifest.json"), true) : new stdClass(),
  ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'method'], 405);

// --- Auth -------------------------------------------------------------------
if (!hash_equals(PM_TOKEN, (string)($_POST['token'] ?? ''))) out(['ok' => false, 'error' => 'auth'], 403);

// --- Slot -------------------------------------------------------------------
$slot = (string)($_POST['slot'] ?? '');
if (!in_array($slot, $ALLOWED, true)) out(['ok' => false, 'error' => 'slot'], 400);

// --- Archivo ----------------------------------------------------------------
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'file'], 400);
$f = $_FILES['image'];
if ($f['size'] > MAX_BYTES) out(['ok' => false, 'error' => 'size'], 400);

$info = @getimagesize($f['tmp_name']);
if ($info === false || !isset($EXT_BY_MIME[$info['mime']])) out(['ok' => false, 'error' => 'type'], 400);
$ext = $EXT_BY_MIME[$info['mime']];

// --- Guardar ----------------------------------------------------------------
if (!is_dir($DIR) && !@mkdir($DIR, 0755, true)) out(['ok' => false, 'error' => 'mkdir'], 500);

// borrar variantes previas del mismo slot (por si cambió de jpg->png, etc.)
foreach (['jpg','jpeg','png','webp'] as $e) @unlink("$DIR/$slot.$e");

if (!@move_uploaded_file($f['tmp_name'], "$DIR/$slot.$ext")) out(['ok' => false, 'error' => 'save'], 500);

$map = build_manifest($DIR, $ALLOWED);
out(['ok' => true, 'slot' => $slot, 'file' => $map[$slot]]);
