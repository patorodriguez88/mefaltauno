<?php
// Cambios de estado de un pedido. Los usan el panel (admin/pedido.php) y Mercado Pago (pago aprobado → confirmado).

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

    $lineas = q("SELECT item_id, cantidad, fisico FROM pedido_items WHERE pedido_id=? AND item_id IS NOT NULL", [$id])->fetchAll();
    if ($nuevo === 'cancelado' && $viejo !== 'cancelado') {
        // Vuelve al stock del depósito solo lo físico; lo de encargue nunca salió de ahí
        foreach ($lineas as $l) q("UPDATE items SET stock = stock + ?, limite = IF(limite IS NULL, NULL, limite + ?) WHERE id=?", [$l['fisico'], $l['cantidad'], $l['item_id']]);
        if ($p['cupon_codigo']) q("UPDATE cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo=?", [$p['cupon_codigo']]);
    }
    if ($nuevo === 'entregado' && $viejo !== 'entregado') {
        foreach ($lineas as $l) q("INSERT IGNORE INTO cliente_items (cliente_id, item_id, origen) VALUES (?,?,'compra')", [$p['cliente_id'], $l['item_id']]);
    }
    q("UPDATE pedidos SET estado=? WHERE id=?", [$nuevo, $id]);
    registrar_historial('pedido', $id, $nuevo, $nota, $usuario);
    $db->commit();
    // Pago confirmado → WePoint recibe la orden de venta para preparar el pedido.
    // Si tiene unidades por encargue que todavía no llegaron al depósito, la manda el operador cuando lleguen.
    $encargue_pend = (int)q("SELECT COALESCE(SUM(encargue),0) FROM pedido_items WHERE pedido_id=? AND encargue>0 AND encargue_estado<>'recibido'", [$id])->fetchColumn();
    if ($encargue_pend && in_array($nuevo, ['confirmado', 'preparando'], true) && !$p['wepoint_orden_id']) {
        flash('info', "Este pedido tiene $encargue_pend unidades por encargue a Brihet: la orden a WePoint se manda cuando lleguen al depósito.");
    }
    if (!$encargue_pend && in_array($nuevo, ['confirmado', 'preparando'], true) && !$p['wepoint_orden_id'] && wepoint_ordenes_activas()) {
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
