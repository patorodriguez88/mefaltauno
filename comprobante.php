<?php
// Muestra el comprobante de transferencia de un pedido: solo al cliente dueño del pedido o a un operador.
require __DIR__ . '/inc/bootstrap.php';

$cli = requiere_login();
$p = q("SELECT id, cliente_id, comprobante FROM pedidos WHERE id=?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p || ((int)$p['cliente_id'] !== (int)$cli['id'] && !es_admin($cli))) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
$nombre = (string)$p['comprobante'];
$archivo = comprobante_dir() . '/' . $nombre;
if (!preg_match('/^[a-z0-9-]+\.(jpg|pdf)$/', $nombre, $m) || !is_file($archivo)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

header('Content-Type: ' . ($m[1] === 'pdf' ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . filesize($archivo));
header('Content-Disposition: inline; filename="comprobante-pedido-' . (int)$p['id'] . '.' . $m[1] . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, no-store');
readfile($archivo);
