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
        foreach ($lineas as $l) {
            q("UPDATE items SET stock = stock + ?, limite = IF(limite IS NULL, NULL, limite + ?) WHERE id=?", [$l['fisico'], $l['cantidad'], $l['item_id']]);
            registrar_movimiento((int)$l['item_id'], 'cancelacion', (int)$l['fisico'], ['pedido_id' => $id, 'cliente_id' => (int)$p['cliente_id'], 'usuario' => $usuario]);
        }
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
    // Los avisos de logística (Brihet, WePoint) son para el operador: cuando confirma Mercado Pago con el cliente
    // en la página, no se le muestran a él (el encargue ya se le avisó en el carrito y el checkout).
    $al_operador = es_admin();
    $encargue_pend = (int)q("SELECT COALESCE(SUM(encargue),0) FROM pedido_items WHERE pedido_id=? AND encargue>0 AND encargue_estado<>'recibido'", [$id])->fetchColumn();
    if ($encargue_pend && in_array($nuevo, ['confirmado', 'preparando'], true) && !$p['wepoint_orden_id'] && $al_operador) {
        flash('info', "Este pedido tiene $encargue_pend unidades por encargue a Brihet: la orden a WePoint se manda cuando lleguen al depósito.");
    }
    if (!$encargue_pend && in_array($nuevo, ['confirmado', 'preparando'], true) && !$p['wepoint_orden_id'] && wepoint_ordenes_activas()) {
        try {
            wepoint_crear_orden($id);
            if ($al_operador) flash('ok', 'Orden enviada a WePoint para preparar.');
        } catch (Exception $e) {
            if ($al_operador) flash('error', 'No se pudo enviar a WePoint: ' . $e->getMessage());
            else error_log("[wepoint] pedido #$id confirmado por Mercado Pago sin orden: " . $e->getMessage());
        }
    }
    if ($avisar) {
        require_once dirname(__DIR__) . '/inc/notificaciones.php';
        notificar_cambio_estado('pedido', $id, $nuevo, $nota);
    }
}

// Borra un pedido de prueba (solo maestros, admin/pedido.php). Si no estaba cancelado, antes lo cancela sin avisar
// para devolver el stock y el uso del cupón. Las reservas y arrepentimientos que lo nombraban quedan sin pedido.
function pedido_borrar(int $id, string $usuario): void {
    $estado = q("SELECT estado FROM pedidos WHERE id=?", [$id])->fetchColumn();
    if ($estado === false) throw new Exception('Pedido no encontrado.');
    if ($estado !== 'cancelado') pedido_cambiar_estado($id, 'cancelado', 'Cancelado para borrarlo (prueba).', false, $usuario);
    $comprobante = q("SELECT comprobante FROM pedidos WHERE id=?", [$id])->fetchColumn();
    $db = db();
    $db->beginTransaction();
    q("UPDATE reservas SET pedido_id=NULL WHERE pedido_id=?", [$id]);
    q("UPDATE arrepentimientos SET pedido_id=NULL WHERE pedido_id=?", [$id]);
    q("DELETE FROM historial WHERE entidad='pedido' AND entidad_id=?", [$id]);
    q("DELETE FROM pedidos WHERE id=?", [$id]);
    $db->commit();
    comprobante_borrar_archivo($comprobante ?: null);
}

// ─── Palabra clave de retiro ────────────────────────────────────────────────
// Una sola palabra, corta y fácil de recordar, con la onda de MeFaltaUno (aventuras, héroes, colecciones):
// "COMETA", "TESORO", "JAGUAR". Le llega al cliente en el mail de "Llegó a tu kiosco" y está en la constancia
// que imprime el kiosco: antes de entregar, el kiosquero se la pide a quien retira. Sin tildes ni ñ, para que
// nadie dude cómo se dice o se escribe.
const PALABRAS_CLAVE = [
    'RAYO', 'TRUENO', 'COMETA', 'ESCUDO', 'COHETE', 'ROBOT', 'NINJA', 'MAGO', 'TESORO', 'MAPA', 'ESTRELLA', 'PLANETA',
    'LUNA', 'TORNADO', 'METEORO', 'GALAXIA', 'CAPA', 'ANTIFAZ', 'ESPADA', 'CORONA', 'CASTILLO', 'BARCO', 'GLOBO', 'TREN',
    'FARO', 'ANCLA', 'TIGRE', 'LOBO', 'PUMA', 'JAGUAR', 'ZORRO', 'PANDA', 'KOALA', 'CANGURO', 'BALLENA', 'PULPO', 'CEBRA',
    'JIRAFA', 'GORILA', 'BISONTE', 'MAMUT', 'TROMPO', 'PELOTA', 'DADO', 'FIGURITA', 'PORTADA', 'CUENTO', 'AVENTURA', 'PILOTO',
    'TURBO', 'RADAR', 'CRISTAL', 'DIAMANTE', 'MEDALLA', 'TROFEO', 'COPA', 'BANDERA', 'VOLANTE', 'MOTOR', 'CASCO', 'BRUMA',
    'SELVA', 'DESIERTO', 'ISLA', 'OASIS', 'PIRATA', 'VIKINGO', 'GIGANTE', 'CENTELLA', 'CHISPA', 'LINTERNA', 'TELESCOPIO', 'ASTRONAUTA',
];

/** La palabra clave del pedido: se sortea la primera vez que se necesita y queda fija. */
function pedido_palabra_clave(int $id): string {
    $p = q("SELECT palabra_clave, punto_id, envio_punto FROM pedidos WHERE id=?", [$id])->fetch();
    if (!$p) return '';
    if ($p['palabra_clave']) return $p['palabra_clave'];
    // Que no se repita con otro pedido sin retirar del mismo kiosco
    $usadas = q("SELECT palabra_clave FROM pedidos WHERE palabra_clave IS NOT NULL AND estado NOT IN ('entregado','cancelado')
                 AND (punto_id <=> ?) AND (envio_punto <=> ?)", [$p['punto_id'], $p['envio_punto']])->fetchAll(PDO::FETCH_COLUMN);
    $libres = array_values(array_diff(PALABRAS_CLAVE, $usadas)) ?: PALABRAS_CLAVE;
    $clave = $libres[random_int(0, count($libres) - 1)];
    q("UPDATE pedidos SET palabra_clave=? WHERE id=? AND palabra_clave IS NULL", [$clave, $id]);
    return (string)q("SELECT palabra_clave FROM pedidos WHERE id=?", [$id])->fetchColumn();
}

/** Estados con el pago ya confirmado: recién ahí se puede imprimir la constancia de entrega. */
const ESTADOS_CON_PAGO = ['confirmado', 'preparando', 'en_camino', 'enviado', 'entregado'];
