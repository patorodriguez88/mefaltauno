<?php
// Mercado Pago. Con MP_PUBLIC_KEY el cliente paga en un modal dentro de la tienda (Checkout Bricks:
// mp-pagar.php + assets/js/mp-pago.js); sin ella, sale a pagar a la página de Mercado Pago (Checkout Pro).
// En los dos casos el pedido pasa solo a "confirmado" cuando el pago queda aprobado. Mercado Pago avisa
// a mp-webhook.php; de respaldo, el cron revisa los pedidos pendientes y la página del pedido mira el pago.
// El pago siempre se lee de la API de Mercado Pago: nunca se confía en lo que trae el aviso ni el navegador.
// Credenciales en config.php: MP_ACCESS_TOKEN (de prueba "TEST-…" o de producción "APP_USR-…"),
// MP_PUBLIC_KEY (la del mismo par) y MP_WEBHOOK_SECRET (clave secreta de las notificaciones, opcional).

/** Días durante los que el cron sigue buscando el pago de un pedido pendiente. */
const MP_DIAS_REVISION = 7;

function mp_activo(): bool {
    return defined('MP_ACCESS_TOKEN') && MP_ACCESS_TOKEN !== '';
}

/** Pago en el modal de la tienda (Checkout Bricks): hace falta además la Public Key. */
function mp_modal_activo(): bool {
    return mp_activo() && defined('MP_PUBLIC_KEY') && MP_PUBLIC_KEY !== '';
}

