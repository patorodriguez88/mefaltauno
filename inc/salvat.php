<?php
// Fotos en alta desde salvat.com (la editorial): Brihet publica fotos chicas (400 px) y Salvat las tiene grandes.
// El emparejamiento no es seguro (Salvat no usa nuestros números), así que el sistema busca y propone, y el operador elige.
// Solo se reemplazan fotos que vinieron de Brihet (items.foto_origen): las que subió el operador no se tocan.
require_once __DIR__ . '/subidas.php';

const SALVAT_BASE = 'https://www.salvat.com';

function salvat_get(string $url, int $timeout = 20): string {
    if (parse_url($url, PHP_URL_HOST) !== 'www.salvat.com') throw new Exception('Solo se lee salvat.com.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
                            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MeFaltaUno/1.0; +https://web.mefaltauno.com.ar)']);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $code >= 400) throw new Exception("Salvat no respondió (HTTP $code).");
    return (string)$html;
}

// Texto de búsqueda a partir del título del número: "Fascículo 08: Oracle Red Rull – S. Perez 2023" → "Oracle Red Rull S. Perez"
function salvat_consulta(string $titulo, string $coleccion = ''): string {
    $t = preg_replace('/^\s*(fasc[ií]culo|libro|entrega|n[°º]|n[uú]mero)\s*\d+\s*[:\-–—.]?\s*/iu', '', $titulo);
    $t = preg_replace('/\b(19|20)\d{2}\b/', '', $t);                    // años
    $t = preg_replace('/[^\p{L}\p{N}\s.]+/u', ' ', $t);
    $t = trim(preg_replace('/\s+/', ' ', $t));
    if ($t === '' || preg_match('/^fasc[ií]culo$/iu', $t)) $t = $coleccion;   // títulos genéricos: buscar por colección
    return mb_substr($t, 0, 80);
}

// Productos de Salvat para un texto: [[nombre, url, miniatura, grande], …]. El buscador de Salvat exige todas las
// palabras: si no hay nada, se reintenta con menos. Primero van los de la colección de Salvat que se llama parecido.
function salvat_buscar(string $texto, int $max = 6, string $coleccion = ''): array {
    $palabras = preg_split('/\s+/', trim($texto));
    $res = [];
    for ($n = count($palabras); $n >= 1 && !$res; $n = $n > 3 ? 3 : $n - 1) {
        $res = salvat_buscar_texto(implode(' ', array_slice($palabras, 0, $n)), 12);
        if ($n === 1) break;
    }
    if ($coleccion !== '') {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $coleccion) ?: $coleccion)), '-');
        usort($res, fn($a, $b) => (int)($b['coleccion'] === $slug) <=> (int)($a['coleccion'] === $slug));
    }
    return array_slice($res, 0, $max);
}

// ¿El nombre del producto de Salvat es de ese número de edición? ("Fascículo Nº 12…", "Autos Inolvidables Nº 43…", "#12")
function salvat_es_numero(string $nombre, int $numero): bool {
    return (bool)preg_match('/(?:n\s*[º°o]\.?|#|fasc[ií]culo|entrega|volumen|tomo|libro)\s*0*' . $numero . '(?!\d)/iu', $nombre);
}

// Nombre de colección sin relleno: "Construí el Titanic" → "titanic", "Arma el enorme Tiranosaurio Rex" → "tiranosaurio rex"
function salvat_nucleo(string $coleccion): string {
    $c = mb_strtolower($coleccion);
    $c = preg_replace('/^(constru[ií](te)?|arm[aá]|cre[aá]|colecci[oó]n)\s+(el|la|los|las|tu|tus|un|una)?\s*(enorme|gran)?\s*/u', '', $c);
    $c = preg_replace('/\s*[–—-].*$/u', '', $c);   // "Asterix – Colección integral" → "asterix"
    return trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $c));
}

// ¿El producto de Salvat es de la misma colección? Se comparan las palabras clave (sin "de", "el"…) de la URL
// de su colección con las del nombre nuestro: "castillo-hogwarts" ≈ "castillo de hogwarts".
function salvat_palabras(string $t): array {
    $t = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t);
    $ws = preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_diff($ws, ['de', 'del', 'el', 'la', 'los', 'las', 'y', 'en', 'tu', 'tus', 'coleccion', 'construye', 'construi']));
}
function salvat_misma_coleccion(array $c, string $coleccion): bool {
    $nuestras = salvat_palabras(salvat_nucleo($coleccion));
    $suyas = salvat_palabras($c['coleccion']);
    if (!$nuestras || !$suyas) return false;
    $comunes = array_intersect($suyas, $nuestras);
    return count($comunes) >= min(count($suyas), count($nuestras));
}

// Candidatos para un número: por "colección + número de edición" y por título. Primero los del número exacto
// y de la misma colección, después los del número, después el resto.
function salvat_candidatos(string $titulo, string $coleccion, int $numero, int $max = 4): array {
    $res = [];
    foreach ([trim(salvat_nucleo($coleccion) . ' ' . $numero), salvat_consulta($titulo, $coleccion)] as $q) {
        try { $lista = salvat_buscar($q, 8, $coleccion); } catch (Exception $e) { $lista = []; }
        foreach ($lista as $c) $res[$c['url']] ??= $c;
        usleep(200000);
    }
    $res = array_values($res);
    return salvat_ordenar($res, $coleccion, $numero, $max);
}

