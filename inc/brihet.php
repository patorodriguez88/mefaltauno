<?php
// Importar el catálogo de brihet.com.ar (WooCommerce, API pública de tienda /wp-json/wc/store/v1).
// En Brihet cada producto "variable" es una colección y cada variante es un número ("Fascículo #12").
// Se importan solo los números que Brihet tiene en stock; el stock de la web lo sigue manejando WePoint.
// Precio: el de Brihet tal cual. Su "envío" fijo de $1.600 por número se cobra aparte en el carrito como
// "Recargo interior" (Admin → Ajustes). Las fotos se bajan a nuestro servidor (uploads/).
require_once __DIR__ . '/subidas.php';
require_once __DIR__ . '/vistas.php';

const BRIHET_API = 'https://brihet.com.ar/wp-json/wc/store/v1/products';
const BRIHET_CACHE_MIN = 60;   // el catálogo leído se guarda una hora (no pedirle todo a Brihet en cada clic)

function brihet_get(string $query): array {
    $ch = curl_init(BRIHET_API . '?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MeFaltaUno/1.0; +https://web.mefaltauno.com.ar)',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($r)) throw new Exception("Brihet no respondió bien (HTTP $code).");
    return $r;
}

// Catálogo completo: [colecciones (productos variables) indexadas por id, variantes agrupadas por colección]
function brihet_catalogo(bool $refrescar = false): array {
    $cache = dirname(__DIR__) . '/storage/brihet_catalogo.json';
    if (!$refrescar && is_file($cache) && filemtime($cache) > time() - BRIHET_CACHE_MIN * 60) {
        return json_decode((string)file_get_contents($cache), true);
    }
    @set_time_limit(300);
    $productos = [];
    for ($p = 1; $p <= 20; $p++) {
        $lote = brihet_get("per_page=100&page=$p");
        foreach ($lote as $x) if ($x['type'] === 'variable') $productos[$x['id']] = $x;
        if (count($lote) < 100) break;
    }
    $variantes = [];
    for ($p = 1; $p <= 60; $p++) {
        $lote = brihet_get("type=variation&per_page=100&page=$p");
        foreach ($lote as $v) $variantes[$v['parent']][] = $v;
        if (count($lote) < 100) break;
        usleep(400000);   // de a poco: no cargar el servidor de Brihet
    }
    $out = ['leido' => date('Y-m-d H:i:s'), 'colecciones' => $productos, 'variantes' => $variantes];
    @file_put_contents($cache, json_encode($out));
    return $out;
}

