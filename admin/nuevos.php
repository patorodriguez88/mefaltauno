<?php
// Bandeja "Por publicar": todo lo que está en la cuenta de WePoint y todavía no es un número de la web.
// Se agrupa por título de WePoint: las ediciones de un título se vinculan juntas a una colección (cada edición
// va al número de su edición). También se puede cargar una por una, con foto. Sin foto queda creado pero sin publicar.
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

const NUEVOS_TITULOS_POR_PAGINA = 25;

$buscar = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$pagina = max(1, (int)($_GET['pag'] ?? $_POST['pag'] ?? 1));
// "Cargar una por una": solo las ediciones de un título, cada una con su ficha y foto (aparte, para no cargar
// cientos de fichas en la bandeja)
$sueltas = preg_match('/^\d+$/', (string)($_GET['sueltas'] ?? $_POST['sueltas'] ?? '')) ? (string)($_GET['sueltas'] ?? $_POST['sueltas']) : '';
$volver = 'admin/nuevos.php' . (($qs = http_build_query(array_filter(['q' => $buscar, 'pag' => $pagina > 1 ? $pagina : null, 'sueltas' => $sueltas ?: null]))) ? '?' . $qs : '');

// Crea el número (o vincula el que ya existía) para una edición de la bandeja y la saca de la bandeja.
// Devuelve [item_id, lo que falta para publicar, si el número ya existía].
function nuevo_vincular(array $n, array $col, int $numero, string $titulo, float $precio, ?string $foto, string $usuario): array {
    // Una edición ata la colección a su título de WePoint (un título = una colección)
    if (!empty($n['wepoint_variante_id'])) {
        wepoint_atar_coleccion($col, (int)$n['wepoint_id'], wepoint_sku_de_titulo((int)$n['wepoint_id'], $n['sku'], $n['numero_edicion'] ? (int)$n['numero_edicion'] : null));
    }
    $existe = q("SELECT * FROM items WHERE coleccion_id=? AND numero=?", [$col['id'], $numero])->fetch();
    if ($existe) {
        // El número ya estaba cargado (ej: colección "Próximamente"): se vincula a este producto de WePoint
        if ($existe['wepoint_id'] && wepoint_clave_item($existe) !== $n['clave']) {
            throw new Exception($col['nombre'] . ' ' . num($numero) . ' ya está vinculado a otro producto de WePoint (' . wepoint_clave_item($existe) . ').');
        }
        $titulo = $existe['titulo'] !== '' ? $existe['titulo'] : $titulo;
        $precio = (float)$existe['precio'] > 0 ? (float)$existe['precio'] : $precio;
        $foto = $foto ?: $existe['imagen'];
        $falta = item_falta_para_publicar($foto, $titulo, $precio);
        q("UPDATE items SET titulo=?, imagen=?, precio=?, stock=?, sku=COALESCE(NULLIF(sku,''), ?), wepoint_id=?, wepoint_variante_id=?, stock_sync_at=NOW(), activo=? WHERE id=?",
          [$titulo, $foto, $precio, (int)$n['stock'], $n['sku'], $n['wepoint_id'], $n['wepoint_variante_id'], $falta ? 0 : 1, $existe['id']]);
        if ($foto && $foto !== $existe['imagen']) q("UPDATE items SET foto_origen='manual' WHERE id=?", [$existe['id']]);
        $item_id = (int)$existe['id'];
    } else {
        if (trim($titulo) === '') throw new Exception('Poné el título.');
        $falta = item_falta_para_publicar($foto, $titulo, $precio);
        q("INSERT INTO items (coleccion_id, numero, titulo, imagen, foto_origen, precio, stock, sku, wepoint_id, wepoint_variante_id, stock_sync_at, activo) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?)",
          [$col['id'], $numero, $titulo, $foto, $foto ? 'manual' : null, $precio, (int)$n['stock'], $n['sku'], $n['wepoint_id'], $n['wepoint_variante_id'], $falta ? 0 : 1]);
        $item_id = (int)db()->lastInsertId();
    }
    $dif = (int)$n['stock'] - ($existe ? (int)$existe['stock'] : 0);
    if ($dif) registrar_movimiento($item_id, $dif > 0 ? 'ingreso' : 'salida_wp', $dif, ['usuario' => $usuario, 'detalle' => 'Publicado desde WePoint con ' . (int)$n['stock'] . ' u.']);
    q("DELETE FROM wepoint_nuevos WHERE clave=?", [$n['clave']]);
    return [$item_id, $falta, (bool)$existe];
}

