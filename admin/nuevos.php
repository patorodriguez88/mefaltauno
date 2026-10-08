<?php
// Bandeja "Por publicar": todo lo que está en la cuenta de WePoint y todavía no es un número de la web.
// El operador elige colección y número, sube la foto y lo crea; sin foto queda creado pero sin publicar.
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $wid = (string)($_POST['wepoint_id'] ?? '');
    $n = q("SELECT * FROM wepoint_nuevos WHERE wepoint_id=?", [$wid])->fetch();
    try {
        if (!$n) throw new Exception('Ese producto ya no está en la bandeja.');
        $col = q("SELECT * FROM colecciones WHERE id=?", [(int)($_POST['coleccion_id'] ?? 0)])->fetch();
        if (!$col) throw new Exception('Elegí la colección.');
        $numero = (int)($_POST['numero'] ?? 0);
        if ($numero <= 0) throw new Exception('Indicá el número.');
        $titulo = trim($_POST['titulo'] ?? '');
        $precio = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['precio'] ?? '0'));
        $foto = subir_imagen($_FILES['foto'] ?? null, 'items', $col['slug'] . '-' . $numero);
        $existe = q("SELECT * FROM items WHERE coleccion_id=? AND numero=?", [$col['id'], $numero])->fetch();
        if ($existe) {
            // El número ya estaba cargado (ej: colección "Próximamente"): se vincula a este producto de WePoint
            if ($existe['wepoint_id'] && (string)$existe['wepoint_id'] !== (string)$n['wepoint_id']) {
                throw new Exception($col['nombre'] . ' ' . num($numero) . ' ya está vinculado a otro producto de WePoint (id ' . $existe['wepoint_id'] . '). Elegí otro número.');
            }
            $titulo = $existe['titulo'] !== '' ? $existe['titulo'] : $titulo;
            $precio = (float)$existe['precio'] > 0 ? (float)$existe['precio'] : $precio;
            $foto = $foto ?: $existe['imagen'];
            $falta = item_falta_para_publicar($foto, $titulo, $precio);
            q("UPDATE items SET titulo=?, imagen=?, precio=?, stock=?, sku=COALESCE(NULLIF(sku,''), ?), wepoint_id=?, stock_sync_at=NOW(), activo=? WHERE id=?",
              [$titulo, $foto, $precio, (int)$n['stock'], $n['sku'], $n['wepoint_id'], $falta ? 0 : 1, $existe['id']]);
            $item_id = (int)$existe['id'];
            flash('ok', 'Vinculado al ' . num($numero) . ' que ya existía en ' . $col['nombre'] . '.');
        } else {
            if ($titulo === '') throw new Exception('Poné el título.');
            $falta = item_falta_para_publicar($foto, $titulo, $precio);
            q("INSERT INTO items (coleccion_id, numero, titulo, imagen, precio, stock, sku, wepoint_id, stock_sync_at, activo) VALUES (?,?,?,?,?,?,?,?,NOW(),?)",
              [$col['id'], $numero, $titulo, $foto, $precio, (int)$n['stock'], $n['sku'], $n['wepoint_id'], $falta ? 0 : 1]);
            $item_id = (int)db()->lastInsertId();
        }
        q("DELETE FROM wepoint_nuevos WHERE wepoint_id=?", [$wid]);
        // ¿Es lo que pidió alguien en "Me falta"? Se vincula y, si hay stock, se le reserva
        $si_id = (int)($_POST['solicitud_item_id'] ?? 0);
        if ($si_id && q("UPDATE solicitud_items SET item_id=? WHERE id=? AND item_id IS NULL", [$item_id, $si_id])->rowCount()) {
            if (reservas_asignar()) flash('ok', 'Quedó reservado ' . RESERVA_HORAS . ' h para quien lo buscaba, y le avisamos por mail.');
        }
        if ($falta) flash('error', num($numero) . ' de ' . $col['nombre'] . ' quedó cargado pero sin publicar: falta ' . implode(', ', $falta) . '.');
        else flash('ok', num($numero) . ' de ' . $col['nombre'] . ' ya está publicado en la tienda.');
        redirect('admin/coleccion.php?id=' . (int)$col['id']);
    } catch (Exception $e) {
        flash('error', $e->getMessage());
        redirect('admin/nuevos.php');
    }
}

