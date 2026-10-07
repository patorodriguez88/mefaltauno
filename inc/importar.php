<?php
// Carga inicial del catálogo desde data/catalogo-tiendanube.json
// (extraído de la tienda actual en Tiendanube). Solo inserta lo que no existe.

function importar_catalogo_tiendanube(): array {
    $json = json_decode(file_get_contents(dirname(__DIR__) . '/data/catalogo-tiendanube.json'), true);
    $cats = [
        'libros'    => ['Libros y comics', 'Novelas, clásicos, ciencia y más, en ediciones de colección.', 1],
        'modelismo' => ['Modelismo',       'Autos y miniaturas a escala para exhibir.', 2],
        'juegos'    => ['Juegos y hobbies', 'Juegos de ingenio, piedras y objetos para coleccionar.', 3],
    ];
    $db = db();
    $db->beginTransaction();
    $n = ['colecciones' => 0, 'items' => 0];
    try {
        $cat_ids = [];
        foreach ($cats as $slug => [$nombre, $desc, $orden]) {
            q("INSERT IGNORE INTO categorias (slug, nombre, descripcion, orden) VALUES (?,?,?,?)", [$slug, $nombre, $desc, $orden]);
            $cat_ids[$slug] = (int)q("SELECT id FROM categorias WHERE slug=?", [$slug])->fetchColumn();
        }
        $destacadas = ['racing-cars', 'leyendas-de-la-moda', 'julio-verne-en-miniatura', 'rapido-furioso-coleccion'];
        foreach ($json as $orden => $c) {
            if (q("SELECT id FROM colecciones WHERE slug=?", [$c['slug']])->fetchColumn()) continue;
            $desc = trim($c['descripcion'] ?? '');
            $bajada = mb_strimwidth(preg_split('/(?<=[.!?])\s/', $desc)[0] ?? '', 0, 250, '…');
            q("INSERT INTO colecciones (categoria_id, slug, nombre, bajada, descripcion, imagen, destacada, orden) VALUES (?,?,?,?,?,?,?,?)", [
                $cat_ids[$c['categoria']] ?? null, $c['slug'], titulo_lindo($c['nombre']), $bajada, $desc,
                $c['imagen'] ?: null, in_array($c['slug'], $destacadas, true) ? 1 : 0, $orden,
            ]);
            $col_id = (int)$db->lastInsertId();
            $n['colecciones']++;
            foreach ($c['items'] as $i => $it) {
                q("INSERT INTO items (coleccion_id, numero, titulo, imagen, precio, precio_promo, stock) VALUES (?,?,?,?,?,?,?)", [
                    $col_id, $i + 1, titulo_lindo($it['titulo']), $it['img'] ?: null,
                    (float)$it['precio'], $it['promo'] ? (float)$it['promo'] : null, max(0, (int)($it['stock'] ?? 0)),
                ]);
                $n['items']++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $n;
}

// "RACING CARS" → "Racing Cars" (respeta siglas cortas y escalas como 1/43)
function titulo_lindo(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strtoupper($s) !== $s) return $s;
    $menores = ['de', 'del', 'la', 'las', 'el', 'los', 'en', 'y', 'a', 'al', 'un', 'una', 'por', 'con', 'para', 'o', 'lo'];
    $palabras = explode(' ', mb_strtolower($s));
    foreach ($palabras as $i => &$p) {
        if ($i > 0 && in_array($p, $menores, true)) continue;
        $p = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
    }
    unset($p);
    $s = implode(' ', $palabras);
    $s = preg_replace(['/\bColeccicion\b/u', '/\bColeccion\b/u', '/\bRapido\b/u'], ['Colección', 'Colección', 'Rápido'], $s);
    // Siglas conocidas
    return preg_replace_callback('/\b(Amg|Gt|Gti|Wrc|Sf\d*|Mclaren|Bmw|Rs|Gtr|Ii|Iii|Iv|Xx)\b/u', function ($m) {
        $map = ['Mclaren' => 'McLaren'];
        return $map[$m[1]] ?? mb_strtoupper($m[1]);
    }, $s);
}
