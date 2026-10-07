<?php
// Acciones AJAX de la tienda. POST + header X-CSRF. Responde {ok, ...} o {ok:false, error}.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/notificaciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido.'], 405);
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'La sesión expiró. Recargá la página.'], 419);

$accion = $_POST['accion'] ?? '';
$cli = cliente();

function exigir_login(?array $cli): array {
    if (!$cli) json_out(['ok' => false, 'login' => true, 'error' => 'Ingresá a tu cuenta.']);
    return $cli;
}

function item_activo(int $id): array {
    $it = q("SELECT i.*, c.nombre AS coleccion FROM items i JOIN colecciones c ON c.id=i.coleccion_id
             WHERE i.id=? AND i.activo=1 AND c.activa=1", [$id])->fetch();
    if (!$it) throw new Exception('Ese número ya no está disponible.');
    return $it;
}

function chip_estado(string $estado): string {
    $map = [
        'tengo'     => ['badge-verde', 'Lo tengo'],
        'buscando'  => ['badge-amarillo', 'En búsqueda'],
        'en_camino' => ['badge-azul', 'En camino'],
    ];
    return isset($map[$estado]) ? '<span class="badge ' . $map[$estado][0] . '">' . $map[$estado][1] . '</span>' : '';
}

try {
    switch ($accion) {

    case 'carrito_agregar':
        $it = item_activo((int)($_POST['item_id'] ?? 0));
        $actual = carrito()[$it['id']] ?? 0;
        if ($actual + 1 > (int)$it['stock']) throw new Exception($it['stock'] > 0 ? 'No quedan más unidades de ese número.' : 'Sin stock: activá la búsqueda y salimos a buscarlo.');
        carrito_set((int)$it['id'], $actual + 1);
        json_out(['ok' => true, 'carrito' => carrito_cantidad(), 'mensaje' => num((int)$it['numero']) . ' se sumó a tu equipo ⚡']);

    case 'carrito_agregar_varios':
        $n = 0;
        foreach ((array)($_POST['item_ids'] ?? []) as $id) {
            try { $it = item_activo((int)$id); } catch (Exception $e) { continue; }
            $actual = carrito()[$it['id']] ?? 0;
            if ($actual + 1 > (int)$it['stock']) continue;
            carrito_set((int)$it['id'], $actual + 1);
            $n++;
        }
        if (!$n) throw new Exception('No se pudo agregar ninguno (sin stock).');
        json_out(['ok' => true, 'carrito' => carrito_cantidad(), 'mensaje' => $n === 1 ? '1 número se sumó a tu equipo ⚡' : "$n números se sumaron a tu equipo ⚡"]);

    case 'carrito_cantidad':
        $id = (int)($_POST['item_id'] ?? 0);
        $cant = max(0, (int)($_POST['cantidad'] ?? 0));
        if ($cant > 0) {
            $it = item_activo($id);
            if ($cant > (int)$it['stock']) throw new Exception('Solo quedan ' . (int)$it['stock'] . ' unidades.');
        }
        carrito_set($id, $cant);
        json_out(['ok' => true, 'carrito' => carrito_cantidad()]);

    case 'tengo':
        $cli = exigir_login($cli);
        $it = item_activo((int)($_POST['item_id'] ?? 0));
        $siguiendo = false;
        if (!empty($_POST['tengo'])) {
            q("INSERT IGNORE INTO cliente_items (cliente_id, item_id, origen) VALUES (?,?,'manual')", [$cli['id'], $it['id']]);
            // Marcar un número suma la colección al panel automáticamente
            $siguiendo = q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $it['coleccion_id']])->rowCount() > 0;
        } else {
            q("DELETE FROM cliente_items WHERE cliente_id=? AND item_id=?", [$cli['id'], $it['id']]);
        }
        $estado = estados_items((int)$cli['id'], (int)$it['coleccion_id'])[$it['id']] ?? 'falta';
        json_out(['ok' => true, 'estado' => $estado, 'chip' => chip_estado($estado), 'siguiendo' => $siguiendo]);

    case 'seguir':
        $cli = exigir_login($cli);
        $col = (int)($_POST['coleccion_id'] ?? 0);
        if (!empty($_POST['seguir'])) {
            q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $col]);
        } else {
            q("DELETE FROM cliente_colecciones WHERE cliente_id=? AND coleccion_id=?", [$cli['id'], $col]);
        }
        json_out(['ok' => true]);

    // "Conseguímelo": números sin stock que el cliente quiere
    case 'solicitar':
        $cli = exigir_login($cli);
        $ids = array_unique(array_map('intval', (array)($_POST['item_ids'] ?? [])));
        $mensaje = trim(mb_substr($_POST['mensaje'] ?? '', 0, 2000));
        $activos = array_keys(array_filter(estados_items((int)$cli['id']), fn($e) => $e !== 'falta'));
        $items = [];
        foreach ($ids as $id) {
            if (in_array($id, $activos, true)) continue;  // ya lo tiene o ya lo pidió
            try { $items[] = item_activo($id); } catch (Exception $e) {}
        }
        if (!$items) throw new Exception('Esos números ya están pedidos o los tenés.');
        $db = db();
        $db->beginTransaction();
        q("INSERT INTO solicitudes (cliente_id, mensaje) VALUES (?,?)", [$cli['id'], $mensaje ?: null]);
        $sol = (int)$db->lastInsertId();
        foreach ($items as $it) {
            q("INSERT INTO solicitud_items (solicitud_id, item_id, descripcion) VALUES (?,?,?)",
              [$sol, $it['id'], $it['coleccion'] . ' ' . num((int)$it['numero']) . ' — ' . $it['titulo']]);
            q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $it['coleccion_id']]);
        }
        registrar_historial('solicitud', $sol, 'pendiente', null, nombre_cliente($cli));
        $db->commit();
        notificar_solicitud_nueva($sol);
        $n = count($items);
        json_out(['ok' => true, 'solicitud' => $sol, 'chip' => chip_estado('buscando'),
                  'mensaje' => $n === 1 ? '¡Misión aceptada! Salimos a buscarlo.' : "¡Misión aceptada! Salimos a buscar a los $n."]);

    default:
        throw new Exception('Acción no válida.');
    }
} catch (Exception $e) {
    if (db()->inTransaction()) db()->rollBack();
    json_out(['ok' => false, 'error' => $e->getMessage()]);
}
