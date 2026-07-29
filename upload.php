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
const MAX_BYTES = 25 * 1024 * 1024;                   // 25 MB (fotos de celular grandes)
$ALLOWED = ['hero-cover','face','hero-machinery','tech-monitors','aerial-right','logo-full'];
$EXT_BY_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$DIR = __DIR__ . '/uploads/site';

// Galería de servicios por categoría (se fusiona con servicios/gallery.json en el front)
$GALDIR = __DIR__ . '/uploads/gallery';
$GAL_CATS = ['capacitacion-tecnica','consultoria-compras','diseno-plantas-trituracion',
  'gestion-costos-inventario','mantenimiento-correctivo','mantenimiento-preventivo','reparacion-comercializacion'];

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

function build_gallery($dir) {
  $gd = "$dir/gallery"; $items = [];
  if (is_dir($gd)) {
    foreach (glob("$gd/*.{jpg,jpeg,png,webp}", GLOB_BRACE) as $p) {
      $items[] = ['id' => pathinfo($p, PATHINFO_FILENAME),
                  'file' => 'gallery/' . basename($p) . '?v=' . filemtime($p),
                  '_mt' => filemtime($p)];
    }
    usort($items, fn($a, $b) => $a['_mt'] - $b['_mt']);  // orden de subida
    foreach ($items as &$it) unset($it['_mt']);
  }
  file_put_contents("$dir/gallery.json", json_encode(array_values($items)));
  return array_values($items);
}

// Guarda una imagen redimensionada (máx 1600px, WebP si GD lo soporta) o la original.
function gal_save($tmp, $mime, $destDir, $id) {
  $maxW = 1600;
  $srcExt = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? 'jpg';
  $sz = @getimagesize($tmp); $w = $sz ? $sz[0] : 0; $h = $sz ? $sz[1] : 0;
  if (function_exists('imagecreatetruecolor') && $w > $maxW) {
    $img = null;
    if ($mime==='image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($tmp);
    elseif ($mime==='image/png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($tmp);
    elseif ($mime==='image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($tmp);
    if ($img) {
      $nh = (int)round($h * $maxW / $w);
      $dst = imagecreatetruecolor($maxW, $nh);
      imagecopyresampled($dst, $img, 0,0,0,0, $maxW,$nh, $w,$h);
      if (function_exists('imagewebp')) { $ext='webp'; $path="$destDir/$id.webp"; imagewebp($dst,$path,82); }
      else { $ext='jpg'; $path="$destDir/$id.jpg"; imagejpeg($dst,$path,84); }
      imagedestroy($img); imagedestroy($dst);
      return [$path, $ext, $maxW, $nh];
    }
  }
  $path = "$destDir/$id.$srcExt";
  if (!@move_uploaded_file($tmp, $path)) @copy($tmp, $path);
  return [$path, $srcExt, $w, $h];
}

// Reconstruye el manifest de la galería de servicios (por categoría)
function build_svcgallery($galdir, $cats) {
  $images = []; $categories = [];
  foreach ($cats as $slug) {
    $count = 0; $cd = "$galdir/$slug";
    if (is_dir($cd)) {
      $files = glob("$cd/*.{jpg,jpeg,png,webp}", GLOB_BRACE) ?: [];
      usort($files, fn($a,$b) => filemtime($a) - filemtime($b));
      foreach ($files as $p) {
        $s = @getimagesize($p);
        $images[] = ['id' => pathinfo($p, PATHINFO_FILENAME), 'cat' => $slug,
          'src' => 'uploads/gallery/' . $slug . '/' . basename($p) . '?v=' . filemtime($p),
          'w' => $s ? $s[0] : 0, 'h' => $s ? $s[1] : 0];
        $count++;
      }
    }
    $categories[] = ['slug' => $slug, 'count' => $count];
  }
  $manifest = ['categories' => $categories, 'images' => $images];
  if (is_dir($galdir) || @mkdir($galdir, 0755, true)) @file_put_contents("$galdir/manifest.json", json_encode($manifest));
  return $manifest;
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
    'gallery' => $exists ? build_gallery($DIR) : [],
    'svcgallery' => build_svcgallery($GALDIR, $GAL_CATS),
  ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'method'], 405);

// --- Auth -------------------------------------------------------------------
if (!hash_equals(PM_TOKEN, (string)($_POST['token'] ?? ''))) out(['ok' => false, 'error' => 'auth'], 403);

// --- Acción -----------------------------------------------------------------
$action = (string)($_POST['action'] ?? 'slot');
if (!is_dir($DIR) && !@mkdir($DIR, 0755, true)) out(['ok' => false, 'error' => 'mkdir'], 500);

// Eliminar una foto de la galería (no requiere archivo)
if ($action === 'gallery-del') {
  $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
  if ($id === '') out(['ok' => false, 'error' => 'id'], 400);
  foreach (['jpg','jpeg','png','webp'] as $e) @unlink("$DIR/gallery/$id.$e");
  out(['ok' => true, 'gallery' => build_gallery($DIR)]);
}

// Eliminar una foto de la galería de servicios (por categoría, no requiere archivo)
if ($action === 'svcgal-del') {
  $cat = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['cat'] ?? ''));
  $id  = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['id'] ?? ''));
  if (!in_array($cat, $GAL_CATS, true) || $id === '') out(['ok' => false, 'error' => 'param'], 400);
  foreach (['jpg','jpeg','png','webp'] as $e) @unlink("$GALDIR/$cat/$id.$e");
  out(['ok' => true, 'svcgallery' => build_svcgallery($GALDIR, $GAL_CATS)]);
}

