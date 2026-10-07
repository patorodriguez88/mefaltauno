<?php
// Carrito en sesión: [item_id => cantidad]

function carrito(): array {
    return $_SESSION['carrito'] ?? [];
}

function carrito_cantidad(): int {
    return array_sum(carrito());
}

function carrito_set(int $item_id, int $cant): void {
    if ($cant <= 0) {
        unset($_SESSION['carrito'][$item_id]);
    } else {
        $_SESSION['carrito'][$item_id] = $cant;
    }
}

function carrito_vaciar(): void {
    unset($_SESSION['carrito']);
}

// Líneas del carrito con datos actuales del catálogo. Ajusta cantidades al stock disponible.
function carrito_lineas(): array {
    $c = carrito();
    if (!$c) return ['lineas' => [], 'subtotal' => 0, 'ajustes' => []];
    $ids = array_map('intval', array_keys($c));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q("SELECT i.*, c.nombre AS coleccion, c.slug AS coleccion_slug, c.imagen AS coleccion_imagen
               FROM items i JOIN colecciones c ON c.id=i.coleccion_id
               WHERE i.id IN ($in) AND i.activo=1 AND c.activa=1", $ids)->fetchAll();
    $lineas = [];
    $subtotal = 0;
    $ajustes = [];
    $vistos = [];
    foreach ($rows as $it) {
        $vistos[] = (int)$it['id'];
        $cant = (int)$c[$it['id']];
        if ($cant > (int)$it['stock']) {
            $cant = max(0, (int)$it['stock']);
            $ajustes[] = $it['coleccion'] . ' ' . num((int)$it['numero']) . ($cant ? ": quedan $cant" : ': sin stock');
            carrito_set((int)$it['id'], $cant);
            if (!$cant) continue;
        }
        $p = precio_item($it);
        $lineas[] = ['item' => $it, 'cantidad' => $cant, 'precio' => $p, 'total' => $p * $cant];
        $subtotal += $p * $cant;
    }
    foreach (array_diff($ids, $vistos) as $id) carrito_set($id, 0);
    return ['lineas' => $lineas, 'subtotal' => $subtotal, 'ajustes' => $ajustes];
}
