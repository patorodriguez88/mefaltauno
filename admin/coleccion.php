<?php
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';
require_once dirname(__DIR__) . '/inc/vistas.php';

$id = (int)($_GET['id'] ?? 0);
$col = q("SELECT * FROM colecciones WHERE id=?", [$id])->fetch();
if (!$col) redirect('admin/colecciones.php');
$con_wepoint = wepoint_listo();

function monto($v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    // "19.390,50" o "19390.5" → 19390.5
    if (strpos($v, ',') !== false) $v = str_replace(['.', ','], ['', '.'], $v);
    return (float)$v;
}

// $_FILES['foto_item'] viene "dado vuelta": lo paso a [id => archivo]
function archivos_por_id(string $campo): array {
    $out = [];
    foreach ($_FILES[$campo]['name'] ?? [] as $k => $_) {
        $out[$k] = ['name' => $_FILES[$campo]['name'][$k], 'tmp_name' => $_FILES[$campo]['tmp_name'][$k],
                    'error' => $_FILES[$campo]['error'][$k], 'size' => $_FILES[$campo]['size'][$k]];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $db = db();
    $db->beginTransaction();
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') throw new Exception('El nombre no puede quedar vacío.');
        $portada = subir_imagen($_FILES['foto_portada'] ?? null, 'colecciones', $col['slug']) ?? $col['imagen'];
        $rec_col = trim((string)($_POST['recargo'] ?? '')) === '' ? null : max(0, monto($_POST['recargo']));
        $tot = (int)($_POST['total_entregas'] ?? 0) ?: null;
        q("UPDATE colecciones SET total_entregas=? WHERE id=?", [$tot, $id]);
        $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['salida_inicio'] ?? '') ? $_POST['salida_inicio'] : null;
        $per = isset(PERIODICIDADES[$_POST['periodicidad'] ?? '']) ? $_POST['periodicidad'] : null;
        // Si cambia la periodicidad o la fecha del N° 1, los números que ya salieron se fijan con la fecha que
        // tenían: lo nuevo vale de ahí en adelante (hay colecciones que arrancan quincenales y pasan a semanales)
        $fijar_salidas = ($per !== $col['periodicidad'] || $inicio !== $col['salida_inicio']) ? salidas_coleccion($col) : [];
        q("UPDATE colecciones SET en_calle=?, salida_inicio=?, periodicidad=? WHERE id=?", [!empty($_POST['en_calle']) ? 1 : 0, $inicio, $per, $id]);
        q("UPDATE colecciones SET nombre=?, categoria_id=?, bajada=?, descripcion=?, imagen=?, activa=?, destacada=?, proximamente=?, lanzamiento=?, recargo=?, orden=? WHERE id=?", [
            $nombre, (int)($_POST['categoria_id'] ?? 0) ?: null, trim($_POST['bajada'] ?? '') ?: null, trim($_POST['descripcion'] ?? '') ?: null,
            $portada, !empty($_POST['activa']) ? 1 : 0, !empty($_POST['destacada']) ? 1 : 0,
            !empty($_POST['proximamente']) ? 1 : 0, mb_substr(trim($_POST['lanzamiento'] ?? ''), 0, 60) ?: null, $rec_col, (int)($_POST['orden'] ?? 0), $id,
        ]);

        // Atajo: mismo máximo por cliente para todos los números
        if (trim((string)($_POST['max_todos'] ?? '')) !== '') {
            $m = max(0, (int)$_POST['max_todos']);
            foreach ($_POST['items'] ?? [] as $k => $_) $_POST['items'][$k]['max_por_cliente'] = $m ?: '';
        }
        $fotos = archivos_por_id('foto_item');
        $sin_publicar = [];
        foreach ((array)($_POST['items'] ?? []) as $iid => $it) {
            $titulo = trim($it['titulo'] ?? '');
            if ($titulo === '') continue;
            $actual = q("SELECT imagen, foto_origen, stock FROM items WHERE id=? AND coleccion_id=?", [(int)$iid, $id])->fetch();
            if (!$actual) continue;
            $subida = subir_imagen($fotos[$iid] ?? null, 'items', $col['slug'] . '-' . (int)$it['numero']);
            $foto = $subida ?? $actual['imagen'];
            if ($subida) q("UPDATE items SET foto_origen='manual' WHERE id=?", [(int)$iid]);   // subida a mano: no la reemplaza ningún importador
            $limite = trim((string)($it['limite'] ?? '')) === '' ? null : max(0, (int)$it['limite']);
            $max_cli = trim((string)($it['max_por_cliente'] ?? '')) === '' ? null : max(1, (int)$it['max_por_cliente']);
            // Con WePoint el stock no se toca desde acá; sin WePoint (modo manual) sí
            $stock = $con_wepoint ? (int)$actual['stock'] : max(0, (int)($it['stock'] ?? 0));
            if ($stock > 0 && (int)$actual['stock'] <= 0) q("UPDATE items SET llegada_at=NOW() WHERE id=?", [(int)$iid]);
            $precio = monto($it['precio']) ?? 0;
            $activo = !empty($it['activo']) ? 1 : 0;
            if ($activo && ($falta = item_falta_para_publicar($foto, $titulo, $precio))) {
                $activo = 0;
                $sin_publicar[] = num((int)$it['numero']) . ' (falta ' . implode(', ', $falta) . ')';
            }
            $salida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $it['salida'] ?? '') ? $it['salida'] : null;
            q("UPDATE items SET numero=?, titulo=?, imagen=?, precio=?, precio_promo=?, stock=?, congelado=?, limite=?, max_por_cliente=?, sku=?, activo=?, salida=? WHERE id=? AND coleccion_id=?", [
                (int)$it['numero'], $titulo, $foto, $precio, monto($it['precio_promo']),
                $stock, !empty($it['congelado']) ? 1 : 0, $limite, $max_cli, strtoupper(trim($it['sku'] ?? '')) ?: null,
                $activo, $salida, (int)$iid, $id,
            ]);
        }

        foreach ($fijar_salidas as $num => $sf) {
            if (!$sf['manual'] && $sf['fecha'] <= date('Y-m-d')) {
                q("UPDATE items SET salida=? WHERE coleccion_id=? AND numero=? AND salida IS NULL", [$sf['fecha'], $id, $num]);
            }
        }

        $nuevo = $_POST['nuevo'] ?? [];
        if (trim($nuevo['titulo'] ?? '') !== '') {
            $num = (int)($nuevo['numero'] ?? 0) ?: ((int)q("SELECT COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=?", [$id])->fetchColumn() + 1);
            $foto = subir_imagen($_FILES['foto_nuevo'] ?? null, 'items', $col['slug'] . '-' . $num);
            $precio = monto($nuevo['precio'] ?? '') ?? 0;
            $activo = item_falta_para_publicar($foto, $nuevo['titulo'], $precio) ? 0 : 1;
            if (!$activo) $sin_publicar[] = num($num) . ' (queda sin publicar hasta tener foto y precio)';
            q("INSERT INTO items (coleccion_id, numero, titulo, imagen, foto_origen, precio, stock, sku, activo) VALUES (?,?,?,?,?,?,0,?,?)",
              [$id, $num, trim($nuevo['titulo']), $foto, $foto ? 'manual' : null, $precio, strtoupper(trim($nuevo['sku'] ?? '')) ?: null, $activo]);
        }
        $db->commit();
        flash('ok', 'Cambios guardados.');
        if ($n = reservas_asignar()) flash('ok', "$n números quedaron reservados para quienes los buscaban.");
        if ($sin_publicar) flash('error', 'No se publicaron: ' . implode(' · ', $sin_publicar) . '. Para publicar un número hace falta foto, título y precio.');
    } catch (PDOException $e) {
        $db->rollBack();
        flash('error', $e->getCode() == 23000 ? 'Hay dos números repetidos en la colección.' : 'No se pudo guardar: ' . $e->getMessage());
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('admin/coleccion.php?id=' . $id);
}

