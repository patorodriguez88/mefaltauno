<?php
// Aviso de Caddy: cada cambio del paquete de un pedido (lo procesa caddy_procesar_aviso en inc/caddy.php).
// En sistema.caddy → Clientes → MeFaltaUno → Webhook: EndPoint https://www.mefaltauno.com.ar/caddy-webhook.php
// y Token = CADDY_WEBHOOK_TOKEN de config.php. Caddy lo manda en el header x-caddy-webhook-token.
// Cuerpo: JSON {codigo_seguimiento, idProveedor?, estado, fecha, fecha_entrega?} o el texto viejo "estado fecha idProveedor".
// Responde 200 aunque el pedido no exista (si no, Caddy reintenta) y 500 ante un error nuestro (para que reintente).
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!defined('CADDY_WEBHOOK_TOKEN') || CADDY_WEBHOOK_TOKEN === '') {
    http_response_code(503);
    exit(json_encode(['ok' => 0, 'error' => 'Avisos de Caddy no configurados.']));
}
$token = (string)($_SERVER['HTTP_X_CADDY_WEBHOOK_TOKEN'] ?? preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (!hash_equals(CADDY_WEBHOOK_TOKEN, $token)) {
    http_response_code(401);
    exit(json_encode(['ok' => 0, 'error' => 'Token inválido.']));
}

$raw = (string)file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) $d = caddy_aviso_desde_texto($raw);
if (!$d) {
    http_response_code(400);
    exit(json_encode(['ok' => 0, 'error' => 'Aviso ilegible.']));
}
try {
    $r = caddy_procesar_aviso($d);
    if (!$r['ok']) error_log('[caddy] aviso sin aplicar: ' . $r['error'] . ' · ' . mb_substr($raw, 0, 300));
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[caddy] aviso: ' . $e->getMessage() . ' · ' . mb_substr($raw, 0, 300));
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => 'Error procesando el aviso.']);
}
