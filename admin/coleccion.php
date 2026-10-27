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
$sal = salidas_resumen($col, $salidas);

admin_header($col['nombre'], 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Colecciones</a> · <a href="<?= url('coleccion.php?c=' . urlencode($col['slug'])) ?>" target="_blank">Ver en la tienda ↗</a> · <a href="<?= url('admin/salvat.php?coleccion=' . (int)$col['id']) ?>">📷 Mejorar fotos desde Salvat</a></p>

<form method="post" class="form" enctype="multipart/form-data">
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
            <b>El stock lo informa WePoint</b> y no se puede sumar desde acá. Podés <b>congelar</b> un número (deja de venderse) o ponerle un <b>tope</b> (vender como máximo esa cantidad). Cada número se vincula a WePoint por su <b>SKU</b>.
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
                        $res_fis = array_sum(reservas_mapa()[(int)$it['id']] ?? []);
                        $res_enc = array_sum(reservas_mapa_encargue()[(int)$it['id']] ?? []);
                        $disp_enc = disponible_encargue($it);
                        $disp_tot = disponible_total($it);
                        ?>
                        <div class="stock-ficha">
                            <span title="Unidades en el depósito según WePoint (entrega en 24 h)">WePoint</span>
                            <?php if ($con_wepoint): ?><b><?= (int)$it['stock'] ?></b>
                            <?php else: ?><input type="number" name="<?= $n ?>[stock]" value="<?= (int)$it['stock'] ?>" min="0" class="in-num"><?php endif; ?>
                            <span title="Lo que Brihet informó<?= $it['brihet_stock_at'] ? ' el ' . e(fecha($it['brihet_stock_at'])) : '' ?> (se vende por encargue)">Brihet</span>
                            <b><?= $it['brihet_stock'] !== null ? (int)$it['brihet_stock'] : '—' ?></b>
                            <span title="Guardado <?= RESERVA_HORAS ?> h para quien lo buscaba o lo tiene en su abono: no lo puede comprar otro">Reservado</span>
                            <b class="<?= $res_fis + $res_enc ? 'stock-res' : '' ?>"><?= $res_fis + $res_enc ?></b>
                            <span class="stock-disp" title="Lo que puede comprar cualquier cliente ahora: descuenta reservas, tope y congelado">Disponible</span>
                            <b class="stock-disp <?= $disp_tot ? 'si' : '' ?>"><?= $disp_tot ?></b>
                        </div>
                        <?php if ($it['congelado']): ?><div class="small" style="color:var(--red)">Congelado: no se vende</div>
                        <?php elseif ($disp_tot && $disp_enc): ?><div class="small muted"><?= $disp ?> en 24 h · <?= $disp_enc ?> por encargue</div><?php endif; ?>
                        <?php if ($con_wepoint): ?><div class="small muted"><?= $it['stock_sync_at'] ? 'WePoint: ' . fecha($it['stock_sync_at']) : 'sin sincronizar' ?></div><?php endif; ?>
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
                        <input type="date" name="<?= $n ?>[salida]" value="<?= e($it['salida']) ?>" title="Vacía = calculada con la periodicidad">
                        <?php if ($sf && !$sf['manual']): ?><div class="small muted">aprox. <?= fecha_dia($sf['fecha']) ?></div><?php endif; ?>
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
</form>

<script>
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