/** Mercado Pago solo avisa y vuelve solo a direcciones públicas: no a http://localhost (pruebas locales). */
function mp_sitio_publico(): bool {
    $base = rtrim(SITE_URL, '/');
    return str_starts_with($base, 'https://') && !preg_match('#^https://(localhost|127\.)#', $base);
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
    // En una prueba local (http://localhost) se vuelve con el botón de Mercado Pago y el pago se mira al volver.
    $publica = mp_sitio_publico();
    $pref = mp_api('POST', 'checkout/preferences', array_filter([
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
        'auto_return' => $publica ? 'approved' : null,
        'notification_url' => $publica ? $base . url('mp-webhook.php') : null,
        'statement_descriptor' => 'MEFALTAUNO',
    ], fn ($v) => $v !== null));
    // Con credenciales de prueba ("TEST-…") se paga en el entorno de prueba de Mercado Pago.
    $link = str_starts_with(MP_ACCESS_TOKEN, 'TEST-') && !empty($pref['sandbox_init_point']) ? $pref['sandbox_init_point'] : ($pref['init_point'] ?? '');
    if ($link === '') throw new Exception('Mercado Pago no devolvió el link de pago.');
    q("UPDATE pedidos SET mp_preferencia_id=?, mp_init_point=?, mp_monto=? WHERE id=?", [$pref['id'], $link, $p['total'], $pedido_id]);
    return $link;
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

/**
 * Cobro desde el modal (Payment Brick): el formulario de Mercado Pago tokeniza la tarjeta en el navegador
 * y acá se crea el pago por el total del pedido guardado (el monto del navegador no se usa). Con
 * 3-D Secure el banco puede pedir validar: se devuelve el desafío para mostrarlo en el modal.
 *
 * @return array{estado:string, pago_id:?string, mensaje:string, desafio?:array{url:string, creq:string}}
 */
function mp_cobrar_en_modal(int $pedido_id, array $form, string $email_cliente): array {
    $p = q("SELECT id, estado, total FROM pedidos WHERE id=?", [$pedido_id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    if ($p['estado'] !== 'pendiente') return ['estado' => 'approved', 'pago_id' => null, 'mensaje' => 'Este pedido ya está pago.'];
    if (empty($form['token']) || empty($form['payment_method_id'])) throw new Exception('Faltan los datos de la tarjeta.');

    $pagador = $form['payer'] ?? [];
    $pago = mp_api('POST', 'v1/payments', array_filter([
        'transaction_amount' => round((float)$p['total'], 2),
        'token' => (string)$form['token'],
        'description' => "MeFaltaUno · Pedido #$pedido_id",
        'installments' => max(1, (int)($form['installments'] ?? 1)),
        'payment_method_id' => (string)$form['payment_method_id'],
        'issuer_id' => !empty($form['issuer_id']) ? (int)$form['issuer_id'] : null,
        'payer' => array_filter([
            'email' => !empty($pagador['email']) ? (string)$pagador['email'] : $email_cliente,
            'identification' => !empty($pagador['identification']['number'])
                ? ['type' => (string)($pagador['identification']['type'] ?? 'DNI'), 'number' => (string)$pagador['identification']['number']] : null,
        ]),
        'external_reference' => (string)$pedido_id,
        'notification_url' => mp_sitio_publico() ? rtrim(SITE_URL, '/') . url('mp-webhook.php') : null,
        'statement_descriptor' => 'MEFALTAUNO',
        'three_d_secure_mode' => 'optional',
    ], fn ($v) => $v !== null));

    $pago_id = (string)$pago['id'];
    $estado = mp_procesar_pago($pago_id) ?? (string)($pago['status'] ?? '');
    $detalle = (string)($pago['status_detail'] ?? '');
    if ($estado === 'pending' && $detalle === 'pending_challenge' && !empty($pago['three_ds_info']['external_resource_url'])) {
        return ['estado' => $estado, 'pago_id' => $pago_id, 'mensaje' => 'Tu banco pide validar el pago.',
                'desafio' => ['url' => (string)$pago['three_ds_info']['external_resource_url'], 'creq' => (string)($pago['three_ds_info']['creq'] ?? '')]];
    }
    return ['estado' => $estado, 'pago_id' => $pago_id, 'mensaje' => mp_mensaje_estado($estado, $detalle)];
}

/** Estado de un pago del pedido (mientras el cliente valida con su banco). */
function mp_estado_pago(int $pedido_id, string $pago_id): array {
    $pago = mp_api('GET', 'v1/payments/' . rawurlencode($pago_id));
    if ((int)($pago['external_reference'] ?? 0) !== $pedido_id) throw new Exception('El pago no es de este pedido.');
    $estado = mp_procesar_pago($pago_id) ?? (string)$pago['status'];
    return ['estado' => $estado, 'pago_id' => $pago_id, 'mensaje' => mp_mensaje_estado($estado, (string)($pago['status_detail'] ?? ''))];
}

/** Qué decirle al cliente según el estado del pago en Mercado Pago. */
function mp_mensaje_estado(string $estado, string $detalle): string {
    if ($estado === 'approved') return '¡Pago aprobado! Ya estamos preparando tu pedido.';
    if (in_array($estado, ['pending', 'in_process', 'authorized'], true)) {
        return $detalle === 'pending_challenge' ? 'Falta que valides el pago con tu banco.'
            : 'Tu pago está en revisión. Apenas Mercado Pago lo apruebe, confirmamos el pedido y te avisamos por mail.';
    }
    return [
        'cc_rejected_bad_filled_card_number'   => 'Revisá el número de la tarjeta.',
        'cc_rejected_bad_filled_date'          => 'Revisá la fecha de vencimiento.',
        'cc_rejected_bad_filled_security_code' => 'Revisá el código de seguridad.',
        'cc_rejected_bad_filled_other'         => 'Revisá los datos de la tarjeta.',
        'cc_rejected_insufficient_amount'      => 'La tarjeta no tiene saldo o límite suficiente.',
        'cc_rejected_call_for_authorize'       => 'Tu banco pide que autorices el pago: llamalo y volvé a intentar.',
        'cc_rejected_card_disabled'            => 'La tarjeta está inactiva: llamá a tu banco para activarla.',
        'cc_rejected_duplicated_payment'       => 'Ya hiciste un pago igual hace instantes. Si querés pagar de nuevo, usá otra tarjeta.',
        'cc_rejected_max_attempts'             => 'Llegaste al límite de intentos con esta tarjeta: probá con otra.',
        'cc_rejected_high_risk'                => 'Mercado Pago rechazó el pago por seguridad: probá con otro medio.',
        'cc_rejected_3ds_challenge'            => 'No se pudo validar el pago con tu banco: probá de nuevo.',
    ][$detalle] ?? 'El pago fue rechazado: probá con otra tarjeta u otro medio de pago.';
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
