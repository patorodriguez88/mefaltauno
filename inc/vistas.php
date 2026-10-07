<?php
// Fragmentos de HTML reutilizables.

function tarjeta_coleccion(array $c): string {
    $img = $c['imagen'] ? ' style="background-image:url(\'' . e($c['imagen']) . '\')"' : '';
    $chip = !empty($c['total']) ? '<span class="chip">' . (int)$c['total'] . ' números</span>' : '';
    return '<a class="col-card" href="' . url('coleccion.php?c=' . urlencode($c['slug'])) . '">'
        . '<div class="col-card-img"' . $img . '>' . $chip . '</div>'
        . '<div class="col-card-body"><h3>' . e($c['nombre']) . '</h3>'
        . '<div class="col-card-meta"><span class="muted">desde</span><strong>' . precio((float)($c['desde'] ?? 0)) . '</strong></div>'
        . '</div></a>';
}

// Colecciones que sigue el cliente, con su progreso
function mis_colecciones(int $cliente_id, int $limite = 0): array {
    $sql = "SELECT c.id, c.slug, c.nombre, c.imagen FROM cliente_colecciones cc JOIN colecciones c ON c.id=cc.coleccion_id
            WHERE cc.cliente_id=? AND c.activa=1 ORDER BY cc.created_at DESC" . ($limite ? ' LIMIT ' . (int)$limite : '');
    $cols = q($sql, [$cliente_id])->fetchAll();
    if (!$cols) return [];
    $estados = estados_items($cliente_id);
    foreach ($cols as &$c) {
        $c['items'] = q("SELECT id, numero, titulo FROM items WHERE coleccion_id=? AND activo=1 ORDER BY numero", [$c['id']])->fetchAll();
        $c['tengo'] = 0;
        $c['pendientes'] = 0;
        foreach ($c['items'] as &$it) {
            $it['estado'] = $estados[$it['id']] ?? 'falta';
            if ($it['estado'] === 'tengo') $c['tengo']++;
            elseif ($it['estado'] !== 'falta') $c['pendientes']++;
        }
        unset($it);
    }
    unset($c);
    return $cols;
}

function tarjeta_mi_coleccion(array $c): string {
    $total = count($c['items']);
    $pct = $total ? round($c['tengo'] * 100 / $total) : 0;
    $faltan = $total - $c['tengo'];
    $nums = '';
    foreach ($c['items'] as $it) {
        $nums .= '<span class="' . e($it['estado']) . '" title="' . e(num((int)$it['numero']) . ' — ' . $it['titulo']) . '">' . (int)$it['numero'] . '</span>';
    }
    $sub = $faltan ? "Te faltan <b>$faltan</b>" . ($c['pendientes'] ? " · {$c['pendientes']} en camino o pedidos" : '') : '¡Completa! 🎉';
    return '<a class="mi-col" href="' . url('coleccion.php?c=' . urlencode($c['slug'])) . '">'
        . '<img src="' . e($c['imagen'] ?? '') . '" alt="" loading="lazy">'
        . '<div><h3>' . e($c['nombre']) . '</h3>'
        . '<div class="progreso"><span style="width:' . $pct . '%"></span></div>'
        . '<div class="progreso-txt"><span>' . $c['tengo'] . ' de ' . $total . '</span><span>' . $sub . '</span></div>'
        . '<div class="mini-nums">' . $nums . '</div>'
        . '</div></a>';
}
