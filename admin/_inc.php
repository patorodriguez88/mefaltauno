<?php
// Común a todas las páginas del admin.
require dirname(__DIR__) . '/inc/bootstrap.php';
$admin = requiere_admin();

function admin_header(string $titulo_pag, string $activo): void {
    global $titulo, $admin, $page_css, $page_scripts;
    $titulo = $titulo_pag . ' · Admin';
    require dirname(__DIR__) . '/inc/header.php';
    $pend_sol = (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'")->fetchColumn();
    $pend_ped = (int)q("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn();
    $links = [
        'inicio'      => ['', 'Resumen'],
        'pedidos'     => ['pedidos.php', 'Pedidos' . ($pend_ped ? " ($pend_ped)" : '')],
        'faltantes'   => ['faltantes.php', 'Me faltan' . ($pend_sol ? " ($pend_sol)" : '')],
        'colecciones' => ['colecciones.php', 'Colecciones y stock'],
        'puntos'      => ['puntos.php', 'Puntos de retiro'],
        'clientes'    => ['clientes.php', 'Clientes'],
        'cupones'     => ['cupones.php', 'Descuentos'],
        'ajustes'     => ['ajustes.php', 'Ajustes'],
    ];
    echo '<div class="admin-bar"><div class="container"><b style="color:#fff">Admin</b>';
    foreach ($links as $k => [$href, $label]) {
        echo '<a href="' . url('admin/' . $href) . '" class="' . ($k === $activo ? 'activo' : '') . '">' . e($label) . '</a>';
    }
    echo '<span class="admin-bar-der">' . e($admin['email'])
        . ' <form method="post" action="' . url('salir.php') . '">' . csrf_field() . '<button type="submit">Cerrar sesión</button></form></span>';
    echo '</div></div><section class="section" style="padding-top:28px"><div class="container">';
}

function admin_footer(): void {
    global $page_scripts;
    echo '</div></section>';
    require dirname(__DIR__) . '/inc/footer.php';
}

// Cambia el estado de un pedido aplicando sus efectos (stock, "lo tengo") y avisa al cliente.
function pedido_cambiar_estado(int $id, string $nuevo, ?string $nota, bool $avisar, string $usuario): void {
    if (!isset(ESTADOS_PEDIDO[$nuevo])) throw new Exception('Estado inválido.');
    $db = db();
    $db->beginTransaction();
    $p = q("SELECT * FROM pedidos WHERE id=? FOR UPDATE", [$id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    $viejo = $p['estado'];
    if ($viejo === $nuevo && !$nota) { $db->rollBack(); return; }
    if ($viejo === 'cancelado' && $nuevo !== 'cancelado') throw new Exception('Un pedido cancelado no se puede reabrir: el stock ya se devolvió.');

    $lineas = q("SELECT item_id, cantidad FROM pedido_items WHERE pedido_id=? AND item_id IS NOT NULL", [$id])->fetchAll();
    if ($nuevo === 'cancelado' && $viejo !== 'cancelado') {
        foreach ($lineas as $l) q("UPDATE items SET stock = stock + ?, limite = IF(limite IS NULL, NULL, limite + ?) WHERE id=?", [$l['cantidad'], $l['cantidad'], $l['item_id']]);
        if ($p['cupon_codigo']) q("UPDATE cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo=?", [$p['cupon_codigo']]);
    }
    if ($nuevo === 'entregado' && $viejo !== 'entregado') {
        foreach ($lineas as $l) q("INSERT IGNORE INTO cliente_items (cliente_id, item_id, origen) VALUES (?,?,'compra')", [$p['cliente_id'], $l['item_id']]);
    }
    q("UPDATE pedidos SET estado=? WHERE id=?", [$nuevo, $id]);
    registrar_historial('pedido', $id, $nuevo, $nota, $usuario);
    $db->commit();
    // Pago confirmado → WePoint recibe la orden de venta para preparar el pedido
    if (in_array($nuevo, ['confirmado', 'preparando'], true) && !$p['wepoint_orden_id'] && wepoint_ordenes_activas()) {
        try {
            wepoint_crear_orden($id);
            flash('ok', 'Orden enviada a WePoint para preparar.');
        } catch (Exception $e) {
            flash('error', 'No se pudo enviar a WePoint: ' . $e->getMessage());
        }
    }
    if ($avisar) {
        require_once dirname(__DIR__) . '/inc/notificaciones.php';
        notificar_cambio_estado('pedido', $id, $nuevo, $nota);
    }
}

function solicitud_cambiar_estado(int $id, string $nuevo, ?string $respuesta, bool $avisar, string $usuario): void {
    if (!isset(ESTADOS_SOLICITUD[$nuevo])) throw new Exception('Estado inválido.');
    $s = q("SELECT * FROM solicitudes WHERE id=?", [$id])->fetch();
    if (!$s) throw new Exception('Solicitud no encontrada.');
    $respuesta = $respuesta !== null ? trim($respuesta) : null;
    if ($s['estado'] === $nuevo && ($respuesta ?? '') === ($s['respuesta'] ?? '')) return;
    q("UPDATE solicitudes SET estado=?, respuesta=? WHERE id=?", [$nuevo, $respuesta ?: null, $id]);
    registrar_historial('solicitud', $id, $nuevo, $respuesta, $usuario);
    if ($avisar) {
        require_once dirname(__DIR__) . '/inc/notificaciones.php';
        notificar_cambio_estado('solicitud', $id, $nuevo, $respuesta);
    }
}
