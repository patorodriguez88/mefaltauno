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

    mail_enviar($s['email'], "Recibimos tu pedido #$sol_id: te lo vamos a conseguir",
        '<p>Hola ' . e($s['nombre']) . ',</p><p>Recibimos tu pedido de estos números:</p>' . $lista
        . '<p>Ya está <b>pendiente</b> y lo vamos a buscar. Te avisamos por mail apenas tengamos novedades.</p>'
        . mail_link("cuenta.php?tab=faltantes", 'Seguir mi pedido'));
}

function notificar_pedido_nuevo(int $ped_id): void {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$ped_id])->fetch();
    if (!$p) return;
    $filas = [];
    foreach (q("SELECT * FROM pedido_items WHERE pedido_id=?", [$ped_id]) as $l) {
        $filas[] = [e($l['coleccion'] . ' ' . num((int)$l['numero']) . ' — ' . $l['titulo']), 'x' . (int)$l['cantidad'], precio($l['precio'] * $l['cantidad'])];
    }
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
            . '<p>Cuando transfieras, <b>respondé este mail con el comprobante</b>. Apenas lo confirmamos, empezamos a prepararlo.</p>';
    } else {
        $pago = '<p>Te vamos a enviar el link de pago de Mercado Pago para confirmarlo.</p>';
    }
    $pago .= '<p>Te avisamos por mail cuando esté listo para retirar.</p>';
    mail_enviar($p['email'], "Recibimos tu pedido #$ped_id",
        '<p>Hola ' . e($p['nombre']) . ', ¡gracias por tu compra!</p>' . $detalle . $pago
        . mail_link("pedido.php?id=$ped_id", 'Ver mi pedido'));
}

function notificar_cambio_estado(string $entidad, int $id, string $estado, ?string $nota): void {
    if ($entidad === 'pedido') {
        $r = q("SELECT c.nombre, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$id])->fetch();
        [$label, $texto] = ESTADOS_PEDIDO[$estado] ?? [$estado, ''];
        $asunto = "Tu pedido #$id: $label";
        $link = mail_link("pedido.php?id=$id", 'Ver mi pedido');
    } else {
        $r = q("SELECT c.nombre, c.email FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.id=?", [$id])->fetch();
        [$label, $texto] = ESTADOS_SOLICITUD[$estado] ?? [$estado, ''];
        $asunto = "Tu pedido de faltantes #$id: $label";
        $link = mail_link("cuenta.php?tab=faltantes", 'Ver mis pedidos');
    }
    if (!$r) return;
    mail_enviar($r['email'], $asunto,
        '<p>Hola ' . e($r['nombre']) . ',</p><p>' . e($texto) . '</p>'
        . ($nota ? '<p style="background:#f2f8f8;padding:12px;border-radius:8px">' . nl2br(e($nota)) . '</p>' : '')
        . $link);
}