function brihet_texto(?string $html): string {
    $t = preg_replace('#<(br|/p|/li|/h\d|/div)[^>]*>#i', "\n", (string)$html);
    $t = preg_replace('#<li[^>]*>#i', '• ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\u{a0}", ' ', $t);
    $lineas = [];
    foreach (preg_split('/\R/', $t) as $l) {
        $l = trim($l);
        if ($l === '' || preg_match('/^(salvat|productos disponibles en kioscos|im[aá]genes a modo ilustrativo)/iu', $l)) continue;
        $lineas[] = $l;
    }
    return implode("\n", $lineas);
}

function brihet_nombre(array $p): string {
    return trim(html_entity_decode($p['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function brihet_numero(array $v): ?int {
    return preg_match('/#\s*(\d+)/', (string)($v['variation'] ?? ''), $m) ? (int)$m[1] : null;
}

// Cantidad que informa Brihet ("30 disponibles"); 0 si no tiene stock. Solo referencia para el operador.
function brihet_cantidad(array $v): int {
    if (empty($v['is_in_stock'])) return 0;
    if (preg_match('/(\d+)\s+disponible/i', (string)($v['stock_availability']['text'] ?? ''), $m)) return (int)$m[1];
    return (int)($v['low_stock_remaining'] ?? 0);
}

function brihet_precio(array $x): float {
    $minor = (int)($x['prices']['currency_minor_unit'] ?? 2);
    return (int)($x['prices']['price'] ?? 0) / (10 ** $minor);
}


// Categoría de la web según las de Brihet
function brihet_categoria(array $p): ?int {
    $nombres = mb_strtolower(implode(' ', array_column($p['categories'] ?? [], 'name')));
    $slug = match (true) {
        (bool)preg_match('/mu[ñn]ecos|juegos|ingenio|crochet/u', $nombres) => 'juegos',
        (bool)preg_match('/autos|veh[ií]culos|armables|barcos|buses|aviones|objetos de colecci/u', $nombres) => 'modelismo',
        default => 'libros',
    };
    $id = q("SELECT id FROM categorias WHERE slug=?", [$slug])->fetchColumn();
    return $id ? (int)$id : null;
}

function brihet_normalizar(string $s): string {
    $s = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

// Colección de la web que corresponde a una de Brihet: la ya vinculada o una con el mismo nombre
function brihet_coleccion_web(array $p): ?array {
    $c = q("SELECT * FROM colecciones WHERE brihet_id=?", [$p['id']])->fetch();
    if ($c) return $c;
    $n = brihet_normalizar(brihet_nombre($p));
    foreach (q("SELECT * FROM colecciones WHERE brihet_id IS NULL")->fetchAll() as $c) {
        if (brihet_normalizar($c['nombre']) === $n) return $c;
    }
    return null;
}

// Baja una foto de Brihet (solo de brihet.com.ar) y la guarda como subida. Reusa la misma URL dentro del request.
function brihet_foto(string $url, string $carpeta, string $prefijo): ?string {
    static $hechas = [];
    if (isset($hechas[$url])) return $hechas[$url];
    if (parse_url($url, PHP_URL_HOST) !== 'brihet.com.ar') return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MeFaltaUno/1.0)']);
    $datos = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$datos || strlen($datos) > 8 * 1024 * 1024) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'bri');
    file_put_contents($tmp, $datos);
    try {
        $r = subir_imagen(['name' => 'foto.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($datos)], $carpeta, $prefijo);
    } catch (Exception $e) {
        $r = null;
    } finally {
        @unlink($tmp);
    }
    return $hechas[$url] = $r;
}

// Importa (o actualiza) una colección de Brihet: sus números con stock. Nunca pisa lo que el operador editó:
// título y foto solo se completan si están vacíos; el precio solo cambia si sigue siendo el que vino de Brihet.
// $destino: id de una colección existente para vincular, 0 = la que corresponda (o nueva).
function brihet_importar(int $brihet_id, int $destino = 0): array {
    @set_time_limit(300);
    $cat = brihet_catalogo();
    $p = $cat['colecciones'][$brihet_id] ?? null;
    if (!$p) throw new Exception('Esa colección ya no está en Brihet.');
    $nombre = brihet_nombre($p);
    $col = $destino ? q("SELECT * FROM colecciones WHERE id=?", [$destino])->fetch() : brihet_coleccion_web($p);
    $res = ['coleccion' => $nombre, 'nueva' => false, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];

    if (!$col) {
        $slug = slugify($nombre);
        for ($i = 2; q("SELECT 1 FROM colecciones WHERE slug=?", [$slug])->fetchColumn(); $i++) $slug = slugify($nombre) . "-$i";
        $portada = !empty($p['images'][0]['src']) ? brihet_foto($p['images'][0]['src'], 'colecciones', $slug) : null;
        $desc = brihet_texto($p['short_description'] ?: $p['description']) ?: null;
        q("INSERT INTO colecciones (categoria_id, slug, nombre, descripcion, imagen, activa, brihet_id, total_entregas) VALUES (?,?,?,?,?,1,?,?)",
          [brihet_categoria($p), $slug, $nombre, $desc, $portada, $brihet_id, coleccion_total(['descripcion' => $desc])]);
        $col = q("SELECT * FROM colecciones WHERE id=?", [db()->lastInsertId()])->fetch();
        $res['nueva'] = true;
    } else {
        // Ya existía: se vincula y se completa lo que falte
        $desc = $col['descripcion'] ?: (brihet_texto($p['short_description'] ?: $p['description']) ?: null);
        $portada = $col['imagen'] ?: (!empty($p['images'][0]['src']) ? brihet_foto($p['images'][0]['src'], 'colecciones', $col['slug']) : null);
        q("UPDATE colecciones SET brihet_id=?, descripcion=?, imagen=?, categoria_id=COALESCE(categoria_id, ?), total_entregas=COALESCE(total_entregas, ?) WHERE id=?",
          [$brihet_id, $desc, $portada, brihet_categoria($p), coleccion_total(['descripcion' => brihet_texto($p['short_description'] ?: $p['description'])]), $col['id']]);
    }

    $foto_col = $p['images'][0]['src'] ?? null;
    foreach ($cat['variantes'][$brihet_id] ?? [] as $v) {
        $num = brihet_numero($v);
        if (!$num) continue;
        // Cantidad de Brihet (referencia), también para los que se quedaron sin stock allá
        q("UPDATE items SET llegada_at = IF(stock<=0 AND COALESCE(brihet_stock,0)<=0 AND ?>0, NOW(), llegada_at), brihet_stock=?, brihet_stock_at=NOW() WHERE coleccion_id=? AND numero=?",
          [brihet_cantidad($v), brihet_cantidad($v), $col['id'], $num]);
        if (empty($v['is_in_stock'])) continue;
        $titulo = mb_substr(brihet_texto($v['description'] ?? '') ?: ('Fascículo ' . $num), 0, 255);
        $titulo = strtok($titulo, "\n");
        $precio = brihet_precio($v);
        $url_foto = $v['images'][0]['src'] ?? $foto_col;
        $it = q("SELECT * FROM items WHERE coleccion_id=? AND numero=?", [$col['id'], $num])->fetch();
        if (!$it) {
            $foto = $url_foto ? brihet_foto($url_foto, 'items', $col['slug'] . '-' . $num) : null;
            $activo = item_falta_para_publicar($foto, $titulo, $precio) ? 0 : 1;
            q("INSERT INTO items (coleccion_id, numero, titulo, imagen, foto_origen, precio, stock, activo, brihet_id, brihet_precio, brihet_stock, brihet_stock_at, llegada_at) VALUES (?,?,?,?,?,?,0,?,?,?,?,NOW(),NOW())",
              [$col['id'], $num, $titulo, $foto, $foto ? 'brihet' : null, $precio, $activo, $v['id'], $precio, brihet_cantidad($v)]);
            $res['creados']++;
            continue;
        }
        $cambios = [];
        $vals = [];
        if (preg_match('/^(n[uú]mero|fasc[ií]culo)\s*\d+$/iu', trim($it['titulo'])) && $titulo !== $it['titulo']) { $cambios[] = 'titulo=?'; $vals[] = $titulo; }
        if (!$it['imagen'] && $url_foto && ($foto = brihet_foto($url_foto, 'items', $col['slug'] . '-' . $num))) { $cambios[] = 'imagen=?'; $vals[] = $foto; $cambios[] = "foto_origen='brihet'"; }
        $sin_tocar = (float)$it['precio'] <= 0 || ($it['brihet_precio'] !== null && abs((float)$it['precio'] - (float)$it['brihet_precio']) < 0.01);
        if ($sin_tocar && abs((float)$it['precio'] - $precio) >= 0.01) { $cambios[] = 'precio=?'; $vals[] = $precio; }
        $bp_cambia = $sin_tocar && ($it['brihet_precio'] === null || abs((float)$it['brihet_precio'] - $precio) >= 0.01);
        if ((int)$it['brihet_id'] !== (int)$v['id'] || $bp_cambia) {
            $cambios[] = 'brihet_id=?'; $vals[] = $v['id'];
            $cambios[] = 'brihet_precio=?'; $vals[] = $sin_tocar ? $precio : $it['brihet_precio'];
        }
        if ($cambios) {
            $vals[] = $it['id'];
            q('UPDATE items SET ' . implode(', ', $cambios) . ' WHERE id=?', $vals);
            $res['actualizados']++;
        } else {
            $res['sin_cambios']++;
        }
    }
    $res['coleccion_id'] = (int)$col['id'];
    return $res;
}

// ─── Actualizar cantidad y precio (sin fotos) ───────────────────────────────
const BRIHET_REFRESCO_MIN = 10;   // en el carrito no se vuelve a consultar un número si el dato tiene menos que esto

// Aplica a un número lo que informa Brihet: cantidad siempre; precio solo si no lo editó el operador.
function brihet_aplicar(array $it, array $v): void {
    $precio = brihet_precio($v);
    $sin_tocar = (float)$it['precio'] <= 0 || ($it['brihet_precio'] !== null && abs((float)$it['precio'] - (float)$it['brihet_precio']) < 0.01);
    if ($sin_tocar && $precio > 0) {
        q("UPDATE items SET llegada_at = IF(stock<=0 AND COALESCE(brihet_stock,0)<=0 AND ?>0, NOW(), llegada_at), brihet_stock=?, brihet_stock_at=NOW(), precio=?, brihet_precio=? WHERE id=?",
          [brihet_cantidad($v), brihet_cantidad($v), $precio, $precio, $it['id']]);
    } else {
        q("UPDATE items SET llegada_at = IF(stock<=0 AND COALESCE(brihet_stock,0)<=0 AND ?>0, NOW(), llegada_at), brihet_stock=?, brihet_stock_at=NOW() WHERE id=?",
          [brihet_cantidad($v), brihet_cantidad($v), $it['id']]);
    }
}

// En vivo para unos pocos números (el carrito): una consulta por número, todas en paralelo y con tiempo corto.
// Si Brihet no responde, queda el último dato guardado. Devuelve cuántos se actualizaron.
function brihet_refrescar_items(array $item_ids): int {
    $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
    if (!$item_ids) return 0;
    $in = implode(',', array_fill(0, count($item_ids), '?'));
    $its = q("SELECT id, precio, brihet_id, brihet_precio FROM items WHERE id IN ($in) AND brihet_id IS NOT NULL
              AND (brihet_stock_at IS NULL OR brihet_stock_at < DATE_SUB(NOW(), INTERVAL " . BRIHET_REFRESCO_MIN . " MINUTE))", $item_ids)->fetchAll();
    if (!$its) return 0;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($its as $it) {
        $ch = curl_init(BRIHET_API . '/' . (int)$it['brihet_id']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
                                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MeFaltaUno/1.0; +https://web.mefaltauno.com.ar)']);
        curl_multi_add_handle($mh, $ch);
        $hs[] = [$ch, $it];
    }
    do { $st = curl_multi_exec($mh, $activos); if ($activos) curl_multi_select($mh, 1); } while ($activos && $st === CURLM_OK);
    $n = 0;
    foreach ($hs as [$ch, $it]) {
        $v = json_decode((string)curl_multi_getcontent($ch), true);
        if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_array($v) && isset($v['prices'])) {
            brihet_aplicar($it, $v);
            $n++;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $n;
}

// Botón del operador: relee todo el catálogo y actualiza cantidad y precio de todo lo importado (sin fotos ni números nuevos)
function brihet_actualizar_todo(): array {
    $cat = brihet_catalogo(true);
    $por_id = [];
    foreach ($cat['variantes'] as $lista) foreach ($lista as $v) $por_id[(int)$v['id']] = $v;
    $res = ['actualizados' => 0, 'sin_dato' => 0];
    foreach (q("SELECT id, precio, brihet_id, brihet_precio FROM items WHERE brihet_id IS NOT NULL")->fetchAll() as $it) {
        if (isset($por_id[(int)$it['brihet_id']])) { brihet_aplicar($it, $por_id[(int)$it['brihet_id']]); $res['actualizados']++; }
        else { q("UPDATE items SET brihet_stock=0, brihet_stock_at=NOW() WHERE id=?", [$it['id']]); $res['sin_dato']++; }   // ya no está en Brihet
    }
    return $res;
}
