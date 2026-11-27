<?php
// Mails de pedidos y solicitudes (al operador y al cliente).

function notificar_solicitud_nueva(int $sol_id): void {
    $s = q("SELECT s.*, c.nombre, c.apellido, c.email, c.telefono FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.id=?", [$sol_id])->fetch();
    if (!$s) return;
    $items = q("SELECT descripcion FROM solicitud_items WHERE solicitud_id=?", [$sol_id])->fetchAll(PDO::FETCH_COLUMN);
    $lista = '<ul>' . implode('', array_map(fn($d) => '<li>' . e($d) . '</li>', $items)) . '</ul>';
    $cliente = e(nombre_cliente($s));

    mail_enviar(MAIL_OPERADOR, "Nuevo pedido de faltantes #$sol_id — " . nombre_cliente($s),
        "<p><b>$cliente</b> pidió que le consigamos:</p>$lista"
        . ($s['mensaje'] ? '<p><b>Comentario:</b> ' . nl2br(e($s['mensaje'])) . '</p>' : '')
        . mail_tabla([['Email', e($s['email'])], ['Teléfono', e($s['telefono'] ?: '—')]])
        . mail_link("admin/solicitud.php?id=$sol_id", 'Ver en el panel'));

    mail_enviar($s['email'], "Misión aceptada: salimos a buscar tu pedido #$sol_id",
        '<p>Hola ' . e($s['nombre']) . ',</p><p>Recibimos tu pedido. Estos son los integrantes que salimos a buscar:</p>' . $lista
        . '<p>La misión ya está en marcha. Te avisamos por mail apenas tengamos novedades. <b>Ningún héroe queda atrás.</b></p>'
        . mail_link("cuenta.php?tab=faltantes", 'Seguir la búsqueda'));
}

function notificar_pedido_nuevo(int $ped_id): void {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$ped_id])->fetch();
    if (!$p) return;
    $filas = [];
    foreach (q("SELECT * FROM pedido_items WHERE pedido_id=?", [$ped_id]) as $l) {
        $filas[] = [e($l['coleccion'] . ' ' . num((int)$l['numero']) . ' — ' . $l['titulo']) . ((int)$l['encargue'] ? '<br><small>' . linea_entrega((int)$l['fisico'], (int)$l['encargue']) . '</small>' : ''),
                    'x' . (int)$l['cantidad'], precio($l['precio'] * $l['cantidad'])];
    }
    if ((float)$p['descuento'] > 0) $filas[] = ['Código ' . e($p['cupon_codigo']), '', '−' . precio((float)$p['descuento'])];
    if ((float)$p['recargo'] > 0) $filas[] = ['Recargo interior', '', '+' . precio((float)$p['recargo'])];
    $filas[] = ['<b>Total</b>', '', '<b>' . precio((float)$p['total']) . '</b>'];
    $detalle = mail_tabla($filas)
        . mail_tabla([
            ['Retiro en', '<b>' . e($p['envio_punto'] ?: (ENVIO_METODOS[$p['envio_metodo']] ?? '')) . '</b><br>' . e(trim($p['envio_direccion'] . ', ' . $p['envio_localidad'], ', '))],
            ['Retira', e($p['envio_nombre'] . ' · ' . $p['envio_telefono'])],
            ['Pago', e(PAGO_METODOS[$p['pago_metodo']] ?? $p['pago_metodo'])],
        ])
        . ($p['notas'] ? '<p><b>Notas:</b> ' . nl2br(e($p['notas'])) . '</p>' : '');

    mail_enviar(MAIL_OPERADOR, "Nuevo pedido #$ped_id — " . nombre_cliente($p) . ' — ' . precio((float)$p['total']),
        '<p><b>' . e(nombre_cliente($p)) . '</b> (' . e($p['email']) . ') hizo un pedido:</p>' . $detalle
        . mail_link("admin/pedido.php?id=$ped_id", 'Ver en el panel'));

    if ($p['pago_metodo'] === 'transferencia') {
        $filas = [['Monto', '<b>' . precio((float)$p['total']) . '</b>']];
        foreach (datos_bancarios() as $b) $filas[] = [e($b['label']), '<b>' . e($b['valor']) . '</b>'];
        $pago = '<h3 style="color:#006368;margin:20px 0 8px">Datos para transferir</h3>'
            . (count($filas) > 1 ? mail_tabla($filas) : '<p>En breve te enviamos los datos para transferir.</p>')
            . (ajuste('banco_instrucciones') !== '' ? '<p>' . nl2br(e(ajuste('banco_instrucciones'))) . '</p>' : '')
            . '<p style="background:#fffdea;border:2px solid #d9cc12;padding:12px 14px;border-radius:10px">'
            . '<b>Cuando transfieras, subí el comprobante en tu cuenta.</b> Entrá a <b>Mi cuenta → Mis pedidos → Pedido #' . $ped_id . '</b> '
            . 'y tocá <b>Subir comprobante</b> (sirve una foto, una captura de pantalla o el PDF del banco). '
            . 'Apenas lo confirmamos, empezamos a preparar tu pedido.</p>';
    } elseif (mp_activo()) {
        $pago = '<p>Apenas Mercado Pago apruebe el pago, confirmamos el pedido y te avisamos. Si no llegaste a pagar, '
            . 'podés hacerlo desde tu pedido con el botón <b>Pagar con Mercado Pago</b>.</p>';
    } else {
        $pago = '<p>Te vamos a enviar el link de pago de Mercado Pago para confirmarlo.</p>';
    }
    $pago .= '<p>Te avisamos por mail cuando llegue a tu kiosco.</p>';
    mail_enviar($p['email'], "Misión en marcha: tu pedido #$ped_id",
        '<p>Hola ' . e($p['nombre']) . ', ¡gracias! Tu colección está a punto de crecer.</p>' . $detalle . $pago
        . ($p['pago_metodo'] === 'transferencia'
            ? mail_link("pedido.php?id=$ped_id#comprobante", 'Subir el comprobante')
            : mail_link("pedido.php?id=$ped_id", 'Ver mi pedido')));
}