// --- Validar archivo (reemplazo de slot y agregar a galería) ----------------
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'file'], 400);
$f = $_FILES['image'];
if ($f['size'] > MAX_BYTES) out(['ok' => false, 'error' => 'size'], 400);
$info = @getimagesize($f['tmp_name']);
if ($info === false || !isset($EXT_BY_MIME[$info['mime']])) out(['ok' => false, 'error' => 'type'], 400);
$ext = $EXT_BY_MIME[$info['mime']];

// Agregar una foto nueva a la galería (nombre único generado por el server)
if ($action === 'gallery-add') {
  $gd = "$DIR/gallery";
  if (!is_dir($gd) && !@mkdir($gd, 0755, true)) out(['ok' => false, 'error' => 'mkdir'], 500);
  $id = uniqid('g');
  if (!@move_uploaded_file($f['tmp_name'], "$gd/$id.$ext")) out(['ok' => false, 'error' => 'save'], 500);
  out(['ok' => true, 'gallery' => build_gallery($DIR)]);
}

// Agregar una foto a la galería de servicios, en una categoría
if ($action === 'svcgal-add') {
  $cat = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['cat'] ?? ''));
  if (!in_array($cat, $GAL_CATS, true)) out(['ok' => false, 'error' => 'cat'], 400);
  $cd = "$GALDIR/$cat";
  if (!is_dir($cd) && !@mkdir($cd, 0755, true)) out(['ok' => false, 'error' => 'mkdir'], 500);
  list($path) = gal_save($f['tmp_name'], $info['mime'], $cd, uniqid('g'));
  if (!$path || !file_exists($path)) out(['ok' => false, 'error' => 'save'], 500);
  out(['ok' => true, 'svcgallery' => build_svcgallery($GALDIR, $GAL_CATS)]);
}

// --- Reemplazar una de las imágenes fijas (slot) ----------------------------
$slot = (string)($_POST['slot'] ?? '');
if (!in_array($slot, $ALLOWED, true)) out(['ok' => false, 'error' => 'slot'], 400);
foreach (['jpg','jpeg','png','webp'] as $e) @unlink("$DIR/$slot.$e");
if (!@move_uploaded_file($f['tmp_name'], "$DIR/$slot.$ext")) out(['ok' => false, 'error' => 'save'], 500);
$map = build_manifest($DIR, $ALLOWED);
out(['ok' => true, 'slot' => $slot, 'file' => $map[$slot]]);