// Número de la web que le corresponde a una edición: el de la edición en WePoint, o el que se deduce del nombre o el SKU
function nuevo_numero(array $nv, array $cols): ?int {
    if (!empty($nv['numero_edicion'])) return (int)$nv['numero_edicion'];
    return wepoint_sugerir($nv, $cols)[1] ?: null;
}

// Nombre de WePoint como punto de partida para la colección nueva (se edita antes de crearla): sin el código "DGP" de Dinter
function nuevo_nombre_propuesto(?string $nombre): string {
    return trim(preg_replace('/[\s\-]*\bDGP\s*$/i', '', (string)$nombre)) ?: trim((string)$nombre);
}

// SKU del título de WePoint: el de su ficha; si todavía no se trajo, el que más se repite en sus ediciones (13739-1… → 13739)
function nuevo_sku_titulo(array $eds): ?string {
    if ($eds && !empty($eds[0]['wepoint_id']) && ($s = wepoint_sku_de_titulo((int)$eds[0]['wepoint_id']))) return $s;
    $skus = array_filter(array_map(fn($e) => wepoint_sku_titulo($e['sku'], $e['numero_edicion'] ? (int)$e['numero_edicion'] : null), $eds));
    if (!$skus) return null;
    $cnt = array_count_values($skus);
    arsort($cnt);
    return (string)array_key_first($cnt);
}