/** Aviso al operador: el cliente subió el comprobante de transferencia de su pedido. */
function notificar_comprobante(int $ped_id): void {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$ped_id])->fetch();
    if (!$p) return;
    mail_enviar(MAIL_OPERADOR, "Comprobante de transferencia — pedido #$ped_id — " . precio((float)$p['total']),
        '<p><b>' . e(nombre_cliente($p)) . '</b> (' . e($p['email']) . ') subió el comprobante de la transferencia del pedido <b>#' . $ped_id . '</b> por <b>'
        . precio((float)$p['total']) . '</b>.</p><p>Fijate que la plata haya entrado al banco y pasá el pedido a <b>Pago confirmado</b>.</p>'
        . mail_link("admin/pedido.php?id=$ped_id", 'Ver el pedido y el comprobante'));
}

// El kiosco del pedido, con los datos que tenga hoy en Puntos de retiro (si se borró, lo que quedó en el pedido).
function pedido_kiosco(array $p): array {
    $k = $p['punto_id'] ? q("SELECT * FROM puntos_retiro WHERE id=?", [$p['punto_id']])->fetch() : null;
    return [
        'nombre'      => $k['nombre'] ?? ($p['envio_punto'] ?: 'tu kiosco'),
        'responsable' => $k['responsable'] ?? null,
        'direccion'   => trim(($k['direccion'] ?? $p['envio_direccion']) . ', ' . ($k['localidad'] ?? $p['envio_localidad']), ', '),
        'horario'     => $k['horario'] ?? null,
        'telefono'    => $k['telefono'] ?? null,
    ];
}

// Mails de logística: en camino (con el seguimiento de Caddy) y llegó al kiosco (con quién lo tiene y el DNI).
function mail_pedido_logistica(array $p, string $estado): ?array {
    $id = (int)$p['id'];
    $seg = $p['caddy_seguimiento'] ? '<p>Código de seguimiento de Caddy: <b>' . e($p['caddy_seguimiento']) . '</b></p>' : '';
    $k = pedido_kiosco($p);
    $quien = e($k['nombre']) . ($k['responsable'] ? ' · ' . e($k['responsable']) : '');
    if ($estado === 'en_camino') {
        return ["Tu pedido #$id va en camino a tu kiosco",
            '<p>Hola ' . e($p['nombre']) . ',</p><p>Tu pedido <b>#' . $id . '</b> ya salió del depósito y va en camino a <b>' . $quien . '</b>.</p>'
            . $seg . '<p>Te avisamos apenas llegue al kiosco.</p>'];
    }
    if ($estado === 'enviado') {
        $filas = [['Kiosco', '<b>' . $quien . '</b>']];
        if ($k['direccion']) $filas[] = ['Dirección', e($k['direccion'])];
        if ($k['horario']) $filas[] = ['Horario', e($k['horario'])];
        if ($k['telefono']) $filas[] = ['Teléfono', e($k['telefono'])];
        return ["Tu pedido #$id ya está en tu kiosco",
            '<p>Hola ' . e($p['nombre']) . ',</p><p>¡Llegó! Tu pedido <b>#' . $id . '</b> ya se encuentra en poder de <b>' . $quien . '</b>.</p>'
            . mail_tabla($filas) . $seg
            . '<p>Comunicate con el kiosco para concretar la entrega.</p>'
            . '<p style="background:#fff6e0;padding:12px;border-radius:8px"><b>No te olvides de llevar tu DNI</b>: el kiosquero puede pedírtelo.</p>'];
    }
    return null;
}

function notificar_cambio_estado(string $entidad, int $id, string $estado, ?string $nota): void {
    if ($entidad === 'pedido') {
        $r = q("SELECT p.*, c.nombre, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$id])->fetch();
        if ($r && ($m = mail_pedido_logistica($r, $estado))) {
            mail_enviar($r['email'], $m[0], $m[1]
                . ($nota ? '<p style="background:#f2f8f8;padding:12px;border-radius:8px">' . nl2br(e($nota)) . '</p>' : '')
                . mail_link("pedido.php?id=$id", 'Ver mi pedido'));
            return;
        }
        [$label, $texto] = ESTADOS_PEDIDO[$estado] ?? [$estado, ''];
        $asunto = "Tu pedido #$id: $label";
        $link = mail_link("pedido.php?id=$id", 'Ver mi pedido');
    } else {
        $r = q("SELECT c.nombre, c.email FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.id=?", [$id])->fetch();
        [$label, $texto] = ESTADOS_SOLICITUD[$estado] ?? [$estado, ''];
        $asunto = "Tu búsqueda #$id: $label";
        $link = mail_link("cuenta.php?tab=faltantes", 'Ver mis búsquedas');
    }
    if (!$r) return;
    mail_enviar($r['email'], $asunto,
        '<p>Hola ' . e($r['nombre']) . ',</p><p>' . e($texto) . '</p>'
        . ($nota ? '<p style="background:#f2f8f8;padding:12px;border-radius:8px">' . nl2br(e($nota)) . '</p>' : '')
        . $link);
}
