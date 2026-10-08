<?php
require __DIR__ . '/_inc.php';

// Alta rápida de colección con N números
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_exigir();
    $nombre = trim($_POST['nombre'] ?? '');
    $cant = max(1, min(500, (int)($_POST['cantidad'] ?? 1)));
    $precio = (float)str_replace(['.', ','], ['', '.'], $_POST['precio'] ?? '0');
    if ($nombre === '') {
        flash('error', 'Poné el nombre de la colección.');
        redirect('admin/colecciones.php');
    }
    $slug = slugify($nombre);
    $base = $slug;
    for ($i = 2; q("SELECT 1 FROM colecciones WHERE slug=?", [$slug])->fetchColumn(); $i++) $slug = "$base-$i";
    $db = db();
    $db->beginTransaction();
    q("INSERT INTO colecciones (categoria_id, slug, nombre, activa) VALUES (?,?,?,0)", [(int)($_POST['categoria_id'] ?? 0) ?: null, $slug, $nombre]);
    $cid = (int)$db->lastInsertId();
    for ($n = 1; $n <= $cant; $n++) {
        q("INSERT INTO items (coleccion_id, numero, titulo, precio, stock) VALUES (?,?,?,?,0)", [$cid, $n, "Número $n", $precio]);
    }
    $db->commit();
    flash('ok', 'Colección creada (oculta). Completá títulos, imágenes y stock, y activala.');
    redirect('admin/coleccion.php?id=' . $cid);
}

$sin_stock = !empty($_GET['sin_stock']);
$cols = q("SELECT c.*, cat.nombre AS categoria, COUNT(i.id) AS total, SUM(i.stock<=0) AS sin_stock, SUM(i.stock) AS unidades,
                  (SELECT COUNT(*) FROM cliente_colecciones cc WHERE cc.coleccion_id=c.id) AS seguidores
           FROM colecciones c LEFT JOIN categorias cat ON cat.id=c.categoria_id LEFT JOIN items i ON i.coleccion_id=c.id AND i.activo=1
           GROUP BY c.id " . ($sin_stock ? 'HAVING sin_stock > 0' : '') . " ORDER BY c.activa DESC, c.orden, c.nombre")->fetchAll();

$por_publicar = wepoint_nuevos_pendientes();
admin_header('Stock', 'colecciones');
?>

<?php if ($por_publicar): ?>
    <a class="aviso aviso-link" href="<?= url('admin/nuevos.php') ?>">
        <span>📦 <b><?= $por_publicar ?> producto<?= $por_publicar > 1 ? 's' : '' ?> de WePoint</b> esperando foto para publicarse en la web.</span>
        <span class="btn btn-primario btn-chico">Ver por publicar</span>
    </a>
<?php endif; ?>

<div class="section-head">
    <h1 style="margin:0">Stock</h1>
    <?php if ($sin_stock): ?><a href="<?= url('admin/colecciones.php') ?>">Ver todas</a><?php endif; ?>
</div>

<div class="tabla-wrap" style="margin-bottom:24px">
    <table class="tabla">
        <tr><th></th><th>Colección</th><th>Categoría</th><th>Números</th><th>Sin stock</th><th>Unidades</th><th>La siguen</th><th>Estado</th></tr>
        <?php foreach ($cols as $c): ?>
            <tr class="clic" onclick="location='<?= url('admin/coleccion.php?id=' . (int)$c['id']) ?>'">
                <td><?= foto($c['imagen'] ?? '', '📚', 'width:44px;height:44px;border-radius:8px;object-fit:cover;background:var(--teal-50);font-size:1.1rem') ?></td>
                <td><b><?= e($c['nombre']) ?></b><?= $c['destacada'] ? ' <span class="badge badge-amarillo">Destacada</span>' : '' ?></td>
                <td class="small"><?= e($c['categoria'] ?? '—') ?></td>
                <td><?= (int)$c['total'] ?></td>
                <td><?= $c['sin_stock'] ? '<span class="badge badge-rojo">' . (int)$c['sin_stock'] . '</span>' : '0' ?></td>
                <td><?= (int)$c['unidades'] ?></td>
                <td><?= (int)$c['seguidores'] ?></td>
                <td><?= $c['activa'] ? '<span class="badge badge-verde">Visible</span>' : '<span class="badge badge-gris">Oculta</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<form method="post" class="card form" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear">
    <h3>Nueva colección</h3>
    <label class="campo">Nombre<input type="text" name="nombre" required></label>
    <div class="form-row">
        <label class="campo">Categoría
            <select name="categoria_id"><option value="">—</option>
                <?php foreach (categorias() as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['nombre']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="campo">Cantidad de números<input type="number" name="cantidad" min="1" max="500" value="10" required></label>
    </div>
    <label class="campo">Precio por número<input type="text" name="precio" inputmode="decimal" placeholder="Ej: 19390"></label>
    <button class="btn btn-teal" type="submit">Crear colección</button>
</form>

<?php admin_footer(); ?>