$nuevos = q("SELECT * FROM wepoint_nuevos ORDER BY nombre")->fetchAll();
$cols = q("SELECT id, nombre FROM colecciones ORDER BY nombre")->fetchAll();
// Búsquedas libres abiertas (números que no estaban en el catálogo): alguno puede ser este producto
$libres = q("SELECT si.id, si.descripcion, s.id AS solicitud_id, c.nombre, c.apellido FROM solicitud_items si
             JOIN solicitudes s ON s.id=si.solicitud_id JOIN clientes c ON c.id=s.cliente_id
             WHERE si.item_id IS NULL AND s.estado IN ('pendiente','buscando') ORDER BY s.created_at")->fetchAll();
$ultima = q("SELECT valor FROM ajustes WHERE clave='wepoint_ultima_sync'")->fetchColumn();

admin_header('Por publicar', 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Stock</a></p>
<div class="section-head">
    <h1 style="margin:0">Por publicar</h1>
</div>
<div class="aviso" style="margin-bottom:16px;background:var(--teal-50);color:var(--teal-900)">
    Acá aparece <b>todo lo que está en la cuenta de WePoint</b> y todavía no es un número de la web.
    Elegí la colección y el número, revisá título y precio, subí la foto y crealo. <b>Si el número ya existe</b> (por ejemplo, de una colección “Próximamente”), se vincula a ese. <b>Sin foto, título o precio se crea pero no se publica.</b>
    El stock es el disponible para venta: lo recepcionado que el depósito todavía no ubicó cuenta como 0.
    <?php if ($ultima): ?><br><span class="small">Última sincronización con WePoint: <?= e(fecha($ultima)) ?></span><?php endif; ?>
</div>

<?php if (!$nuevos): ?>
    <div class="card vacio"><h3>No hay productos nuevos de WePoint</h3><p class="muted">Cuando el depósito cargue un producto en WePoint, aparece acá en la próxima sincronización.</p></div>
<?php endif; ?>

<div class="nuevos">
    <?php foreach ($nuevos as $nv):
        [$col_sug, $num_sug] = wepoint_sku_partes($nv['sku']); ?>
        <form method="post" enctype="multipart/form-data" class="nuevo-card form">
            <?= csrf_field() ?>
            <input type="hidden" name="wepoint_id" value="<?= e($nv['wepoint_id']) ?>">
            <label class="foto-mini foto-nuevo" title="Subir foto">
                <span>📷<br><small>Foto</small></span>
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" hidden onchange="previewNuevo(this)">
            </label>
            <div class="nuevo-datos">
                <div class="nuevo-wp">
                    <b><?= e($nv['nombre'] ?? 'Sin nombre en WePoint') ?></b> <span class="muted small"><?= $nv['sku'] ? 'SKU ' . e($nv['sku']) : 'sin SKU' ?> · id WePoint <?= e($nv['wepoint_id']) ?></span>
                    <span class="badge <?= $nv['stock'] > 0 ? 'badge-verde' : 'badge-gris' ?>"><?= (int)$nv['stock'] ?> disponibles</span>
                </div>
                <div class="nuevo-campos">
                    <label class="campo">Colección
                        <select name="coleccion_id" required>
                            <option value="">Elegí…</option>
                            <?php foreach ($cols as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $col_sug === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="campo campo-num">N°<input type="number" name="numero" min="1" value="<?= $num_sug ?: '' ?>" required></label>
                    <label class="campo">Título<input type="text" name="titulo" value="<?= e($nv['nombre'] ?? '') ?>" required></label>
                    <label class="campo campo-precio">Precio<input type="text" name="precio" inputmode="decimal" value="<?= $nv['precio'] !== null ? e((float)$nv['precio']) : '' ?>" required></label>
                </div>
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
    <?php endforeach; ?>
</div>

<script>
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