$cols = q("SELECT id, nombre, slug, sku, wepoint_titulo_id, brihet_id, proximamente FROM colecciones ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $usuario = nombre_cliente($admin);
    $col = q("SELECT * FROM colecciones WHERE id=?", [(int)($_POST['coleccion_id'] ?? 0)])->fetch();

    // ── Todas las ediciones marcadas de un título, de una vez ──
    if (($_POST['accion'] ?? '') === 'grupo') {
        try {
            $nueva = ($_POST['destino'] ?? '') === 'nueva';
            $nombre_nueva = trim(preg_replace('/\s+/', ' ', (string)($_POST['nombre_nueva'] ?? '')));
            if ($nueva) {
                if ($nombre_nueva === '') throw new Exception('Poné el nombre de la colección nueva.');
                if (q("SELECT 1 FROM colecciones WHERE nombre=?", [$nombre_nueva])->fetchColumn()) {
                    throw new Exception('Ya hay una colección “' . $nombre_nueva . '”: elegila en “A una colección que ya existe”.');
                }
            } elseif (!$col) throw new Exception('Elegí la colección.');
            $claves = array_values(array_filter(array_map('strval', (array)($_POST['claves'] ?? []))));
            if (!$claves) throw new Exception('Marcá al menos una edición.');
            $precio_comun = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['precio'] ?? '0'));
            $marcas = implode(',', array_fill(0, count($claves), '?'));
            $eds = q("SELECT * FROM wepoint_nuevos WHERE wepoint_id=? AND clave IN ($marcas) ORDER BY numero_edicion, nombre",
                     array_merge([(string)($_POST['wepoint_id'] ?? '')], $claves))->fetchAll();
            if (!$eds) throw new Exception('Esas ediciones ya no están en la bandeja.');
            $titulo_id = (int)$eds[0]['wepoint_id'];
            $sku_t = nuevo_sku_titulo(q("SELECT wepoint_id, sku, numero_edicion FROM wepoint_nuevos WHERE wepoint_id=?", [$eds[0]['wepoint_id']])->fetchAll());
            if ($nueva && ($otra = wepoint_coleccion_del_titulo($titulo_id, $sku_t))) {
                throw new Exception('Este título de WePoint' . ($sku_t ? " (SKU $sku_t)" : '') . ' ya es la colección “' . $otra['nombre'] . '”: no se crea otra.');
            }
            $hechos = $creados = $vinculados = 0;
            $sin_publicar = [];
            $salteados = [];
            $usados = [];
            $db = db();
            $db->beginTransaction();
            if ($nueva) {
                // Como el alta de Stock: se crea oculta y se publica cuando estén las fotos
                $slug = $base = slugify($nombre_nueva);
                for ($i = 2; q("SELECT 1 FROM colecciones WHERE slug=?", [$slug])->fetchColumn(); $i++) $slug = "$base-$i";
                q("INSERT INTO colecciones (categoria_id, slug, sku, wepoint_titulo_id, nombre, activa) VALUES (?,?,?,?,?,0)",
                  [(int)($_POST['categoria_id'] ?? 0) ?: null, $slug, $sku_t, $titulo_id, $nombre_nueva]);
                $col = q("SELECT * FROM colecciones WHERE id=?", [(int)$db->lastInsertId()])->fetch();
            } else {
                wepoint_atar_coleccion($col, $titulo_id, $sku_t);   // si el título es de otra colección, no se vincula nada
                $col = q("SELECT * FROM colecciones WHERE id=?", [$col['id']])->fetch();
            }
            foreach ($eds as $n) {
                $numero = nuevo_numero($n, $cols);
                $nombre_ed = ($n['sku'] ?: $n['clave']);
                if (!$numero) { $salteados[] = $nombre_ed . ': sin número de edición'; continue; }
                if (isset($usados[$numero])) { $salteados[] = num($numero) . ' repetido (' . $nombre_ed . ')'; continue; }
                $usados[$numero] = true;
                $precio = (float)$n['precio'] > 0 ? (float)$n['precio'] : $precio_comun;
                try {
                    [, $falta, $existia] = nuevo_vincular($n, $col, $numero, $col['nombre'], $precio, null, $usuario);
                } catch (Exception $e) {
                    $salteados[] = $e->getMessage();
                    continue;
                }
                $hechos++;
                $existia ? $vinculados++ : $creados++;
                if ($otras = array_diff($falta, ['foto'])) $sin_publicar[] = num($numero) . ' (falta ' . implode(', ', $otras) . ')';
            }
            if ($nueva && !$hechos) throw new Exception('No se creó la colección: ninguna edición se pudo vincular (' . implode(' · ', $salteados) . ').');
            $db->commit();
            if ($nueva) flash('ok', 'Colección “' . $col['nombre'] . '” creada (oculta). Activala en Stock cuando tenga las fotos.');
            if ($hechos) {
                $sin_foto = (int)q("SELECT COUNT(*) FROM items WHERE coleccion_id=? AND activo=0 AND (imagen IS NULL OR imagen='')", [$col['id']])->fetchColumn();
                flash('ok', "$hechos edicion" . ($hechos > 1 ? 'es' : '') . ' a ' . $col['nombre'] . ': ' . $creados . ' creada' . ($creados === 1 ? '' : 's') . ', ' . $vinculados . ' vinculada' . ($vinculados === 1 ? '' : 's') . ' a números que ya existían.'
                    . ($sin_foto ? " La colección tiene $sin_foto número" . ($sin_foto > 1 ? 's' : '') . ' sin foto: subilas en Stock → ' . $col['nombre'] . ' para publicarlos.' : ''));
            }
            if ($sin_publicar) flash('error', 'Sin publicar además de la foto: ' . implode(' · ', $sin_publicar) . '.');
            if ($salteados) flash('error', 'Quedaron en la bandeja: ' . implode(' · ', $salteados) . '.');
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    // ── Una edición, con foto ──
    $clave = (string)($_POST['clave'] ?? '');
    $n = q("SELECT * FROM wepoint_nuevos WHERE clave=?", [$clave])->fetch();
    try {
        if (!$n) throw new Exception('Ese producto ya no está en la bandeja.');
        if (!$col) throw new Exception('Elegí la colección.');
        $numero = (int)($_POST['numero'] ?? 0);
        if ($numero <= 0) throw new Exception('Indicá el número.');
        $titulo = trim($_POST['titulo'] ?? '');
        $precio = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['precio'] ?? '0'));
        $foto = subir_imagen($_FILES['foto'] ?? null, 'items', $col['slug'] . '-' . $numero);
        $db = db();
        $db->beginTransaction();
        [$item_id, $falta, $existia] = nuevo_vincular($n, $col, $numero, $titulo, $precio, $foto, $usuario);
        $db->commit();
        if ($existia) flash('ok', 'Vinculado al ' . num($numero) . ' que ya existía en ' . $col['nombre'] . '.');
        // ¿Es lo que pidió alguien en "Me falta"? Se vincula y, si hay stock, se le reserva
        $si_id = (int)($_POST['solicitud_item_id'] ?? 0);
        if ($si_id && q("UPDATE solicitud_items SET item_id=? WHERE id=? AND item_id IS NULL", [$item_id, $si_id])->rowCount()) {
            if (reservas_asignar()) flash('ok', 'Quedó reservado ' . RESERVA_HORAS . ' h para quien lo buscaba, y le avisamos por mail.');
        }
        if ($falta) flash('error', num($numero) . ' de ' . $col['nombre'] . ' quedó cargado pero sin publicar: falta ' . implode(', ', $falta) . '.');
        else flash('ok', num($numero) . ' de ' . $col['nombre'] . ' ya está publicado en la tienda.');
        redirect('admin/coleccion.php?id=' . (int)$col['id']);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        flash('error', $e->getMessage());
        redirect($volver);
    }
}

