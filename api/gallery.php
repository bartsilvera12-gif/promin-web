<?php
/**
 * ProMin — API única de la galería.
 *
 * Fuente de verdad: /data/gallery.json  (semilla inmutable: /data/gallery.seed.json).
 * Archivos subidos: /uploads/gallery/<archivo>  (ignorado por git; no se pisa en deploy).
 *
 *  GET                      -> { categories, images }  (público)
 *  POST action=login        -> inicia sesión admin (password server-side)
 *  POST action=logout
 *  POST action=upload       -> sube imagen + registra (requiere sesión)
 *  POST action=update       -> title/description/alt/category/active (requiere sesión)
 *  POST action=reorder      -> nuevo orden por lista de ids (requiere sesión)
 *  POST action=delete       -> borra registro (+ archivo si corresponde) (requiere sesión)
 *
 * Seguridad: escrituras solo con sesión admin válida. MIME real + extensión.
 * Nombres únicos, sin path traversal. GET es lo único público.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const ADMIN_PASS = 'Promin2026!';                 // se valida en el server; no viaja al cliente
const MAX_BYTES  = 25 * 1024 * 1024;              // 25 MB
$ROOT    = dirname(__DIR__);
$DATA    = "$ROOT/data/gallery.json";
$SEED    = "$ROOT/data/gallery.seed.json";
$UPLOADS = "$ROOT/uploads/gallery";
$EXT_BY_MIME = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];

function out($arr, $code = 200) { http_response_code($code); echo json_encode($arr); exit; }
function is_admin() { return !empty($_SESSION['gal_admin']); }
function require_admin() { if (!is_admin()) out(['ok'=>false,'error'=>'auth'], 401); }

function load_data() {
  global $DATA, $SEED;
  if (!file_exists($DATA)) {
    if (!is_dir(dirname($DATA))) @mkdir(dirname($DATA), 0755, true);
    $seed = file_exists($SEED) ? file_get_contents($SEED) : '{"categories":[],"images":[]}';
    @file_put_contents($DATA, $seed, LOCK_EX);
  }
  $d = json_decode(@file_get_contents($DATA), true);
  if (!is_array($d)) $d = ['categories'=>[], 'images'=>[]];
  if (!isset($d['categories'])) $d['categories'] = [];
  if (!isset($d['images'])) $d['images'] = [];
  usort($d['images'], fn($a,$b) => ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0));
  return $d;
}
function save_data($d) {
  global $DATA;
  usort($d['images'], fn($a,$b) => ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0));
  @file_put_contents($DATA, json_encode($d), LOCK_EX);
  return $d;
}
function valid_cat($d, $slug) {
  foreach ($d['categories'] as $c) if ($c['slug'] === $slug) return true;
  return false;
}

// Guarda imagen (reescala a 1600px + WebP con GD si se puede; si no, original).
function store_image($tmp, $mime, $dir, $id) {
  global $EXT_BY_MIME;
  $srcExt = $EXT_BY_MIME[$mime] ?? 'jpg';
  $sz = @getimagesize($tmp); $w = $sz ? $sz[0] : 0; $h = $sz ? $sz[1] : 0;
  $maxW = 1600;
  if (function_exists('imagecreatetruecolor') && $w > $maxW && $mime !== 'image/avif') {
    $img = null;
    if ($mime==='image/jpeg' && function_exists('imagecreatefromjpeg')) $img=@imagecreatefromjpeg($tmp);
    elseif ($mime==='image/png' && function_exists('imagecreatefrompng')) $img=@imagecreatefrompng($tmp);
    elseif ($mime==='image/webp' && function_exists('imagecreatefromwebp')) $img=@imagecreatefromwebp($tmp);
    if ($img) {
      $nh=(int)round($h*$maxW/$w); $dst=imagecreatetruecolor($maxW,$nh);
      imagecopyresampled($dst,$img,0,0,0,0,$maxW,$nh,$w,$h);
      if (function_exists('imagewebp')) { $ext='webp'; $path="$dir/$id.webp"; imagewebp($dst,$path,82); }
      else { $ext='jpg'; $path="$dir/$id.jpg"; imagejpeg($dst,$path,84); }
      imagedestroy($img); imagedestroy($dst);
      return [$path, "$id.$ext", $maxW, $nh];
    }
  }
  $path="$dir/$id.$srcExt";
  if (!@move_uploaded_file($tmp,$path)) @copy($tmp,$path);
  return [$path, "$id.$srcExt", $w, $h];
}

// ------------------------------- GET (público) -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $d = load_data();
  out(['categories'=>$d['categories'], 'images'=>array_values($d['images'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok'=>false,'error'=>'method'], 405);
$action = (string)($_POST['action'] ?? '');

// ------------------------------- Sesión --------------------------------------
if ($action === 'login') {
  if (hash_equals(ADMIN_PASS, (string)($_POST['pass'] ?? ''))) { $_SESSION['gal_admin']=true; out(['ok'=>true]); }
  out(['ok'=>false,'error'=>'bad-pass'], 401);
}
if ($action === 'logout') { $_SESSION['gal_admin']=false; out(['ok'=>true]); }
if ($action === 'session') { out(['ok'=>true,'admin'=>is_admin()]); }

// ------------------------------- Escrituras (sesión) -------------------------
require_admin();
$d = load_data();

if ($action === 'update') {
  $id = (string)($_POST['id'] ?? '');
  $found = false;
  foreach ($d['images'] as &$im) {
    if (($im['id'] ?? '') === $id) {
      if (isset($_POST['title']))       $im['title'] = mb_substr(trim((string)$_POST['title']), 0, 200);
      if (isset($_POST['description'])) $im['description'] = mb_substr(trim((string)$_POST['description']), 0, 600);
      if (isset($_POST['alt']))         $im['alt'] = mb_substr(trim((string)$_POST['alt']), 0, 300);
      if (isset($_POST['category']) && valid_cat($d, $_POST['category'])) $im['category'] = (string)$_POST['category'];
      if (isset($_POST['active']))      $im['active'] = ($_POST['active'] === '1' || $_POST['active'] === 'true');
      $found = true; break;
    }
  }
  unset($im);
  if (!$found) out(['ok'=>false,'error'=>'not-found'], 404);
  $d = save_data($d);
  out(['ok'=>true, 'categories'=>$d['categories'], 'images'=>array_values($d['images'])]);
}

if ($action === 'reorder') {
  $ids = json_decode((string)($_POST['order'] ?? '[]'), true);
  if (!is_array($ids)) out(['ok'=>false,'error'=>'order'], 400);
  $pos = array_flip($ids);
  foreach ($d['images'] as &$im) {
    if (isset($pos[$im['id']])) $im['sort_order'] = (int)$pos[$im['id']] + 1;
  }
  unset($im);
  $d = save_data($d);
  out(['ok'=>true, 'categories'=>$d['categories'], 'images'=>array_values($d['images'])]);
}

if ($action === 'delete') {
  global $ROOT;
  $id = (string)($_POST['id'] ?? '');
  $kept = []; $target = null;
  foreach ($d['images'] as $im) { if (($im['id'] ?? '') === $id) $target = $im; else $kept[] = $im; }
  if (!$target) out(['ok'=>false,'error'=>'not-found'], 404);
  // borra el archivo físico solo si NO es protegido y está dentro de uploads/gallery
  if (empty($target['protected'])) {
    $rel = preg_replace('/\?.*$/', '', (string)($target['src'] ?? ''));
    $abs = realpath("$ROOT/$rel");
    $up  = realpath("$ROOT/uploads/gallery");
    if ($abs && $up && strpos($abs, $up) === 0) @unlink($abs);
  }
  $d['images'] = $kept;
  $d = save_data($d);
  out(['ok'=>true, 'categories'=>$d['categories'], 'images'=>array_values($d['images'])]);
}

if ($action === 'upload') {
  global $UPLOADS;
  $cat = (string)($_POST['category'] ?? '');
  if (!valid_cat($d, $cat)) out(['ok'=>false,'error'=>'cat'], 400);
  if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) out(['ok'=>false,'error'=>'file'], 400);
  $f = $_FILES['image'];
  if ($f['size'] > MAX_BYTES) out(['ok'=>false,'error'=>'size'], 400);
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($f['tmp_name']);
  if (!isset($EXT_BY_MIME[$mime])) out(['ok'=>false,'error'=>'type'], 400);
  if (@getimagesize($f['tmp_name']) === false) out(['ok'=>false,'error'=>'type'], 400);
  if (!is_dir($UPLOADS) && !@mkdir($UPLOADS, 0755, true)) out(['ok'=>false,'error'=>'mkdir'], 500);
  $id = 'g' . bin2hex(random_bytes(8));
  list($path, $fname, $w, $h) = store_image($f['tmp_name'], $mime, $UPLOADS, $id);
  if (!$path || !file_exists($path)) out(['ok'=>false,'error'=>'save'], 500);
  $maxOrder = 0; foreach ($d['images'] as $im) $maxOrder = max($maxOrder, (int)($im['sort_order'] ?? 0));
  $rec = [
    'id' => $id,
    'src' => "uploads/gallery/$fname",
    'thumbnail' => "uploads/gallery/$fname",
    'srcset' => '',
    'w' => $w, 'h' => $h,
    'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200),
    'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 600),
    'alt' => mb_substr(trim((string)($_POST['alt'] ?? '')), 0, 300),
    'alt_pt' => '',
    'category' => $cat,
    'sort_order' => $maxOrder + 1,
    'active' => true,
    'protected' => false,
    'created_at' => date('c'),
  ];
  $d['images'][] = $rec;
  $d = save_data($d);
  out(['ok'=>true, 'image'=>$rec, 'categories'=>$d['categories'], 'images'=>array_values($d['images'])]);
}

out(['ok'=>false,'error'=>'unknown-action'], 400);
