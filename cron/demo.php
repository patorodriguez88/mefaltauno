<?php
// Carga de datos de prueba en el servidor (uso único, protegido con WEPOINT_CRON_TOKEN).
//   POST ?token=…&paso=base  (pc=<clave cliente>, po=<clave operador>) → catálogo, kioscos, banco, usuarios
//   ?token=…&paso=imagenes                                     → copia 12 imágenes de Tiendanube por llamada
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/demo.php';
header('Content-Type: application/json; charset=utf-8');

$t = (string)($_GET['token'] ?? '');
if (!defined('WEPOINT_CRON_TOKEN') || WEPOINT_CRON_TOKEN === '' || !hash_equals(WEPOINT_CRON_TOKEN, $t)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'No autorizado']));
}
set_time_limit(120);
try {
    if (($_GET['paso'] ?? '') === 'base') {
        $pc = (string)($_POST['pc'] ?? '');
        $po = (string)($_POST['po'] ?? '');
        if (strlen($pc) < 8 || strlen($po) < 8) throw new Exception('Faltan las claves (mínimo 8 caracteres).');
        echo json_encode(['ok' => true, 'hecho' => demo_cargar_base($pc, $po)], JSON_UNESCAPED_UNICODE);
    } elseif (($_GET['paso'] ?? '') === 'cp_puntos') {
        echo json_encode(['ok' => true, 'puntos' => demo_cp_puntos()], JSON_UNESCAPED_UNICODE);
    } elseif (($_GET['paso'] ?? '') === 'restaurar_stock') {
        echo json_encode(['ok' => true, 'numeros' => demo_restaurar_stock()], JSON_UNESCAPED_UNICODE);
    } elseif (($_GET['paso'] ?? '') === 'imagenes') {
        echo json_encode(['ok' => true] + demo_copiar_imagenes(12), JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Paso inválido.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