function salvat_ordenar(array $res, string $coleccion, int $numero, int $max): array {
    foreach ($res as &$c) {
        $c['misma'] = salvat_misma_coleccion($c, $coleccion);
        $c['numero_ok'] = $c['misma'] && salvat_es_numero($c['nombre'], $numero);   // solo cuenta si es de la misma colección
        $c['puntos'] = ($c['numero_ok'] ? 4 : 0) + ($c['misma'] ? 2 : 0) + (salvat_es_numero($c['nombre'], $numero) ? 1 : 0);
    }
    unset($c);
    usort($res, fn($a, $b) => $b['puntos'] <=> $a['puntos']);
    return array_slice($res, 0, $max);
}

function salvat_buscar_texto(string $texto, int $max): array {
    if (trim($texto) === '') return [];
    $html = salvat_get(SALVAT_BASE . '/busqueda?controller=search&s=' . urlencode($texto));
    preg_match_all('#<article[^>]*product-miniature.*?</article>#s', $html, $arts);
    $out = [];
    foreach ($arts[0] as $a) {
        if (!preg_match('#href="(https://www\.salvat\.com/[^"]+-\d+)"#', $a, $u)) continue;
        if (str_contains($u[1], '/cms/')) continue;
        preg_match('#data-src="([^"]+)"#', $a, $th);
        preg_match('#data-full-size-image-url="([^"]+)"#', $a, $big);
        preg_match('#title="([^"]+)"#', $a, $nom);   // el nombre completo (en el listado viene cortado)
        $out[] = [
            'nombre' => trim(html_entity_decode($nom[1] ?? basename($u[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'url' => $u[1], 'mini' => $th[1] ?? null,
            'grande' => isset($big[1]) ? preg_replace('#-thickbox_default/#', '-large_default/', $big[1]) : null,
            'coleccion' => explode('/', parse_url($u[1], PHP_URL_PATH) ?: '')[1] ?? '',
        ];
        if (count($out) >= $max) break;
    }
    return $out;
}

// Fotos grandes de un producto de Salvat (solo las suyas, no las de "productos relacionados")
function salvat_fotos_producto(string $url): array {
    $html = salvat_get($url);
    $fotos = [];
    if (preg_match('#<meta[^>]+property="og:image"[^>]+content="([^"]+)"#i', $html, $m)) $fotos[] = $m[1];
    preg_match_all('#https://www\.salvat\.com/\d+-(?:large|thickbox)_default/[^"\'\s>]+\.(?:jpe?g|png|webp)#i', $html, $mm);
    foreach ($mm[0] as $f) $fotos[] = $f;
    $fotos = array_values(array_unique(array_map(fn($f) => preg_replace('#-thickbox_default/#', '-large_default/', $f), $fotos)));
    if ($fotos && preg_match('#/\d+-large_default/([^/?]+)$#', $fotos[0], $m)) {
        $fotos = array_values(array_filter($fotos, fn($f) => str_ends_with(parse_url($f, PHP_URL_PATH) ?? '', '/' . $m[1])));
    }
    return array_slice($fotos, 0, 1 + FOTOS_EXTRA_MAX);
}

function salvat_bajar(string $url, string $prefijo): ?string {
    if (parse_url($url, PHP_URL_HOST) !== 'www.salvat.com') return null;
    $datos = salvat_get($url, 30);
    if (strlen($datos) > 8 * 1024 * 1024) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'sal');
    file_put_contents($tmp, $datos);
    try {
        return subir_imagen(['name' => 'foto.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($datos)], 'items', $prefijo);
    } catch (Exception $e) {
        return null;
    } finally {
        @unlink($tmp);
    }
}

// Pone las fotos de un producto de Salvat en un número: la primera de principal y el resto como extra.
// No toca fotos subidas por el operador. Devuelve cuántas fotos quedaron.
function salvat_aplicar(int $item_id, string $url_producto): int {
    $it = q("SELECT i.*, c.slug FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.id=?", [$item_id])->fetch();
    if (!$it) throw new Exception('Número no encontrado.');
    if ($it['foto_origen'] === 'manual') throw new Exception('Ese número tiene una foto subida a mano: no se reemplaza.');
    $fotos = salvat_fotos_producto($url_producto);
    if (!$fotos) throw new Exception('Ese producto de Salvat no tiene fotos.');
    $prefijo = $it['slug'] . '-' . (int)$it['numero'];
    $principal = salvat_bajar($fotos[0], $prefijo);
    if (!$principal) throw new Exception('No se pudo bajar la foto de Salvat.');
    q("UPDATE items SET imagen=?, foto_origen='salvat', salvat_url=? WHERE id=?", [$principal, $url_producto, $item_id]);
    $n = 1;
    $extras = (int)q("SELECT COUNT(*) FROM item_fotos WHERE item_id=?", [$item_id])->fetchColumn();
    foreach (array_slice($fotos, 1) as $f) {
        if ($extras >= FOTOS_EXTRA_MAX) break;
        if ($p = salvat_bajar($f, $prefijo)) {
            q("INSERT INTO item_fotos (item_id, imagen, orden) VALUES (?,?,?)", [$item_id, $p, 50 + $n]);
            $extras++;
            $n++;
        }
    }
    return $n;
}