// ── Búsqueda: cada palabra tiene que estar en el nombre o el SKU (o ser el número de la edición) ──
$where = [];
$params = [];
foreach (array_slice(preg_split('/\s+/', $buscar, -1, PREG_SPLIT_NO_EMPTY), 0, 8) as $pal) {
    $like = '%' . $pal . '%';
    $cond = '(nombre LIKE ? OR sku LIKE ? OR clave = ?';
    array_push($params, $like, $like, $pal);
    if (ctype_digit($pal)) { $cond .= ' OR numero_edicion = ?'; $params[] = (int)$pal; }
    $where[] = $cond . ')';
}
if ($sueltas !== '') { $where[] = 'wepoint_id = ?'; $params[] = $sueltas; }
$where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_ed = (int)q("SELECT COUNT(*) FROM wepoint_nuevos")->fetchColumn();
$titulos = q("SELECT wepoint_id, MIN(nombre) AS nombre, COUNT(*) AS ediciones FROM wepoint_nuevos $where GROUP BY wepoint_id ORDER BY MIN(nombre), wepoint_id", $params)->fetchAll();
$encontradas = array_sum(array_column($titulos, 'ediciones'));
$paginas = max(1, (int)ceil(count($titulos) / NUEVOS_TITULOS_POR_PAGINA));
$pagina = min($pagina, $paginas);
$titulos_pag = array_slice($titulos, ($pagina - 1) * NUEVOS_TITULOS_POR_PAGINA, NUEVOS_TITULOS_POR_PAGINA);
$eds_por_titulo = [];
if ($titulos_pag) {
    $ids = array_column($titulos_pag, 'wepoint_id');
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM wepoint_nuevos " . ($where ? $where . ' AND' : 'WHERE') . " wepoint_id IN ($marcas) ORDER BY numero_edicion IS NULL, numero_edicion, nombre, clave";
    foreach (q($sql, array_merge($params, $ids))->fetchAll() as $e) $eds_por_titulo[$e['wepoint_id']][] = $e;
}

