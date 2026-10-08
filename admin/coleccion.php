<?php
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

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
        q("UPDATE colecciones SET nombre=?, categoria_id=?, bajada=?, descripcion=?, imagen=?, activa=?, destacada=?, orden=? WHERE id=?", [
            $nombre, (int)($_POST['categoria_id'] ?? 0) ?: null, trim($_POST['bajada'] ?? '') ?: null, trim($_POST['descripcion'] ?? '') ?: null,
            $portada, !empty($_POST['activa']) ? 1 : 0, !empty($_POST['destacada']) ? 1 : 0, (int)($_POST['orden'] ?? 0), $id,
        ]);

        $fotos = archivos_por_id('foto_item');
        $sin_publicar = [];
        foreach ((array)($_POST['items'] ?? []) as $iid => $it) {
            $titulo = trim($it['titulo'] ?? '');
            if ($titulo === '') continue;
            $actual = q("SELECT imagen, stock FROM items WHERE id=? AND coleccion_id=?", [(int)$iid, $id])->fetch();
            if (!$actual) continue;
            $foto = subir_imagen($fotos[$iid] ?? null, 'items', $col['slug'] . '-' . (int)$it['numero']) ?? $actual['imagen'];
            $limite = trim((string)($it['limite'] ?? '')) === '' ? null : max(0, (int)$it['limite']);
            // Con WePoint el stock no se toca desde acá; sin WePoint (modo manual) sí
            $stock = $con_wepoint ? (int)$actual['stock'] : max(0, (int)($it['stock'] ?? 0));
            $precio = monto($it['precio']) ?? 0;
            $activo = !empty($it['activo']) ? 1 : 0;
            if ($activo && ($falta = item_falta_para_publicar($foto, $titulo, $precio))) {
                $activo = 0;
                $sin_publicar[] = num((int)$it['numero']) . ' (falta ' . implode(', ', $falta) . ')';
            }
            q("UPDATE items SET numero=?, titulo=?, imagen=?, precio=?, precio_promo=?, stock=?, congelado=?, limite=?, sku=?, activo=? WHERE id=? AND coleccion_id=?", [
                (int)$it['numero'], $titulo, $foto, $precio, monto($it['precio_promo']),
                $stock, !empty($it['congelado']) ? 1 : 0, $limite, strtoupper(trim($it['sku'] ?? '')) ?: null,
                $activo, (int)$iid, $id,
            ]);
        }

        $nuevo = $_POST['nuevo'] ?? [];
        if (trim($nuevo['titulo'] ?? '') !== '') {
            $num = (int)($nuevo['numero'] ?? 0) ?: ((int)q("SELECT COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=?", [$id])->fetchColumn() + 1);
            $foto = subir_imagen($_FILES['foto_nuevo'] ?? null, 'items', $col['slug'] . '-' . $num);
            $precio = monto($nuevo['precio'] ?? '') ?? 0;
            $activo = item_falta_para_publicar($foto, $nuevo['titulo'], $precio) ? 0 : 1;
            if (!$activo) $sin_publicar[] = num($num) . ' (queda sin publicar hasta tener foto y precio)';
            q("INSERT INTO items (coleccion_id, numero, titulo, imagen, precio, stock, sku, activo) VALUES (?,?,?,?,?,0,?,?)",
              [$id, $num, trim($nuevo['titulo']), $foto, $precio, strtoupper(trim($nuevo['sku'] ?? '')) ?: null, $activo]);
        }
        $db->commit();
        flash('ok', 'Cambios guardados.');
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

