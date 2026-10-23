<?php
// Aviso de Mercado Pago (notification_url de cada preferencia y "Webhooks" del panel de Mercado Pago).
// Llega ?data.id=<pago>&type=payment (PHP lo recibe como data_id) y el mismo dato en el cuerpo JSON.
// El pago se lee de la API (mp_procesar_pago): el aviso solo dice qué pago mirar.
require __DIR__ . '/inc/bootstrap.php';

$cuerpo = json_decode((string)file_get_contents('php://input'), true) ?: [];
$tipo = (string)($_GET['type'] ?? $cuerpo['type'] ?? $_GET['topic'] ?? '');
$pago_id = (string)($_GET['data_id'] ?? $cuerpo['data']['id'] ?? ($tipo === 'payment' ? ($_GET['id'] ?? '') : ''));

if (!mp_activo() || $tipo !== 'payment' || !preg_match('/^\d+$/', $pago_id)) {
    http_response_code(200);   // otros avisos (órdenes, pruebas): se aceptan y se ignoran
    exit('ok');
}
if (!mp_firma_valida($pago_id)) {
    http_response_code(401);
    exit('firma inválida');
}

try {
    mp_procesar_pago($pago_id);
    http_response_code(200);
    echo 'ok';
} catch (Exception $e) {
    error_log('[mercadopago] aviso del pago ' . $pago_id . ': ' . $e->getMessage());
    http_response_code(500);   // Mercado Pago reintenta
    echo 'error';
}
