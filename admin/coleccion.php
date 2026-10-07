<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);
$col = q("SELECT * FROM colecciones WHERE id=?", [$id])->fetch();
if (!$col) redirect('admin/colecciones.php');

function monto($v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    // "19.390,50" o "19390.5" → 19390.5
    if (strpos($v, ',') !== false) $v = str_replace(['.', ','], ['', '.'], $v);
    return (float)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $db = db();
    $db->beginTransaction();
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') throw new Exception('El nombre no puede quedar vacío.');
        q("UPDATE colecciones SET nombre=?, categoria_id=?, bajada=?, descripcion=?, imagen=?, activa=?, destacada=?, orden=? WHERE id=?", [
            $nombre, (int)($_POST['categoria_id'] ?? 0) ?: null, trim($_POST['bajada'] ?? '') ?: null, trim($_POST['descripcion'] ?? '') ?: null,
            trim($_POST['imagen'] ?? '') ?: null, !empty($_POST['activa']) ? 1 : 0, !empty($_POST['destacada']) ? 1 : 0, (int)($_POST['orden'] ?? 0), $id,
        ]);
        foreach ((array)($_POST['items'] ?? []) as $iid => $it) {
            $titulo = trim($it['titulo'] ?? '');
            if ($titulo === '') continue;
            q("UPDATE items SET numero=?, titulo=?, imagen=?, precio=?, precio_promo=?, stock=?, sku=?, activo=? WHERE id=? AND coleccion_id=?", [
                (int)$it['numero'], $titulo, trim($it['imagen'] ?? '') ?: null, monto($it['precio']) ?? 0, monto($it['precio_promo']),
                max(0, (int)$it['stock']), trim($it['sku'] ?? '') ?: null, !empty($it['activo']) ? 1 : 0, (int)$iid, $id,
            ]);
        }
        $nuevo = $_POST['nuevo'] ?? [];
        if (trim($nuevo['titulo'] ?? '') !== '') {
            $num = (int)($nuevo['numero'] ?? 0) ?: ((int)q("SELECT COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=?", [$id])->fetchColumn() + 1);
            q("INSERT INTO items (coleccion_id, numero, titulo, imagen, precio, stock) VALUES (?,?,?,?,?,?)",
              [$id, $num, trim($nuevo['titulo']), trim($nuevo['imagen'] ?? '') ?: null, monto($nuevo['precio'] ?? '') ?? 0, max(0, (int)($nuevo['stock'] ?? 0))]);
        }
        $db->commit();
        flash('ok', 'Cambios guardados.');
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

<form method="post" class="form">
    <?= csrf_field() ?>
    <div class="layout-2">
        <div class="card form">
            <h3>Datos de la colección</h3>
            <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($col['nombre']) ?>" required></label>
            <label class="campo">Bajada <small>(una línea, para buscadores)</small><input type="text" name="bajada" value="<?= e($col['bajada']) ?>"></label>
            <label class="campo">Descripción<textarea name="descripcion" rows="6"><?= e($col['descripcion']) ?></textarea></label>
            <label class="campo">Imagen de portada <small>(URL)</small><input type="text" name="imagen" value="<?= e($col['imagen']) ?>"></label>
        </div>
        <aside class="card form">
            <?php if ($col['imagen']): ?><img src="<?= e($col['imagen']) ?>" alt="" style="border-radius:10px;aspect-ratio:1;object-fit:cover"><?php endif; ?>
            <label class="campo">Categoría
                <select name="categoria_id"><option value="">—</option>
                    <?php foreach (categorias() as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= $cat['id'] == $col['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="campo">Orden<input type="number" name="orden" value="<?= (int)$col['orden'] ?>"></label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $col['activa'] ? 'checked' : '' ?>> Visible en la tienda</label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="destacada" value="1" <?= $col['destacada'] ? 'checked' : '' ?>> Destacada en el inicio</label>
            <button class="btn btn-primario" type="submit">Guardar todo</button>
        </aside>
    </div>

    <h3 style="margin-top:12px">Números</h3>
    <p class="muted small" style="margin-top:-8px">El stock lo va a informar WePoint cuando esté la integración; por ahora se carga a mano. “Lo piden” = clientes esperando que lo consigamos.</p>
    <div class="tabla-wrap">
        <table class="tabla">
            <tr><th>N°</th><th>Título</th><th>Precio</th><th>Promo</th><th>Stock</th><th>Lo piden</th><th>SKU</th><th>Imagen (URL)</th><th>Activo</th></tr>
            <?php foreach ($items as $it): $n = 'items[' . (int)$it['id'] . ']'; ?>
                <tr>
                    <td><input type="number" name="<?= $n ?>[numero]" value="<?= (int)$it['numero'] ?>" style="width:70px"></td>
                    <td><input type="text" name="<?= $n ?>[titulo]" value="<?= e($it['titulo']) ?>" style="min-width:220px"></td>
                    <td><input type="text" name="<?= $n ?>[precio]" value="<?= e((float)$it['precio']) ?>" inputmode="decimal" style="width:100px"></td>
                    <td><input type="text" name="<?= $n ?>[precio_promo]" value="<?= $it['precio_promo'] !== null ? e((float)$it['precio_promo']) : '' ?>" inputmode="decimal" style="width:100px"></td>
                    <td><input type="number" name="<?= $n ?>[stock]" value="<?= (int)$it['stock'] ?>" min="0" style="width:80px"></td>
                    <td><?= $it['pedidos'] ? '<span class="badge badge-amarillo">' . (int)$it['pedidos'] . '</span>' : '' ?></td>
                    <td><input type="text" name="<?= $n ?>[sku]" value="<?= e($it['sku']) ?>" style="width:110px"></td>
                    <td><input type="text" name="<?= $n ?>[imagen]" value="<?= e($it['imagen']) ?>" style="min-width:200px"></td>
                    <td><input type="checkbox" name="<?= $n ?>[activo]" value="1" <?= $it['activo'] ? 'checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:var(--teal-50)">
                <td><input type="number" name="nuevo[numero]" placeholder="auto" style="width:70px"></td>
                <td><input type="text" name="nuevo[titulo]" placeholder="+ Agregar número…" style="min-width:220px"></td>
                <td><input type="text" name="nuevo[precio]" inputmode="decimal" style="width:100px"></td>
                <td></td>
                <td><input type="number" name="nuevo[stock]" min="0" value="0" style="width:80px"></td>
                <td></td><td></td>
                <td><input type="text" name="nuevo[imagen]" style="min-width:200px"></td>
                <td></td>
            </tr>
        </table>
    </div>
    <div><button class="btn btn-primario" type="submit">Guardar todo</button></div>
</form>

<?php admin_footer(); ?>
