<?php
// Mercado Pago (Checkout Pro): al confirmar la compra el cliente paga en Mercado Pago y el pedido pasa
// solo a "confirmado" cuando el pago queda aprobado. Mercado Pago avisa a mp-webhook.php; de respaldo,
// el cron revisa los pedidos pendientes y la página del pedido mira el pago al volver de pagar.
// El pago siempre se lee de la API de Mercado Pago: nunca se confía en lo que trae el aviso.
// Credenciales en config.php: MP_ACCESS_TOKEN (de prueba "TEST-…" o de producción "APP_USR-…") y
// MP_WEBHOOK_SECRET (la clave secreta de las notificaciones, para validar la firma).

/** Días durante los que el cron sigue buscando el pago de un pedido pendiente. */
const MP_DIAS_REVISION = 7;

function mp_activo(): bool {
    return defined('MP_ACCESS_TOKEN') && MP_ACCESS_TOKEN !== '';
}

function mp_api(string $metodo, string $ruta, ?array $body = null): array {
    $headers = ['Authorization: Bearer ' . MP_ACCESS_TOKEN, 'Content-Type: application/json'];
    if ($metodo === 'POST') $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
    $base = defined('MP_API_URL') ? rtrim(MP_API_URL, '/') : 'https://api.mercadopago.com';   // MP_API_URL solo para pruebas locales
    $ch = curl_init($base . '/' . ltrim($ruta, '/'));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception('No se pudo conectar con Mercado Pago: ' . $err);
    $data = json_decode($resp, true) ?? [];
    if ($code >= 400) throw new Exception("Mercado Pago respondió $code: " . ($data['message'] ?? substr($resp, 0, 200)));
    return $data;
}

/**
 * Link de pago del pedido. Una preferencia por pedido: se reusa mientras el total no cambie.
 * Va como un solo ítem por el total, así el monto coincide exacto con descuentos y recargos.
 */
