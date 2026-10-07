<?php
// Carrito en sesión: [item_id => cantidad] + código de descuento opcional.

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
    unset($_SESSION['carrito'], $_SESSION['cupon']);
}

function carrito_cupon(): ?string {
    return $_SESSION['cupon'] ?? null;
}

function carrito_set_cupon(?string $codigo): void {
    if ($codigo) $_SESSION['cupon'] = strtoupper(trim($codigo));
    else unset($_SESSION['cupon']);
}

// Líneas del carrito con datos actuales del catálogo. Ajusta cantidades a lo disponible
// y aplica el código de descuento si sigue siendo válido.
function carrito_lineas(): array {
    $vacio = ['lineas' => [], 'subtotal' => 0, 'descuento' => 0, 'total' => 0, 'cupon' => null, 'cupon_error' => null, 'ajustes' => []];
    $c = carrito();
    if (!$c) return $vacio;
    $ids = array_map('intval', array_keys($c));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q("SELECT i.*, c.nombre AS coleccion, c.slug AS coleccion_slug, c.imagen AS coleccion_imagen
               FROM items i JOIN colecciones c ON c.id=i.coleccion_id
               WHERE i.id IN ($in) AND i.activo=1 AND c.activa=1", $ids)->fetchAll();
    $out = $vacio;
    $vistos = [];
    foreach ($rows as $it) {
        $vistos[] = (int)$it['id'];
        $cant = (int)$c[$it['id']];
        $disp = disponible($it);
        if ($cant > $disp) {
            $cant = $disp;
            $out['ajustes'][] = $it['coleccion'] . ' ' . num((int)$it['numero']) . ($cant ? ": quedan $cant" : ': ya no está disponible');
            carrito_set((int)$it['id'], $cant);
            if (!$cant) continue;
        }
        $p = precio_item($it);
        $out['lineas'][] = ['item' => $it, 'cantidad' => $cant, 'precio' => $p, 'total' => $p * $cant, 'disponible' => $disp];
        $out['subtotal'] += $p * $cant;
    }
    foreach (array_diff($ids, $vistos) as $id) carrito_set($id, 0);

    if (($codigo = carrito_cupon()) && $out['lineas']) {
        try {
            $v = cupon_validar($codigo, cliente(), $out['subtotal']);
            $out['cupon'] = $v['cupon'];
            $out['descuento'] = $v['descuento'];
        } catch (Exception $e) {
            $out['cupon_error'] = $e->getMessage();
        }
    }
    $out['total'] = $out['subtotal'] - $out['descuento'];
    return $out;
}
