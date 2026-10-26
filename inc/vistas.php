<?php
// Fragmentos de HTML reutilizables.

// Total de números de la colección completa: el cargado, o "Total de entregas: N" de la descripción (Brihet)
function coleccion_total(array $c): ?int {
    if (!empty($c['total_entregas'])) return (int)$c['total_entregas'];
    if (preg_match('/total de (?:entregas|n[uú]meros|fasc[ií]culos)\D{0,10}(\d+)/iu', (string)($c['descripcion'] ?? ''), $m)) return (int)$m[1];
    return null;
}

// Íconos de línea con los colores de la marca (verde, con un detalle amarillo: .acento) para la ficha
// de la colección y las fechas de salida, en lugar de emojis.
const ICONOS_FICHA = [
    'total'      => '<rect x="4" y="6" width="12" height="14" rx="1.5"/><path d="M8 3.5h10a1.5 1.5 0 0 1 1.5 1.5v12"/><path class="acento" d="M7.5 6v5l1.75-1.2L11 11V6z"/>',
    'calendario' => '<path class="acento" d="M3.5 7.5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2V10h-17z"/><rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M8 3v4.5M16 3v4.5M7.5 14h2M11 14h2M14.5 14h2M7.5 17h2M11 17h2"/>',
    'kiosco'     => '<path d="M4.5 5h12v13.5a1.5 1.5 0 0 0 1.5 1.5H6a1.5 1.5 0 0 1-1.5-1.5z"/><path d="M16.5 9h3v9.5a1.5 1.5 0 0 1-3 0"/><rect class="acento" x="7.5" y="8" width="6" height="4" rx=".5"/><path d="M7.5 15h6M7.5 17.5h4"/>',
    'cohete'     => '<path class="acento" d="M10 17.5c0 1.6.9 2.9 2 3.5 1.1-.6 2-1.9 2-3.5z"/><path d="M12 3c3 2.2 4.8 5.6 4.5 9.8L15 15.5H9l-1.5-2.7C7.2 8.6 9 5.2 12 3z"/><circle cx="12" cy="9.5" r="1.7"/><path d="M8.6 12.8 5.5 15.5l.5 2.5 3-1.5M15.4 12.8l3.1 2.7-.5 2.5-3-1.5"/>',
    'disponible' => '<circle class="acento" cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 4.9-5"/>',
    'tuya'       => '<path class="acento" d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5v1a3 3 0 0 0 3 3M16 6h3v1a3 3 0 0 1-3 3M12 13v3.5M9 20h6M10 16.5h4l.5 3.5h-5z"/>',
];

function icono_ficha(string $nombre, int $tam = 22): string {
    return '<svg class="ico-marca" viewBox="0 0 24 24" width="' . $tam . '" height="' . $tam . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . (ICONOS_FICHA[$nombre] ?? '') . '</svg>';
}

function tarjeta_coleccion(array $c): string {
    $img = $c['imagen'] ? ' style="background-image:url(\'' . e(img($c['imagen'])) . '\')"' : '';
    $tot = coleccion_total($c) ?? (int)($c['total'] ?? 0);   // la colección completa; si no se sabe, los cargados
    $cant = $tot ? $tot . ' números' : '';
    return '<a class="col-card" href="' . url('coleccion.php?c=' . urlencode($c['slug'])) . '">'
        . '<div class="col-card-img"' . $img . '>' . (!empty($c['proximamente']) ? '<span class="chip chip-amarillo">Próximamente' . (!empty($c['lanzamiento']) ? ' · ' . e($c['lanzamiento']) : '') . '</span>' : (!empty($c['en_calle']) ? '<span class="chip">' . icono_ficha('kiosco', 15) . ' En kioscos</span>' : '')) . '</div>'
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