function mp_link_de_pago(int $pedido_id): string {
    $p = q("SELECT p.*, c.email, c.nombre AS cliente_nombre FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$pedido_id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    if ($p['estado'] !== 'pendiente') throw new Exception('Este pedido ya no está pendiente de pago.');
    if ($p['mp_init_point'] && (float)$p['mp_monto'] === (float)$p['total']) return $p['mp_init_point'];

    $base = rtrim(SITE_URL, '/');
    $volver = $base . url('pedido.php?id=' . $pedido_id . '&mp=1');
    $pref = mp_api('POST', 'checkout/preferences', [
        'items' => [[
            'id' => 'pedido-' . $pedido_id,
            'title' => "MeFaltaUno · Pedido #$pedido_id",
            'quantity' => 1,
            'currency_id' => 'ARS',
            'unit_price' => round((float)$p['total'], 2),
        ]],
        'payer' => ['email' => $p['email'], 'name' => $p['cliente_nombre']],
        'external_reference' => (string)$pedido_id,
        'back_urls' => ['success' => $volver, 'pending' => $volver, 'failure' => $volver],
        'auto_return' => 'approved',
        'notification_url' => $base . url('mp-webhook.php'),
        'statement_descriptor' => 'MEFALTAUNO',
    ]);
    if (empty($pref['init_point'])) throw new Exception('Mercado Pago no devolvió el link de pago.');
    q("UPDATE pedidos SET mp_preferencia_id=?, mp_init_point=?, mp_monto=? WHERE id=?", [$pref['id'], $pref['init_point'], $p['total'], $pedido_id]);
    return $pref['init_point'];
}

/**
 * Lee el pago en Mercado Pago, lo anota en el pedido y, si está aprobado por el total exacto en pesos,
 * confirma el pedido (manda la orden a WePoint y el mail al cliente). Se puede llamar varias veces con
 * el mismo pago (aviso, vuelta del cliente, cron): confirma una sola vez.
 *
 * @return string|null el estado del pago en Mercado Pago (approved, pending, rejected…), null si no es de un pedido
 */
function mp_procesar_pago(string $pago_id): ?string {
    $pago = mp_api('GET', 'v1/payments/' . rawurlencode($pago_id));
    $pedido_id = (int)($pago['external_reference'] ?? 0);
    $p = $pedido_id ? q("SELECT id, estado, total, mp_aprobado_at FROM pedidos WHERE id=?", [$pedido_id])->fetch() : null;
    if (!$p) return null;

    // Un intento rechazado posterior no pisa al pago aprobado (el cliente puede probar varias tarjetas).
    $estado = (string)($pago['status'] ?? '');
    q("UPDATE pedidos SET mp_pago_id=?, mp_estado=? WHERE id=? AND (mp_estado IS NULL OR mp_estado NOT LIKE 'approved%' OR ?='approved')",
      [(string)$pago['id'], $estado . (!empty($pago['status_detail']) ? ':' . $pago['status_detail'] : ''), $pedido_id, $estado]);

    if ($estado !== 'approved') return $estado;

    $monto_ok = abs((float)($pago['transaction_amount'] ?? 0) - (float)$p['total']) < 0.01 && ($pago['currency_id'] ?? '') === 'ARS';
    if (!$monto_ok) {
        registrar_historial('pedido', $pedido_id, $p['estado'], "Mercado Pago aprobó la operación {$pago['id']} por " . precio((float)($pago['transaction_amount'] ?? 0))
            . ' y el pedido es de ' . precio((float)$p['total']) . ': revisar a mano.', 'Mercado Pago');
        return $estado;
    }

    // Una sola confirmación aunque lleguen a la vez el aviso, la vuelta del cliente y el cron.
    $tomado = q("UPDATE pedidos SET mp_aprobado_at=NOW() WHERE id=? AND mp_aprobado_at IS NULL AND estado='pendiente'", [$pedido_id])->rowCount();
    if ($tomado) {
        pedido_cambiar_estado($pedido_id, 'confirmado', "Pago aprobado en Mercado Pago (operación {$pago['id']}).", true, 'Mercado Pago');
    }
    return $estado;
}

/** Respaldo por si un aviso no llegó: busca el pago de los pedidos de Mercado Pago que siguen pendientes. */
function mp_revisar_pendientes(): array {
    $res = ['revisados' => 0, 'confirmados' => 0, 'errores' => []];
    if (!mp_activo()) return $res;
    $pendientes = q("SELECT id FROM pedidos WHERE estado='pendiente' AND pago_metodo='mercadopago' AND mp_preferencia_id IS NOT NULL
                     AND created_at >= NOW() - INTERVAL " . MP_DIAS_REVISION . " DAY")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pendientes as $pedido_id) {
        $res['revisados']++;
        try {
            $pagos = mp_api('GET', 'v1/payments/search?sort=date_created&criteria=desc&external_reference=' . (int)$pedido_id)['results'] ?? [];
            foreach ($pagos as $pago) {
                if (($pago['status'] ?? '') === 'approved' && mp_procesar_pago((string)$pago['id']) === 'approved') {
                    $res['confirmados']++;
                    break;
                }
            }
        } catch (Exception $e) {
            $res['errores'][] = "#$pedido_id: " . $e->getMessage();
        }
    }
    return $res;
}

/**
 * Firma del aviso (header x-signature "ts=…,v1=…"): HMAC-SHA256 con la clave secreta de las
 * notificaciones sobre "id:<data.id>;request-id:<x-request-id>;ts:<ts>;". Sin clave configurada no se valida.
 */
function mp_firma_valida(string $data_id): bool {
    if (!defined('MP_WEBHOOK_SECRET') || MP_WEBHOOK_SECRET === '') return true;
    $partes = [];
    foreach (explode(',', $_SERVER['HTTP_X_SIGNATURE'] ?? '') as $par) {
        [$k, $v] = array_map('trim', explode('=', $par, 2) + [1 => '']);
        $partes[$k] = $v;
    }
    if (empty($partes['ts']) || empty($partes['v1'])) return false;
    $manifiesto = 'id:' . strtolower($data_id) . ';request-id:' . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . ';ts:' . $partes['ts'] . ';';
    return hash_equals(hash_hmac('sha256', $manifiesto, MP_WEBHOOK_SECRET), $partes['v1']);
}