$items = q("SELECT i.*, (SELECT COUNT(*) FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id WHERE si.item_id=i.id AND s.estado IN ('pendiente','buscando')) AS pedidos
            FROM items i WHERE coleccion_id=? ORDER BY numero", [$id])->fetchAll();

admin_header($col['nombre'], 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Colecciones</a> · <a href="<?= url('coleccion.php?c=' . urlencode($col['slug'])) ?>" target="_blank">Ver en la tienda ↗</a></p>

<form method="post" class="form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="layout-2">
        <div class="card form">
            <h3>Datos de la colección</h3>
            <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($col['nombre']) ?>" required></label>
            <label class="campo">Bajada <small>(una línea, para buscadores)</small><input type="text" name="bajada" value="<?= e($col['bajada']) ?>"></label>
            <label class="campo">Descripción<textarea name="descripcion" rows="6"><?= e($col['descripcion']) ?></textarea></label>
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
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $col['activa'] ? 'checked' : '' ?>> Publicada en la tienda</label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="destacada" value="1" <?= $col['destacada'] ? 'checked' : '' ?>> Destacada en el inicio</label>
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
            <tr><th>Foto</th><th>N°</th><th>Título</th><th>Precio / promo</th><th>Stock<?= $con_wepoint ? ' WePoint' : '' ?></th><th>Tope</th><th>Estado</th><th>SKU</th></tr>
            <?php foreach ($items as $it): $n = 'items[' . (int)$it['id'] . ']'; $disp = disponible($it); ?>
                <tr class="<?= $it['congelado'] ? 'fila-congelada' : '' ?><?= !$it['activo'] ? ' fila-oculta' : '' ?>">
                    <td>
                        <label class="foto-mini" title="Cambiar foto">
                            <?php if ($it['imagen']): ?><img src="<?= e(img($it['imagen'])) ?>" alt=""><?php else: ?><span>📷</span><?php endif; ?>
                            <input type="file" name="foto_item[<?= (int)$it['id'] ?>]" accept="image/jpeg,image/png,image/webp" hidden onchange="previewFoto(this)">
                        </label>
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
                        <?php if ($con_wepoint): ?>
                            <b><?= (int)$it['stock'] ?></b>
                        <?php else: ?>
                            <input type="number" name="<?= $n ?>[stock]" value="<?= (int)$it['stock'] ?>" min="0" class="in-num">
                        <?php endif; ?>
                        <span class="badge <?= $disp > 0 ? 'badge-verde' : 'badge-gris' ?>" title="Se vende">se vende <?= $disp ?></span>
                        <?php if ($con_wepoint): ?><div class="muted small"><?= $it['stock_sync_at'] ? fecha($it['stock_sync_at']) : 'sin sincronizar' ?></div><?php endif; ?>
                    </td>
                    <td><input type="number" name="<?= $n ?>[limite]" value="<?= $it['limite'] !== null ? (int)$it['limite'] : '' ?>" min="0" placeholder="—" class="in-num" title="Vender como máximo esta cantidad. Vacío = sin tope"></td>
                    <td class="td-estado">
                        <label title="<?= $it['imagen'] ? 'Visible en la tienda' : 'Subí una foto para poder publicarlo' ?>"><input type="checkbox" name="<?= $n ?>[activo]" value="1" <?= $it['activo'] ? 'checked' : '' ?> <?= $it['imagen'] ? '' : 'disabled data-sin-foto' ?>> Publicado</label>
                        <?php if (!$it['imagen']): ?><div class="small" style="color:var(--amber)">Falta foto</div><?php endif; ?>
                        <label title="Congelado: no se vende aunque haya stock"><input type="checkbox" name="<?= $n ?>[congelado]" value="1" <?= $it['congelado'] ? 'checked' : '' ?>> Congelado</label>
                    </td>
                    <td><input type="text" name="<?= $n ?>[sku]" value="<?= e($it['sku']) ?>" class="in-sku"><?= $it['wepoint_id'] ? '<div class="small" style="color:var(--green)">✓ WePoint</div>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:var(--teal-50)">
                <td><label class="foto-mini" title="Foto"><span>📷</span><input type="file" name="foto_nuevo" accept="image/jpeg,image/png,image/webp" hidden onchange="previewFoto(this)"></label></td>
                <td><input type="number" name="nuevo[numero]" placeholder="auto" class="in-num"></td>
                <td class="td-titulo"><input type="text" name="nuevo[titulo]" placeholder="+ Agregar número…"></td>
                <td><div class="precio-par"><input type="text" name="nuevo[precio]" inputmode="decimal" placeholder="Precio"></div></td>
                <td colspan="3" class="muted small">El stock lo va a traer WePoint por el SKU.</td>
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
    const cont = input.closest('label').parentNode.querySelector('img') ? input.closest('label').parentNode : input.closest('label');
    let img = cont.querySelector('img');
    if (!img) { img = document.createElement('img'); cont.prepend(img); const s = cont.querySelector('span, .sin-foto'); if (s) s.remove(); }
    img.src = URL.createObjectURL(f);
    // Con foto ya se puede publicar
    const pub = input.closest('tr')?.querySelector('input[data-sin-foto]');
    if (pub) { pub.disabled = false; pub.checked = true; pub.closest('label').title = 'Visible en la tienda'; pub.closest('td').querySelector('.small')?.remove(); }
    toast('Foto lista: se guarda al tocar “Guardar todo”');
}
</script>

<?php admin_footer(); ?>
