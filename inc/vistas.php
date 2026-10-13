<?php
// Fragmentos de HTML reutilizables.

// Total de números de la colección completa: el cargado, o "Total de entregas: N" de la descripción (Brihet)
function coleccion_total(array $c): ?int {
    if (!empty($c['total_entregas'])) return (int)$c['total_entregas'];
    if (preg_match('/total de (?:entregas|n[uú]meros|fasc[ií]culos)\D{0,10}(\d+)/iu', (string)($c['descripcion'] ?? ''), $m)) return (int)$m[1];
    return null;
}

function tarjeta_coleccion(array $c): string {
    $img = $c['imagen'] ? ' style="background-image:url(\'' . e(img($c['imagen'])) . '\')"' : '';
    $tot = coleccion_total($c) ?? (int)($c['total'] ?? 0);   // la colección completa; si no se sabe, los cargados
    $cant = $tot ? $tot . ' números' : '';
    return '<a class="col-card" href="' . url('coleccion.php?c=' . urlencode($c['slug'])) . '">'
        . '<div class="col-card-img"' . $img . '>' . (!empty($c['proximamente']) ? '<span class="chip chip-amarillo">Próximamente' . (!empty($c['lanzamiento']) ? ' · ' . e($c['lanzamiento']) : '') . '</span>' : '') . '</div>'
        . '<div class="col-card-body"><h3>' . e($c['nombre']) . '</h3>'
        . '<div class="col-card-meta"><span class="muted">' . $cant . '</span>'
        . ($c['desde'] !== null && $c['desde'] !== '' ? '<strong>' . precio((float)$c['desde']) . '</strong>' : '<span class="precio-confirmar">Precio a confirmar</span>') . '</div>'
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

const MINI_NUMS_TODOS = 30;    // hasta esta cantidad de números se muestran todos
const MINI_NUMS_FALTAN = 18;   // más que eso: solo los que faltan, hasta este tope y "+N"

function tarjeta_mi_coleccion(array $c): string {
    $total = count($c['items']);
    $pct = $total ? round($c['tengo'] * 100 / $total) : 0;
    $faltan = $total - $c['tengo'];
    // Colecciones largas (ej: 120 números): solo los que faltan, para que la tarjeta no crezca sin fin
    $muestra = $c['items'];
    $resto = 0;
    if ($total > MINI_NUMS_TODOS) {
        $muestra = array_values(array_filter($c['items'], fn($it) => $it['estado'] !== 'tengo'));
        $resto = max(0, count($muestra) - MINI_NUMS_FALTAN);
        $muestra = array_slice($muestra, 0, MINI_NUMS_FALTAN);
    }
    $nums = '';
    foreach ($muestra as $it) {
        $nums .= '<span class="' . e($it['estado']) . '" title="' . e(num((int)$it['numero']) . ' — ' . $it['titulo']) . '">' . (int)$it['numero'] . '</span>';
    }
    if ($resto) $nums .= '<span class="mas" title="Y ' . $resto . ' más que te faltan">+' . $resto . '</span>';
    $sub = $faltan ? "Faltan <b>$faltan</b>" . ($c['pendientes'] ? " · {$c['pendientes']} en búsqueda o en camino" : '') : '¡Colección completa! 🏆';
    return '<a class="mi-col" href="' . url('coleccion.php?c=' . urlencode($c['slug'])) . '">'
        . foto($c['imagen'] ?? '', '📚')
        . '<div><h3>' . e($c['nombre']) . '</h3>'
        . '<div class="progreso"><span style="width:' . $pct . '%"></span></div>'
        . '<div class="progreso-txt"><span>' . $c['tengo'] . ' de ' . $total . '</span><span>' . $sub . '</span></div>'
        . '<div class="mini-nums">' . $nums . '</div>'
        . '</div></a>';
}


// ─── Página de cada número: galería y video ─────────────────────────────────
const FOTOS_EXTRA_MAX = 4;   // además de la principal

function url_numero(string $slug, int $numero): string {
    return url('numero.php?c=' . urlencode($slug) . '&n=' . $numero);
}

// Fotos de un número: la principal primero y después las extra
function fotos_item(array $it): array {
    $fotos = $it['imagen'] ? [$it['imagen']] : [];
    foreach (q("SELECT imagen FROM item_fotos WHERE item_id=? ORDER BY orden, id", [$it['id']]) as $f) $fotos[] = $f['imagen'];
    return $fotos;
}

// Link de YouTube o Instagram → [tipo, url para embeber o abrir, miniatura]. null si no es un video de ahí.
function video_info(?string $url): ?array {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) return null;
    if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
        return ['youtube', 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&autoplay=1', 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg'];
    }
    if (preg_match('#instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $url, $m)) {
        return ['instagram', 'https://www.instagram.com/p/' . $m[1] . '/', null];
    }
    return null;
}