$items = q("SELECT i.*, (SELECT COUNT(*) FROM item_fotos f WHERE f.item_id=i.id) AS fotos_extra, (SELECT COUNT(*) FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id WHERE si.item_id=i.id AND s.estado IN ('pendiente','buscando')) AS pedidos
            FROM items i WHERE coleccion_id=? ORDER BY numero", [$id])->fetchAll();

$salidas = salidas_coleccion($col);

// Composición del stock de cada número, para el panel lateral: reservas activas (de quién y por qué) y
// lo vendido por encargue que todavía no se le pidió a Brihet
$reservas_item = $encargue_pend = [];
foreach (q("SELECT r.item_id, r.stock, r.origen, r.abono_id, r.vence_at, c.nombre, c.apellido FROM reservas r JOIN clientes c ON c.id=r.cliente_id
            JOIN items i ON i.id=r.item_id WHERE i.coleccion_id=? AND r.estado='activa' AND r.vence_at > NOW() ORDER BY r.vence_at", [$id]) as $r) {
    $reservas_item[(int)$r['item_id']][] = ['quien' => nombre_cliente($r), 'por' => $r['abono_id'] ? 'abono' : ($r['origen'] === 'manual' ? 'a mano' : 'búsqueda'),
                                            'stock' => $r['stock'], 'vence' => fecha($r['vence_at'])];
}
foreach (q("SELECT pi.item_id, SUM(pi.encargue) AS n FROM pedido_items pi JOIN pedidos p ON p.id=pi.pedido_id JOIN items i ON i.id=pi.item_id
            WHERE i.coleccion_id=? AND pi.encargue > 0 AND pi.encargue_estado='pendiente' AND p.estado<>'cancelado' GROUP BY pi.item_id", [$id]) as $r) {
    $encargue_pend[(int)$r['item_id']] = (int)$r['n'];
}
$sal = salidas_resumen($col, $salidas);

admin_header($col['nombre'], 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Colecciones</a> · <a href="<?= url('coleccion.php?c=' . urlencode($col['slug'])) ?>" target="_blank">Ver en la tienda ↗</a> · <a href="<?= url('admin/salvat.php?coleccion=' . (int)$col['id']) ?>">📷 Mejorar fotos desde Salvat</a></p>

<form method="post" class="form" enctype="multipart/form-data" id="form-coleccion">
    <?= csrf_field() ?>
    <div class="layout-2 layout-col">
        <div class="card form">
            <h3>Datos de la colección</h3>
            <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($col['nombre']) ?>" required></label>
            <label class="campo">Bajada <small>(una línea, para buscadores)</small><input type="text" name="bajada" value="<?= e($col['bajada']) ?>"></label>
            <label class="campo campo-desc">Descripción<textarea name="descripcion" rows="6"><?= e($col['descripcion']) ?></textarea></label>
        </div>
        <aside class="card form">
            <div class="foto-portada">
                <?php if ($col['imagen']): ?><img src="<?= e(img($col['imagen'])) ?>" alt=""><?php else: ?><div class="sin-foto">Sin portada</div><?php endif; ?>
                <label class="btn btn-linea btn-chico">📷 Cambiar portada<input type="file" name="foto_portada" accept="image/jpeg,image/png,image/webp" hidden onchange="previewFoto(this)"></label>
            </div>
            <label class="campo">Categoría
                <select name="categoria_id"><option value="">—</option>
                    <?php foreach (categorias() as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= $cat['id'] == $col['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="campo">Orden<input type="number" name="orden" value="<?= (int)$col['orden'] ?>"></label>
            <label class="campo">Total de números de la colección <small>(la colección completa, no los cargados acá<?php if (!$col['total_entregas'] && ($t = coleccion_total($col))): ?>; la descripción dice <?= $t ?><?php endif; ?>)</small><input type="number" name="total_entregas" min="1" value="<?= $col['total_entregas'] ? (int)$col['total_entregas'] : '' ?>" placeholder="<?= e((string)(coleccion_total($col) ?? 'Ej: 120')) ?>"></label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $col['activa'] ? 'checked' : '' ?>> Publicada en la tienda</label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="destacada" value="1" <?= $col['destacada'] ? 'checked' : '' ?>> Destacada en el inicio</label>
            <label class="tengo-toggle" style="color:var(--ink)" title="Se ve en la tienda y la pueden seguir y suscribirse, pero todavía no se vende"><input type="checkbox" name="proximamente" value="1" <?= $col['proximamente'] ? 'checked' : '' ?>> Próximamente <small class="muted">(se ve, no se vende)</small></label>
            <label class="campo">Lanzamiento <small>(opcional, ej: “Noviembre 2026”)</small><input type="text" name="lanzamiento" maxlength="60" value="<?= e($col['lanzamiento']) ?>"></label>
            <fieldset class="salidas-box">
                <legend>🗞️ Salida en kioscos</legend>
                <label class="tengo-toggle" style="color:var(--ink)" title="Sale en kioscos ahora, con periodicidad. Destildada: ya salió toda, se consigue pero no sale nada nuevo."><input type="checkbox" name="en_calle" value="1" <?= $col['en_calle'] ? 'checked' : '' ?>> En la calle <small class="muted">(sale en kioscos ahora)</small></label>
                <label class="campo">Sale el N° 1<input type="date" name="salida_inicio" value="<?= e($col['salida_inicio']) ?>"></label>
                <label class="campo">Periodicidad
                        <select name="periodicidad"><option value="">—</option>
                            <?php foreach (PERIODICIDADES as $k => [$lbl]): ?><option value="<?= $k ?>" <?= $col['periodicidad'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select></label>
                <?php $abonados = abonos_de_coleccion($id); if ($abonados): $ab_act = count(array_filter($abonados, fn($a) => $a['estado'] === 'activo')); $ab_pau = count($abonados) - $ab_act; ?>
                    <details class="abonados">
                        <summary class="small"><b><?= $ab_act ?> abonado<?= $ab_act == 1 ? '' : 's' ?></b><?= $ab_pau ? " · $ab_pau pausado" . ($ab_pau == 1 ? '' : 's') : '' ?> · <u>ver quiénes</u></summary>
                        <p class="small muted" style="margin:6px 0">A cada número nuevo que llega al depósito se le guarda una unidad a cada abonado activo, por orden de llegada.</p>
                        <ul>
                            <?php foreach ($abonados as $a): ?>
                                <li><b><?= e(nombre_cliente($a)) ?></b> <span class="muted"><?= e($a['email']) ?></span><br>
                                    <span class="small"><?= $a['estado'] === 'activo' ? '<span class="badge badge-verde">Activo</span>' : '<span class="badge badge-gris">Pausado</span>' ?>
                                    desde el <?= num((int)$a['desde_numero']) ?> · se abonó el <?= e(fecha($a['created_at'], false)) ?><?= $a['excluidos'] ? ' · sacó ' . (int)$a['excluidos'] . ' número' . ($a['excluidos'] == 1 ? '' : 's') : '' ?><?= $a['reservas'] ? ' · ' . (int)$a['reservas'] . ' reservado' . ($a['reservas'] == 1 ? '' : 's') . ' ahora' : '' ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <?php if ($salidas): ?>
                    <p class="small" style="margin:0">
                        <?= $sal['va'] ? 'Va por el <b>' . num($sal['va']) . '</b>' : 'Todavía no salió el N° 1' ?><?= $sal['proximo'] ? ' · el <b>' . num($sal['proximo']) . '</b> sale aprox. el <b>' . fecha_dia($sal['prox_fecha']) . '</b>' : '' ?><?= $sal['fin'] && $sal['proximo'] ? ' · el último (' . num($sal['fin']) . ') aprox. el ' . fecha_dia($sal['fin_fecha']) : '' ?>.</p>
                    <?php if ($sal['terminada'] && $col['en_calle']): ?><p class="small" style="margin:0;color:var(--amber)">Según las fechas ya salió el último número: ¿la destildás de “En la calle”?</p><?php endif; ?>
                <?php else: ?>
                    <p class="muted small" style="margin:0">Con la fecha del N° 1 y la periodicidad se calcula cuándo sale cada número. Si uno sale distinto, corregile la fecha en la tabla y los siguientes se corren solos.</p>
                <?php endif; ?>
            </fieldset>
            <label class="campo">Recargo interior por unidad <small>(vacío = el general, <?= precio(recargo_general()) ?>)</small><input type="text" name="recargo" inputmode="decimal" value="<?= $col['recargo'] !== null ? e((float)$col['recargo']) : '' ?>" placeholder="<?= e((string)recargo_general()) ?>"></label>
            <?php $maxes = array_unique(array_map(fn($i) => $i['max_por_cliente'] === null ? 'sin máximo' : (string)(int)$i['max_por_cliente'], $items)); ?>
            <label class="campo">Poner el mismo máximo por cliente en todos los números
                <small>(hoy: <?= $items ? (count($maxes) === 1 ? e(reset($maxes)) . ' en todos' : 'distinto según el número') : '—' ?>. Escribí un número y guardá para cambiarlo en todos; 0 = sin máximo; vacío = no tocar)</small>
                <input type="number" name="max_todos" min="0" placeholder="Ej: 2"></label>
            <button class="btn btn-primario" type="submit">Guardar todo</button>
        </aside>
    </div>

    <h3 style="margin-top:12px">Números</h3>
    <div class="aviso" style="margin:-4px 0 4px;background:var(--teal-50);color:var(--teal-900)">
        <?php if ($con_wepoint): ?>
            <b>El stock lo informa WePoint</b> y no se puede sumar desde acá. Podés <b>congelar</b> un número (deja de venderse) o ponerle un <b>tope</b> (vender como máximo esa cantidad). Cada número se vincula a WePoint por su <b>SKU</b>. En <b>Stock</b>: <span class="badge badge-verde">verde</span> se vende ya (depósito, entrega en 24 h) y <span class="badge badge-azul">azul</span> por encargue al editor (Brihet); tocalos para ver de dónde sale.
        <?php else: ?>
            <b>WePoint todavía no está conectado:</b> mientras tanto el stock se carga a mano. Cuando se conecte, el stock pasa a venir de WePoint y acá solo vas a poder <b>congelar</b> o poner un <b>tope</b>. Cargá el <b>SKU</b> de cada número para vincularlo.
        <?php endif; ?>
    </div>
    <div class="tabla-wrap">
        <table class="tabla tabla-items">
            <tr><th>Foto</th><th>N°</th><th>Título</th><th>Precio / promo</th><th>Stock</th><th title="Tope: cuántas más se pueden vender (baja con cada venta). Por cliente: cuántas puede comprar cada uno">Límites de venta</th><th>Estado</th><th title="Fecha de salida en kioscos. Vacía = calculada con la periodicidad">Salida</th><th>SKU</th></tr>
            <?php foreach ($items as $it): $n = 'items[' . (int)$it['id'] . ']'; $disp = disponible($it); ?>
                <tr class="<?= $it['congelado'] ? 'fila-congelada' : '' ?><?= !$it['activo'] ? ' fila-oculta' : '' ?>">
                    <td>
                        <label class="foto-mini" title="Cambiar foto">
                            <?php if ($it['imagen']): ?><img src="<?= e(img($it['imagen'])) ?>" alt=""><?php else: ?><span>📷</span><?php endif; ?>
                            <input type="file" name="foto_item[<?= (int)$it['id'] ?>]" accept="image/jpeg,image/png,image/webp" hidden onchange="previewFoto(this)">
                        </label>
                        <a class="mas-fotos" href="<?= url('admin/numero.php?id=' . (int)$it['id']) ?>" title="Más fotos y video">+ fotos<?= $it['fotos_extra'] ? ' (' . (1 + (int)$it['fotos_extra']) . ')' : '' ?><?= $it['video_url'] ? ' ▶' : '' ?></a>
                    </td>
                    <td><input type="number" name="<?= $n ?>[numero]" value="<?= (int)$it['numero'] ?>" class="in-num"></td>
                    <td class="td-titulo">
                        <input type="text" name="<?= $n ?>[titulo]" value="<?= e($it['titulo']) ?>">
                        <?php if ($it['pedidos']): ?><div class="small" style="margin-top:4px"><span class="badge badge-amarillo"><?= (int)$it['pedidos'] ?> lo piden</span></div><?php endif; ?>
                    </td>
                    <td>
                        <div class="precio-par">
                            <input type="text" name="<?= $n ?>[precio]" value="<?= e((float)$it['precio']) ?>" inputmode="decimal" title="Precio">
                            <input type="text" name="<?= $n ?>[precio_promo]" value="<?= $it['precio_promo'] !== null ? e((float)$it['precio_promo']) : '' ?>" inputmode="decimal" placeholder="Promo" title="Precio promo (vacío = sin promo)" class="in-promo">
                        </div>
                    </td>
                    <td class="td-stock">
                        <?php
                        $res = array_sum(reservas_mapa()[(int)$it['id']] ?? []);
                        $detalle = [
                            'titulo' => num((int)$it['numero']) . ' · ' . $it['titulo'],
                            'deposito' => (int)$it['stock'], 'sync' => $it['stock_sync_at'] ? fecha($it['stock_sync_at']) : null,
                            'reservas' => $reservas_item[(int)$it['id']] ?? [], 'reservado' => $res,
                            'tope' => $it['limite'] !== null ? (int)$it['limite'] : null, 'congelado' => (bool)$it['congelado'],
                            'max_cliente' => $it['max_por_cliente'] !== null ? (int)$it['max_por_cliente'] : null,
                            'vende' => $disp, 'brihet' => $it['brihet_stock'] !== null ? (int)$it['brihet_stock'] : null,
                            'brihet_at' => $it['brihet_stock_at'] ? fecha($it['brihet_stock_at']) : null,
                            'encargue_pend' => $encargue_pend[(int)$it['id']] ?? 0, 'encargue' => disponible_encargue($it), 'total' => disponible_total($it),
                        ];
                        ?>
                        <?php if (!$con_wepoint): ?><label class="tope-campo">En depósito<input type="number" name="<?= $n ?>[stock]" value="<?= (int)$it['stock'] ?>" min="0" class="in-num"></label><?php endif; ?>
                        <button type="button" class="stock-ver stock-par" data-detalle="<?= e(json_encode($detalle, JSON_UNESCAPED_UNICODE)) ?>" title="Verde: se vende ya (depósito, 24 h) · Azul: por encargue al editor (Brihet). Tocá para ver de dónde sale.">
                            <span class="badge <?= $disp > 0 ? 'badge-verde' : 'badge-gris' ?>"><?= $disp ?></span>
                            <?php if ($it['brihet_stock'] !== null): ?><span class="badge badge-azul"><?= $detalle['encargue'] ?></span><?php endif; ?>
                        </button>
                    </td>
                    <td class="td-topes">
                        <label class="tope-campo" title="Cuántas unidades más se pueden vender de este número: baja con cada venta. Vacío = sin tope (manda el stock)">Tope<input type="number" name="<?= $n ?>[limite]" value="<?= $it['limite'] !== null ? (int)$it['limite'] : '' ?>" min="0" placeholder="sin tope" class="in-num"></label>
                        <label class="tope-campo" title="Cuántas puede comprar cada cliente, sumando todos sus pedidos. Vacío = sin máximo">Por cliente<input type="number" name="<?= $n ?>[max_por_cliente]" value="<?= $it['max_por_cliente'] !== null ? (int)$it['max_por_cliente'] : '' ?>" min="1" placeholder="sin máx." class="in-num"></label>
                    </td>
                    <td class="td-estado">
                        <label title="<?= $it['imagen'] ? 'Visible en la tienda' : 'Subí una foto para poder publicarlo' ?>"><input type="checkbox" name="<?= $n ?>[activo]" value="1" <?= $it['activo'] ? 'checked' : '' ?> <?= $it['imagen'] ? '' : 'disabled data-sin-foto' ?>> Publicado</label>
                        <?php if (!$it['imagen']): ?><div class="small" style="color:var(--amber)">Falta foto</div><?php endif; ?>
                        <label title="Congelado: no se vende aunque haya stock"><input type="checkbox" name="<?= $n ?>[congelado]" value="1" <?= $it['congelado'] ? 'checked' : '' ?>> Congelado</label>
                    </td>
                    <td class="td-salida"><?php $sf = $salidas[(int)$it['numero']] ?? null; ?>
                        <?php if ($sf && !$sf['manual']): ?>
                            <?php // Estimada: se ve en gris adentro pero no se manda (sin name) hasta que la cambien; así sigue el cálculo ?>
                            <input type="date" class="salida-aprox" data-name="<?= $n ?>[salida]" value="<?= e($sf['fecha']) ?>" title="Fecha estimada con la periodicidad. Cambiala si este número sale distinto.">
                        <?php else: ?>
                            <input type="date" name="<?= $n ?>[salida]" value="<?= e($it['salida']) ?>" title="<?= $it['salida'] ? 'Fecha fija. Borrala para volver a la estimada.' : 'Sin fecha: cargá la del N° 1 y la periodicidad para estimarla' ?>">
                        <?php endif; ?>
                    </td>
                    <td><input type="text" name="<?= $n ?>[sku]" value="<?= e($it['sku']) ?>" class="in-sku"><?= $it['wepoint_id'] ? '<div class="small" style="color:var(--green)">✓ WePoint</div>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:var(--teal-50)">
                <td><label class="foto-mini" title="Foto"><span>📷</span><input type="file" name="foto_nuevo" accept="image/jpeg,image/png,image/webp" hidden onchange="previewFoto(this)"></label></td>
                <td><input type="number" name="nuevo[numero]" placeholder="auto" class="in-num"></td>
                <td class="td-titulo"><input type="text" name="nuevo[titulo]" placeholder="+ Agregar número…"></td>
                <td><div class="precio-par"><input type="text" name="nuevo[precio]" inputmode="decimal" placeholder="Precio"></div></td>
                <td colspan="4" class="muted small">El stock lo va a traer WePoint por el SKU.</td>
                <td><input type="text" name="nuevo[sku]" class="in-sku" placeholder="SKU"></td>
            </tr>
        </table>
    </div>
    <div><button class="btn btn-primario" type="submit">Guardar todo</button></div>
    <div class="barra-guardar" id="barra-guardar" hidden>
        <span>Tenés cambios sin guardar</span>
        <button class="btn btn-primario" type="submit">Guardar todo</button>
    </div>
</form>

<dialog class="cajon" id="cajon-stock" aria-labelledby="cajon-titulo">
    <div class="cajon-cabeza">
        <div><small class="cajon-col"><?= e($col['nombre']) ?></small><h3 id="cajon-titulo"></h3><small>Composición del stock</small></div>
        <button type="button" class="modal-cerrar" aria-label="Cerrar" onclick="this.closest('dialog').close()">×</button>
    </div>
    <div class="cajon-cuerpo" id="cajon-cuerpo"></div>
</dialog>

<script>
// Stock de un número: al tocar "Se vende" se abre a la derecha de dónde sale cada unidad
(() => {
    const cajon = document.getElementById('cajon-stock'), cuerpo = document.getElementById('cajon-cuerpo');
    const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fila = (txt, n, clase = '') => `<div class="cajon-fila ${clase}"><span>${txt}</span><b>${n}</b></div>`;
    document.addEventListener('click', ev => {
        const b = ev.target.closest('.stock-ver');
        if (!b) return;
        const d = JSON.parse(b.dataset.detalle);
        document.getElementById('cajon-titulo').textContent = d.titulo;
        const resFis = d.reservas.filter(r => r.stock !== 'encargue'), resEnc = d.reservas.filter(r => r.stock === 'encargue');
        const lista = rs => rs.length ? '<ul class="cajon-lista">' + rs.map(r => `<li><b>${esc(r.quien)}</b> · por ${esc(r.por)}<br><small>hasta el ${esc(r.vence)}</small></li>`).join('') + '</ul>' : '';
        cuerpo.innerHTML = `
            <div class="cajon-resumen">
                <div class="verde"><small>Disponible WePoint · ya</small><b>${d.vende}</b></div>
                ${d.brihet !== null ? `<div class="azul"><small>Brihet · por encargue</small><b>${d.encargue}</b></div>` : ''}
            </div>
            <section><h4>Depósito · entrega en 24 h</h4>
                ${fila('En depósito (WePoint)', d.deposito)}
                <p class="cajon-nota">${d.sync ? 'Actualizado ' + esc(d.sync) : 'WePoint todavía no lo informó'}</p>
                ${fila('− Reservado para clientes', d.reservado, d.reservado ? 'ambar' : '')}${lista(resFis)}
                ${d.tope !== null ? fila('Tope (se vende como máximo)', d.tope) : ''}
                ${d.congelado ? fila('Congelado', 'no se vende', 'rojo') : ''}
                ${fila('= Se vende con entrega en 24 h', d.vende, 'total')}
            </section>
            <section><h4>Brihet · por encargue</h4>
                ${d.brihet === null ? '<p class="cajon-nota">Brihet no informó stock de este número.</p>' : `
                ${fila('Stock en Brihet', d.brihet)}
                <p class="cajon-nota">${d.brihet_at ? 'Informado el ' + esc(d.brihet_at) : ''}</p>
                ${d.encargue_pend ? fila('− Vendido por encargue, falta pedírselo', d.encargue_pend, 'ambar') : ''}
                ${resEnc.length ? fila('− Reservado por encargue', resEnc.length, 'ambar') + lista(resEnc) : ''}
                ${fila('= Se vende por encargue', d.encargue, 'total')}`}
            </section>
            <section class="cajon-final">
                ${fila('Disponible para vender en total', d.total, 'total grande')}
                ${d.max_cliente !== null ? `<p class="cajon-nota">Cada cliente puede comprar como máximo ${d.max_cliente}.</p>` : ''}
            </section>`;
        cajon.showModal();
    });
    cajon.addEventListener('click', ev => { if (ev.target === cajon) cajon.close(); });   // tocar afuera cierra
})();

// Fechas de salida: las estimadas (gris) se recalculan en pantalla apenas cambia una fecha fija, la del N° 1
// o la periodicidad, con la misma cuenta que el servidor (inc/salidas.php). Una estimada que se edita pasa a fija.
(() => {
    const form = document.getElementById('form-coleccion');
    const inicio = form.querySelector('[name="salida_inicio"]'), per = form.querySelector('[name="periodicidad"]');
    const PASO = { semanal: 7, quincenal: 14, mensual: 0 };
    const hoy = new Date().toISOString().slice(0, 10);
    const ini0 = inicio.value, per0 = per.value;
    // Lo que ya salió con la cuenta de antes queda fijo al cambiar la periodicidad (igual que al guardar)
    const original = new Map();
    const filas = () => [...form.querySelectorAll('.tabla-items tr')].map(tr => ({
        num: +(tr.querySelector('input[name$="[numero]"]') || {}).value, input: tr.querySelector('.td-salida input'),
    })).filter(f => f.num && f.input).sort((a, b) => a.num - b.num);
    filas().forEach(f => { original.set(f.input, f.input.value); if (f.input.classList.contains('salida-aprox')) f.input.dataset.calc = f.input.value; });
    const sumar = (fecha, pasos, p) => {
        const [y, m, d] = fecha.split('-').map(Number), t = new Date(Date.UTC(y, m - 1, d));
        if (PASO[p]) t.setUTCDate(t.getUTCDate() + pasos * PASO[p]); else t.setUTCMonth(t.getUTCMonth() + pasos);
        return t.toISOString().slice(0, 10);
    };
    const recalcular = () => {
        const cambioRitmo = inicio.value !== ini0 || per.value !== per0;
        let base = inicio.value ? [1, inicio.value] : null;
        filas().forEach(({ num, input }) => {
            const fija = !input.classList.contains('salida-aprox') && input.value;
            const yaSalio = input.classList.contains('salida-aprox') && cambioRitmo && original.get(input) && original.get(input) <= hoy;
            if (fija) { base = [num, input.value]; return; }
            if (yaSalio) { input.value = original.get(input); base = [num, input.value]; return; }
            if (!input.classList.contains('salida-aprox')) return;   // vacía sin estimar: la deja como está
            input.value = input.dataset.calc = base && (num === base[0] || per.value) ? sumar(base[1], num - base[0], per.value) : '';
        });
    };
    form.addEventListener('change', ev => {
        const inp = ev.target;
        if (inp.matches('.td-salida input')) {
            if (!inp.value) {
                // Borró una fecha fija: vuelve a la estimada. Si estaba guardada, al guardar se borra (data-vaciar)
                if (inp.dataset.name) inp.removeAttribute('name'); else inp.dataset.vaciar = '1';
                inp.classList.add('salida-aprox');
            } else if (inp.classList.contains('salida-aprox') && inp.value !== inp.dataset.calc) {   // la escribió el operador
                if (inp.dataset.name) inp.name = inp.dataset.name;
                delete inp.dataset.vaciar;
                inp.classList.remove('salida-aprox');
                inp.title = 'Fecha fija. Borrala para volver a la estimada.';
            }
        }
        if (inp.matches('.td-salida input, [name="salida_inicio"], [name="periodicidad"]')) recalcular();
    });

    // Barra para guardar, siempre a mano cuando hay cambios; Cmd/Ctrl+S guarda; aviso al salir sin guardar
    const barra = document.getElementById('barra-guardar');
    let cambios = false, enviando = false;
    const marcar = () => { cambios = true; barra.hidden = false; };
    form.addEventListener('input', marcar);
    form.addEventListener('change', marcar);
    form.addEventListener('submit', () => {
        enviando = true;
        form.querySelectorAll('[data-vaciar]').forEach(i => { i.value = ''; });   // la fija borrada se guarda vacía
    });
    document.addEventListener('keydown', ev => {
        if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); form.requestSubmit(); }
    });
    window.addEventListener('beforeunload', ev => { if (cambios && !enviando) { ev.preventDefault(); ev.returnValue = ''; } });
})();

// Vista previa de la foto elegida antes de guardar
function previewFoto(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    // Portada: va en el recuadro de la portada (reemplaza "Sin portada"), no dentro del botón. Números: en su miniatura.
    const portada = input.closest('.foto-portada');
    const cont = portada || input.closest('label');
    let img = portada ? portada.querySelector(':scope > img') : cont.querySelector('img');
    if (!img) {
        img = document.createElement('img');
        const vacio = portada ? portada.querySelector('.sin-foto') : cont.querySelector('span');
        vacio ? vacio.replaceWith(img) : cont.prepend(img);
    }
    img.src = URL.createObjectURL(f);
    // Con foto ya se puede publicar
    const pub = input.closest('tr')?.querySelector('input[data-sin-foto]');
    if (pub) { pub.disabled = false; pub.checked = true; pub.closest('label').title = 'Visible en la tienda'; pub.closest('td').querySelector('.small')?.remove(); }
    toast('Foto lista: se guarda al tocar “Guardar todo”');
}
</script>

<?php admin_footer(); ?>
