<?php
// Reservas: cuando aparece stock de un número que alguien estaba buscando ("Me falta"), se le guarda
// a esa persona por RESERVA_HORAS para que no se lo compre otro. Por orden de llegada de las búsquedas.
// - reservas_asignar(): automática (cron de WePoint, al crear/vincular un número, al liberar una reserva).
// - reservas_vencer(): las que pasaron el plazo se liberan y pasan al siguiente (o vuelven a la venta).
// - El operador puede reservar a mano, extender o liberar desde el pedido de faltantes.
// disponible() (bootstrap) descuenta lo reservado para otros y suma lo reservado para quien mira.

const RESERVA_HORAS = 72;

// Reservas activas: [item_id => [cliente_id => cantidad]]. Cacheado por request; $refrescar dentro de transacciones.
function reservas_mapa(bool $refrescar = false): array {
    static $mapa = null;
    if ($mapa !== null && !$refrescar) return $mapa;
    $mapa = [];
    foreach (q("SELECT item_id, cliente_id, SUM(cantidad) AS cant FROM reservas WHERE estado='activa' AND stock='fisico' AND vence_at > NOW() GROUP BY item_id, cliente_id") as $r) {
        $mapa[(int)$r['item_id']][(int)$r['cliente_id']] = (int)$r['cant'];
    }
    return $mapa;
}

// Reservas sobre el stock de Brihet (por encargue): [item_id => [cliente_id => cantidad]]
function reservas_mapa_encargue(bool $refrescar = false): array {
    static $mapa = null;
    if ($mapa !== null && !$refrescar) return $mapa;
    $mapa = [];
    foreach (q("SELECT item_id, cliente_id, SUM(cantidad) AS cant FROM reservas WHERE estado='activa' AND stock='encargue' AND vence_at > NOW() GROUP BY item_id, cliente_id") as $r) {
        $mapa[(int)$r['item_id']][(int)$r['cliente_id']] = (int)$r['cant'];
    }
    return $mapa;
}

// Reserva activa de un cliente para un número (para mostrarle "te lo guardamos hasta…")
function reserva_de(int $item_id, ?int $cliente_id): ?array {
    if (!$cliente_id) return null;
    return q("SELECT * FROM reservas WHERE item_id=? AND cliente_id=? AND estado='activa' AND vence_at > NOW() ORDER BY vence_at LIMIT 1", [$item_id, $cliente_id])->fetch() ?: null;
}

