<?php
// Cobro del modal de pago (assets/js/mp-pago.js, Checkout Bricks). Solo el cliente dueño del pedido.
//   POST {pedido, formData}  → cobra con la tarjeta tokenizada por Mercado Pago (inc/mercadopago.php)
//   GET  ?pedido=…&pago=…    → estado del pago mientras el cliente valida con su banco (3-D Secure)
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$responder = function (int $code, array $datos): void {
    http_response_code($code);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};

$cli = cliente();
if (!$cli) $responder(401, ['error' => 'Tu sesión venció: ingresá de nuevo para pagar.']);
if (!mp_modal_activo()) $responder(400, ['error' => 'El pago con Mercado Pago no está disponible.']);

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$entrada = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$pedido_id = (int)($entrada['pedido'] ?? 0);
$es_suyo = q("SELECT 1 FROM pedidos WHERE id=? AND cliente_id=? AND pago_metodo='mercadopago'", [$pedido_id, $cli['id']])->fetchColumn();
if (!$es_suyo) $responder(404, ['error' => 'Pedido no encontrado.']);

try {
    if ($post) {
        if (!csrf_ok()) $responder(403, ['error' => 'La página venció: recargala y volvé a intentar.']);
        $responder(200, mp_cobrar_en_modal($pedido_id, (array)($entrada['formData'] ?? []), (string)$cli['email']));
    }
    $pago_id = preg_replace('/\D/', '', (string)($entrada['pago'] ?? ''));
    if ($pago_id === '') $responder(400, ['error' => 'Falta el pago.']);
    $responder(200, mp_estado_pago($pedido_id, $pago_id));
} catch (Exception $e) {
    error_log("[mercadopago] modal del pedido #$pedido_id: " . $e->getMessage());
    $responder(502, ['error' => 'No pudimos procesar el pago con Mercado Pago. Probá de nuevo en un momento.']);
}