$col_nombre = array_column($cols, 'nombre', 'id');
$categorias = categorias();
// Búsquedas libres abiertas (números que no estaban en el catálogo): alguno puede ser este producto
$libres = q("SELECT si.id, si.descripcion, s.id AS solicitud_id, c.nombre, c.apellido FROM solicitud_items si
             JOIN solicitudes s ON s.id=si.solicitud_id JOIN clientes c ON c.id=s.cliente_id
             WHERE si.item_id IS NULL AND s.estado IN ('pendiente','buscando') ORDER BY s.created_at")->fetchAll();
$ultima = q("SELECT valor FROM ajustes WHERE clave='wepoint_ultima_sync'")->fetchColumn();

// Números ya cargados en una colección (para avisar qué se crea, qué se vincula y qué choca)
function nuevos_items_de(int $col_id): array {
    static $cache = [];
    if (!isset($cache[$col_id])) {
        $cache[$col_id] = [];
        foreach (q("SELECT numero, titulo, wepoint_id, wepoint_variante_id FROM items WHERE coleccion_id=?", [$col_id])->fetchAll() as $it) $cache[$col_id][(int)$it['numero']] = $it;
    }
    return $cache[$col_id];
}

// Colecciones que ya son OTRO título de WePoint con el mismo nombre: casi seguro el título está cargado dos veces en WePoint
function nuevo_titulo_duplicado(array $t, array $cols): array {
    $n = wepoint_normalizar(nuevo_nombre_propuesto($t['nombre']));
    return array_values(array_filter($cols, fn($c) => $c['wepoint_titulo_id'] && (int)$c['wepoint_titulo_id'] !== (int)$t['wepoint_id']
        && wepoint_normalizar(nuevo_nombre_propuesto($c['nombre'])) === $n));
}

function select_colecciones(array $cols, ?int $elegida): string {
    $h = '<select name="coleccion_id" required><option value="">Elegí…</option>';
    foreach ($cols as $c) {
        $otra = $c['wepoint_titulo_id'] && $elegida !== (int)$c['id'];
        $h .= '<option value="' . (int)$c['id'] . '"' . ($elegida === (int)$c['id'] ? ' selected' : '') . ($otra ? ' disabled' : '') . '>'
            . e($c['nombre']) . ($c['sku'] ? ' · SKU ' . e($c['sku']) : '') . ($otra ? ' (ya es otro título de WePoint)' : '') . '</option>';
    }
    return $h . '</select>';
}

admin_header('Por publicar', 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Stock</a></p>
<div class="section-head">
    <h1 style="margin:0">Por publicar</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Nombre, SKU o N° de edición" style="width:280px" autofocus>
        <button class="btn btn-linea btn-chico" type="submit">Buscar</button>
        <?php if ($buscar !== ''): ?><a class="small" href="<?= url('admin/nuevos.php') ?>">Ver todo</a><?php endif; ?>
    </form>
</div>
<div class="aviso" style="margin-bottom:16px;background:var(--teal-50);color:var(--teal-900)">
    Acá aparece <b>todo lo que está en la cuenta de WePoint</b> y todavía no es un número de la web, <b>agrupado por título</b>.
    Elegí la colección una vez y <b>se vinculan todas las ediciones marcadas</b>, cada una a su número de edición; después subís las fotos en la colección.
    O abrí “Cargar una por una” para hacerlo con foto. <b>Si el número ya existe</b> (por ejemplo, de una colección “Próximamente”), se vincula a ese y conserva su foto. <b>Sin foto, título o precio se crea pero no se publica.</b>
    El stock es el disponible para venta: lo recepcionado que el depósito todavía no ubicó cuenta como 0.
    <?php if ($ultima): ?><br><span class="small">Última sincronización con WePoint: <?= e(fecha($ultima)) ?></span><?php endif; ?>
</div>

<?php if ($sueltas !== '' && $titulos): ?>
    <p style="margin:0 0 10px"><b><?= e($titulos[0]['nombre']) ?></b> · una por una, con foto · <a href="<?= url('admin/nuevos.php') ?>">← Volver a la bandeja</a></p>
<?php endif; ?>
<?php if (!$total_ed): ?>
    <div class="card vacio"><h3>No hay productos nuevos de WePoint</h3><p class="muted">Cuando el depósito cargue un producto en WePoint, aparece acá en la próxima sincronización.</p></div>
<?php elseif (!$titulos): ?>
    <div class="card vacio"><h3>No hay nada que coincida con “<?= e($buscar) ?>”</h3><p class="muted">Probá con otra palabra o con el SKU. <a href="<?= url('admin/nuevos.php') ?>">Ver todo</a></p></div>
<?php else: ?>
    <p class="small muted" style="margin:0 0 10px">
        <?= $buscar !== '' ? 'Encontradas ' . $encontradas . ' de ' . $total_ed . ' ediciones' : $total_ed . ' ediciones' ?>
        en <?= count($titulos) ?> título<?= count($titulos) > 1 ? 's' : '' ?><?= $paginas > 1 ? ' · página ' . $pagina . ' de ' . $paginas : '' ?>.
    </p>
<?php endif; ?>

<?php
// Tarjeta de una edición sola, con foto (la de siempre)
function tarjeta_edicion(array $nv, array $cols, array $col_nombre, array $libres, string $buscar, int $pagina): void {
    [$col_sug, $num_sug] = wepoint_sugerir($nv, $cols);
    $existe_sug = $col_sug && $num_sug ? (nuevos_items_de($col_sug)[$num_sug] ?? null) : null; ?>
    <form method="post" enctype="multipart/form-data" class="nuevo-card form">
        <?= csrf_field() ?>
        <input type="hidden" name="clave" value="<?= e($nv['clave']) ?>">
        <input type="hidden" name="q" value="<?= e($buscar) ?>"><input type="hidden" name="pag" value="<?= $pagina ?>"><input type="hidden" name="sueltas" value="<?= e($GLOBALS['sueltas'] ?? '') ?>">
        <label class="foto-mini foto-nuevo" title="Subir foto">
            <span>📷<br><small>Foto</small></span>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" hidden onchange="previewNuevo(this)">
        </label>
        <div class="nuevo-datos">
            <div class="nuevo-wp">
                <b><?= e($nv['nombre'] ?? 'Sin nombre en WePoint') ?><?= $nv['numero_edicion'] ? ' · Edición ' . (int)$nv['numero_edicion'] : '' ?></b> <span class="muted small"><?= $nv['sku'] ? 'SKU ' . e($nv['sku']) : 'sin SKU' ?> · id WePoint <?= e($nv['clave']) ?></span>
                <span class="badge <?= $nv['stock'] > 0 ? 'badge-verde' : 'badge-gris' ?>"><?= (int)$nv['stock'] ?> disponibles</span>
            </div>
            <div class="nuevo-campos">
                <label class="campo">Colección<?= select_colecciones($cols, $col_sug) ?></label>
                <label class="campo campo-num">N°<input type="number" name="numero" min="1" value="<?= $num_sug ?: '' ?>" required></label>
                <label class="campo">Título<input type="text" name="titulo" value="<?= e($nv['nombre'] ?? '') ?>" required></label>
                <label class="campo campo-precio">Precio<input type="text" name="precio" inputmode="decimal" value="<?= $nv['precio'] !== null ? e((float)$nv['precio']) : '' ?>" required></label>
            </div>
            <?php if ($col_sug): ?>
                <p class="small" style="margin:0">
                    <span class="badge badge-amarillo">Sugerido</span> <?= e($col_nombre[$col_sug] ?? '') ?><?= $num_sug ? ' ' . num($num_sug) : ' · falta el número' ?>
                    <?php if ($existe_sug && $existe_sug['wepoint_id'] && wepoint_clave_item($existe_sug) !== $nv['clave']): ?>
                        · <b style="color:var(--rojo, #b42318)">ya está vinculado a otro producto de WePoint (<?= e(wepoint_clave_item($existe_sug)) ?>): revisá el número</b>
                    <?php elseif ($existe_sug): ?>
                        · ya existe “<?= e($existe_sug['titulo']) ?>”: se vincula a ese
                    <?php elseif ($num_sug): ?>
                        · ese número no existe: se crea
                    <?php endif; ?>
                    <span class="muted">— revisalo antes de crear</span>
                </p>
            <?php endif; ?>
            <?php if ($libres): ?>
                <label class="campo">¿Lo estaba buscando alguien? <small>Se le reserva <?= RESERVA_HORAS ?> h y le avisamos</small>
                    <select name="solicitud_item_id">
                        <option value="">No, nadie</option>
                        <?php foreach ($libres as $l): ?><option value="<?= (int)$l['id'] ?>">#<?= (int)$l['solicitud_id'] ?> · <?= e(nombre_cliente($l)) ?> · <?= e($l['descripcion']) ?></option><?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <div class="nuevo-acciones">
                <button class="btn btn-primario btn-chico" type="submit">Crear o vincular número</button>
            </div>
        </div>
    </form>
<?php } ?>

<div class="nuevos">
    <?php foreach ($titulos_pag as $t):
        $eds = $eds_por_titulo[$t['wepoint_id']] ?? [];
        if ($sueltas !== '' || count($eds) < 2) { foreach ($eds as $nv) tarjeta_edicion($nv, $cols, $col_nombre, $libres, $buscar, $pagina); continue; }
        [$col_sug] = wepoint_sugerir($eds[0], $cols);
        $sku_t = nuevo_sku_titulo($eds);
        $col_titulo = wepoint_coleccion_del_titulo((int)$t['wepoint_id'], $sku_t);
        if ($col_titulo) $col_sug = (int)$col_titulo['id'];
        $duplicados = $col_titulo ? [] : nuevo_titulo_duplicado($t, $cols);
        $items_sug = $col_sug ? nuevos_items_de($col_sug) : [];
        $precios = array_filter(array_map(fn($e) => (float)$e['precio'], $eds));
        $precio_comun = $precios ? array_search(max($cnt = array_count_values(array_map('strval', $precios))), $cnt) : '';
        $unidades = array_sum(array_map(fn($e) => (int)$e['stock'], $eds));
        $vistos = []; ?>
        <div class="nuevo-grupo">
            <form method="post" class="nuevo-card form">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="grupo">
                <input type="hidden" name="wepoint_id" value="<?= e($t['wepoint_id']) ?>">
                <input type="hidden" name="q" value="<?= e($buscar) ?>"><input type="hidden" name="pag" value="<?= $pagina ?>">
                <div class="nuevo-datos">
                    <div class="nuevo-wp">
                        <b><?= e($t['nombre'] ?? 'Sin nombre en WePoint') ?></b>
                        <span class="muted small"><?= count($eds) ?> ediciones · título WePoint <?= e($t['wepoint_id']) ?><?= $sku_t ? ' · SKU ' . e($sku_t) : '' ?></span>
                        <span class="badge <?= $unidades > 0 ? 'badge-verde' : 'badge-gris' ?>"><?= $unidades ?> disponibles</span>
                    </div>
                    <?php foreach ($duplicados as $d): ?>
                        <p class="small" style="margin:0;color:var(--rojo, #b42318)"><b>¿Título duplicado en WePoint?</b> “<?= e($d['nombre']) ?>” ya es el título <?= (int)$d['wepoint_titulo_id'] ?><?= $d['sku'] ? ' (SKU ' . e($d['sku']) . ')' : '' ?>, y este es otro título con el mismo nombre<?= $sku_t ? ' (SKU ' . e($sku_t) . ')' : '' ?>. Si es la misma colección, hay que unificarlo en WePoint antes de vincularlo.</p>
                    <?php endforeach; ?>
                    <?php if ($col_titulo): ?>
                    <input type="hidden" name="destino" value="existente"><input type="hidden" name="coleccion_id" value="<?= (int)$col_titulo['id'] ?>">
                    <p style="margin:0"><span class="badge badge-verde">Ya es una colección</span> Este título de WePoint<?= $sku_t ? ' (SKU ' . e($sku_t) . ')' : '' ?> es <b><?= e($col_titulo['nombre']) ?></b>: las ediciones van ahí.</p>
                    <?php else: ?>
                    <div class="nuevo-destino" data-destino>
                        <label class="nuevo-opcion"><input type="radio" name="destino" value="existente" <?= $col_sug ? 'checked' : '' ?>> A una colección que ya existe</label>
                        <div class="nuevo-campos nuevo-campos-grupo" data-para="existente">
                            <label class="campo">Colección<?= select_colecciones($cols, $col_sug) ?></label>
                        </div>
                        <label class="nuevo-opcion"><input type="radio" name="destino" value="nueva" <?= $col_sug ? '' : 'checked' ?>> Crear una colección nueva con estas ediciones</label>
                        <div class="nuevo-campos nuevo-campos-grupo" data-para="nueva">
                            <label class="campo">Nombre en MeFaltaUno <small>para la colección y todos sus números</small><input type="text" name="nombre_nueva" maxlength="160" value="<?= e(nuevo_nombre_propuesto($t['nombre'])) ?>" required></label>
                            <label class="campo">Categoría<select name="categoria_id"><option value="">—</option><?php foreach ($categorias as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['nombre']) ?></option><?php endforeach; ?></select></label>
                            <p class="small muted" style="margin:0;grid-column:1/-1">Queda atada a este título de WePoint<?= $sku_t ? ' con el SKU <b>' . e($sku_t) . '</b>' : '' ?>: no se va a poder crear otra colección para el mismo título.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="nuevo-campos nuevo-campos-grupo">
                        <label class="campo campo-precio">Precio <small>si WePoint no lo trae</small><input type="text" name="precio" inputmode="decimal" value="<?= e($precio_comun) ?>"></label>
                    </div>
                    <?php if ($col_sug && !$col_titulo): ?>
                        <p class="small" style="margin:0"><span class="badge badge-amarillo">Sugerido</span> <?= e($col_nombre[$col_sug] ?? '') ?> <span class="muted">— lo que dice “Qué pasa” es con esta colección; revisalo antes de vincular</span></p>
                    <?php endif; ?>
                    <div class="tabla-wrap" style="max-height:340px;overflow:auto">
                        <table class="tabla tabla-items">
                            <thead><tr><th><input type="checkbox" checked onchange="this.closest('table').querySelectorAll('tbody input[type=checkbox]:not(:disabled)').forEach(c => c.checked = this.checked)" title="Marcar todas"></th><th>N°</th><th>SKU</th><th>Precio WP</th><th>Disp.</th><th>Qué pasa</th></tr></thead>
                            <tbody>
                            <?php foreach ($eds as $nv):
                                $numero = nuevo_numero($nv, $cols);
                                $ex = $numero ? ($items_sug[$numero] ?? null) : null;
                                $bloqueo = !$numero ? 'Sin número de edición: cargala sola' : (isset($vistos[$numero]) ? 'Número repetido en este título: cargala sola' : null);
                                if ($numero) $vistos[$numero] = true;
                                $choca = $ex && $ex['wepoint_id'] && wepoint_clave_item($ex) !== $nv['clave']; ?>
                                <tr>
                                    <td><input type="checkbox" name="claves[]" value="<?= e($nv['clave']) ?>" <?= $bloqueo ? 'disabled' : ($choca ? '' : 'checked') ?>></td>
                                    <td><?= $numero ? num($numero) : '—' ?></td>
                                    <td class="small"><?= $nv['sku'] ? e($nv['sku']) : '<span class="muted">sin SKU</span>' ?></td>
                                    <td class="small"><?= $nv['precio'] !== null ? e(precio((float)$nv['precio'])) : '<span class="muted">—</span>' ?></td>
                                    <td><?= (int)$nv['stock'] ?></td>
                                    <td class="small">
                                        <?php if ($bloqueo): ?><span class="muted"><?= e($bloqueo) ?></span>
                                        <?php elseif (!$col_sug): ?>Se crea sin foto
                                        <?php elseif ($choca): ?><b style="color:var(--rojo, #b42318)">Ya vinculado a otro producto de WePoint (<?= e(wepoint_clave_item($ex)) ?>)</b>
                                        <?php elseif ($ex): ?>Ya existe “<?= e($ex['titulo']) ?>”: se vincula
                                        <?php else: ?>Se crea sin foto<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="nuevo-acciones">
                        <button class="btn btn-primario btn-chico" type="submit">Vincular las ediciones marcadas</button>
                        <span class="small muted">Cada una va al número de su edición y lleva el nombre de la colección de MeFaltaUno (los números que ya existían conservan el suyo). Las que se crean quedan sin publicar hasta que subas la foto en la colección.</span>
                    </div>
                </div>
            </form>
            <p class="nuevo-sueltas small" style="margin:0"><a href="<?= e(url('admin/nuevos.php') . '?' . http_build_query(['sueltas' => $t['wepoint_id']])) ?>">Cargar una por una, con foto →</a></p>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($paginas > 1): ?>
    <nav class="nuevos-paginas">
        <?php foreach (array_unique(array_merge([1], range(max(1, $pagina - 3), min($paginas, $pagina + 3)), [$paginas])) as $i => $p):
            if ($i && $p > $prev + 1): ?><span class="muted">…</span><?php endif; $prev = $p;
            $href = url('admin/nuevos.php') . '?' . http_build_query(array_filter(['q' => $buscar, 'pag' => $p > 1 ? $p : null])); ?>
            <?php if ($p === $pagina): ?><b><?= $p ?></b><?php else: ?><a href="<?= e($href) ?>"><?= $p ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<script>
document.querySelectorAll('[data-destino]').forEach(box => {
    const aplicar = () => {
        const elegido = box.querySelector('input[name=destino]:checked')?.value;
        box.querySelectorAll('[data-para]').forEach(b => {
            const activo = b.dataset.para === elegido;
            b.hidden = !activo;
            b.querySelectorAll('input, select').forEach(i => i.disabled = !activo);
        });
    };
    box.addEventListener('change', aplicar);
    aplicar();
});
function previewNuevo(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    const lbl = input.closest('label');
    let img = lbl.querySelector('img');
    if (!img) { img = document.createElement('img'); lbl.prepend(img); lbl.querySelector('span')?.remove(); }
    img.src = URL.createObjectURL(f);
}
</script>

<?php admin_footer(); ?>
