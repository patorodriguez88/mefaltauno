<?php
// Recibe lo que tools/brihet-enviar.php lee de Brihet desde una PC de la oficina (a nuestro servidor Brihet le contesta 403).
//   ?accion=catalogo  cuerpo: JSON comprimido con gzip {colecciones, variantes}. Guarda el catálogo, actualiza stock y
//                     precios de lo importado y devuelve las URLs de las fotos que faltan.
//   ?accion=fotos     multipart: foto[] (archivos) + url[] (de dónde salió cada una).
// Clave: WEPOINT_CRON_TOKEN, en el encabezado X-Token.
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/brihet.php';

$t = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
if (!defined('WEPOINT_CRON_TOKEN') || WEPOINT_CRON_TOKEN === '' || !hash_equals(WEPOINT_CRON_TOKEN, $t)) {
    json_out(['ok' => false, 'error' => 'No autorizado.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Solo POST.'], 405);
@set_time_limit(300);

try {
    $accion = $_GET['accion'] ?? '';
    if ($accion === 'catalogo') {
        $d = json_decode((string)@gzdecode((string)file_get_contents('php://input')), true);
        if (!is_array($d['colecciones'] ?? null) || !is_array($d['variantes'] ?? null) || !$d['colecciones']) {
            throw new Exception('El catálogo llegó vacío o incompleto.');
        }
        $cat = brihet_guardar_catalogo($d['colecciones'], $d['variantes'], 'enviado');
        $r = brihet_actualizar_todo($cat);
        json_out(['ok' => true, 'colecciones' => count($cat['colecciones']), 'fotos' => brihet_fotos_necesarias($cat)] + $r);
    }
    if ($accion === 'fotos') {
        $urls = (array)($_POST['url'] ?? []);
        $n = 0;
        foreach ((array)($_FILES['foto']['tmp_name'] ?? []) as $i => $tmp) {
            if (($_FILES['foto']['error'][$i] ?? 1) === UPLOAD_ERR_OK && isset($urls[$i])
                && brihet_guardar_foto((string)$urls[$i], (string)file_get_contents($tmp))) $n++;
        }
        json_out(['ok' => true, 'guardadas' => $n]);
    }
    json_out(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