// $stock: 'fisico' (depósito, entrega en 24 h) o 'encargue' (unidad de Brihet, plazos del editor)
function reserva_crear(int $item_id, int $cliente_id, ?int $solicitud_item_id, string $origen, string $usuario, string $stock = 'fisico'): int {
    q("INSERT INTO reservas (item_id, cliente_id, solicitud_item_id, cantidad, stock, estado, origen, vence_at) VALUES (?,?,?,1,?,'activa',?, DATE_ADD(NOW(), INTERVAL " . RESERVA_HORAS . " HOUR))",
      [$item_id, $cliente_id, $solicitud_item_id, $stock, $origen]);
    $rid = (int)db()->lastInsertId();
    reservas_mapa(true);
    reservas_mapa_encargue(true);
    if ($solicitud_item_id) {
        $si = q("SELECT si.solicitud_id, i.numero, c.nombre AS coleccion, c.slug, r.vence_at
                 FROM solicitud_items si JOIN items i ON i.id=? JOIN colecciones c ON c.id=i.coleccion_id JOIN reservas r ON r.id=?
                 WHERE si.id=?", [$item_id, $rid, $solicitud_item_id])->fetch();
        if ($si) {
            $nota = '¡Lo encontramos! ' . $si['coleccion'] . ' ' . num((int)$si['numero'])
                  . ($stock === 'encargue' ? ' está disponible por encargue (la entrega queda sujeta a los plazos del editor). Te lo guardamos hasta el ' : ' te lo guardamos hasta el ')
                  . fecha($si['vence_at']) . '. Pasado ese plazo vuelve a la venta.';
            require_once __DIR__ . '/notificaciones.php';
            q("UPDATE solicitudes SET estado='conseguido', respuesta=? WHERE id=?", [$nota, $si['solicitud_id']]);
            registrar_historial('solicitud', (int)$si['solicitud_id'], 'conseguido', $nota, $usuario);
            notificar_reserva((int)$si['solicitud_id'], $nota, 'coleccion.php?c=' . urlencode($si['slug']));
        }
    }
    return $rid;
}

// Unidades que se pueden reservar ahora (stock que nadie tiene reservado)
function reservas_libres(array $it): int {
    $st = !empty($it['congelado']) ? 0 : max(0, (int)$it['stock']);
    if (isset($it['limite']) && $it['limite'] !== null) $st = min($st, max(0, (int)$it['limite']));
    return max(0, $st - array_sum(reservas_mapa()[(int)$it['id']] ?? []));
}

// Reserva automática: para cada número con stock libre, primero los abonados y después las búsquedas abiertas
// por orden de llegada.
// Cada búsqueda tiene una sola reserva automática: si la dejó vencer, no se le vuelve a reservar sola.
function reservas_asignar(): int {
    $n = abonos_asignar();   // primero los abonados de la colección (inc/abonos.php), después los pedidos sueltos
    $pendientes = q("SELECT si.id AS si_id, si.item_id, s.cliente_id
                     FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id
                     WHERE si.item_id IS NOT NULL AND s.estado IN ('pendiente','buscando')
                       AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.solicitud_item_id=si.id)
                     ORDER BY s.created_at, si.id")->fetchAll();
    foreach ($pendientes as $p) {
        $it = q("SELECT id, stock, congelado, limite, activo FROM items WHERE id=?", [$p['item_id']])->fetch();
        if (!$it || !$it['activo'] || reservas_libres($it) < 1) continue;
        reserva_crear((int)$p['item_id'], (int)$p['cliente_id'], (int)$p['si_id'], 'auto', 'Reserva automática');
        $n++;
    }
    return $n;
}

// Libera las reservas vencidas y reasigna. Devuelve cuántas vencieron.
function reservas_vencer(): int {
    $vencidas = q("SELECT r.*, si.solicitud_id FROM reservas r LEFT JOIN solicitud_items si ON si.id=r.solicitud_item_id
                   WHERE r.estado='activa' AND r.vence_at <= NOW()")->fetchAll();
    foreach ($vencidas as $r) {
        q("UPDATE reservas SET estado='vencida' WHERE id=?", [$r['id']]);
        if ($r['abono_id']) abono_reserva_vencida((int)$r['abono_id']);
        if ($r['solicitud_id']) registrar_historial('solicitud', (int)$r['solicitud_id'], 'conseguido', 'Venció la reserva de ' . RESERVA_HORAS . ' h sin compra: el número volvió a la venta.', 'Reserva automática');
    }
    if ($vencidas) {
        reservas_mapa(true);
        reservas_mapa_encargue(true);
        reservas_asignar();
    }
    return count($vencidas);
}

// Al comprar: las reservas del cliente para esos números quedan usadas por el pedido
function reservas_usar(int $cliente_id, int $item_id, int $cantidad, int $pedido_id): void {
    foreach (q("SELECT id, cantidad, abono_id FROM reservas WHERE cliente_id=? AND item_id=? AND estado='activa' ORDER BY vence_at", [$cliente_id, $item_id])->fetchAll() as $r) {
        if ($cantidad <= 0) break;
        q("UPDATE reservas SET estado='usada', pedido_id=? WHERE id=?", [$pedido_id, $r['id']]);
        if ($r['abono_id']) q("UPDATE abonos SET vencidas_seguidas=0 WHERE id=?", [$r['abono_id']]);
        $cantidad -= (int)$r['cantidad'];
    }
}

// Cuántas unidades más puede comprar un cliente: disponible (con reservas) y el máximo por cliente,
// descontando lo que ya compró en pedidos no cancelados. Sin sesión, el máximo se aplica al carrito.
function comprable(array $it, ?int $cliente_id = -1): int {
    if ($cliente_id === -1) $cliente_id = (int)(cliente()['id'] ?? 0) ?: null;
    $n = disponible_total($it, $cliente_id);
    if (isset($it['max_por_cliente']) && $it['max_por_cliente'] !== null) {
        $ya = $cliente_id ? (int)q("SELECT COALESCE(SUM(pi.cantidad),0) FROM pedido_items pi JOIN pedidos p ON p.id=pi.pedido_id
                                     WHERE p.cliente_id=? AND p.estado<>'cancelado' AND pi.item_id=?", [$cliente_id, $it['id']])->fetchColumn() : 0;
        $n = min($n, max(0, (int)$it['max_por_cliente'] - $ya));
    }
    return $n;
}

// Texto para cuando el límite es el máximo por cliente (y no el stock)
function motivo_tope(array $it, int $puede): string {
    if (isset($it['max_por_cliente']) && $it['max_por_cliente'] !== null && $puede < disponible_total($it)) {
        return 'Máximo ' . (int)$it['max_por_cliente'] . ' por cliente' . ($puede ? '' : ': ya llegaste al máximo de ese número') . '.';
    }
    return $puede > 0 ? "Solo quedan $puede unidades." : 'No quedan más unidades de ese número.';
}

function notificar_reserva(int $solicitud_id, string $nota, string $path): void {
    $r = q("SELECT c.nombre, c.email FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.id=?", [$solicitud_id])->fetch();
    if (!$r) return;
    mail_enviar($r['email'], "¡Lo encontramos! Te lo guardamos " . RESERVA_HORAS . " horas",
        '<p>Hola ' . e($r['nombre']) . ',</p><p>' . e($nota) . '</p>' . mail_link($path, 'Comprarlo ahora'));
}
