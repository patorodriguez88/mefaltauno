<?php
// Categorías de colecciones: alta, edición, orden, si van en el menú de arriba y baja (moviendo sus colecciones).
require __DIR__ . '/_inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acc = $_POST['accion'] ?? '';
    try {
        $nombre = trim(mb_substr($_POST['nombre'] ?? '', 0, 120));
        $desc = trim(mb_substr($_POST['descripcion'] ?? '', 0, 255)) ?: null;
        $orden = (int)($_POST['orden'] ?? 0);
        $menu = !empty($_POST['en_menu']) ? 1 : 0;
        if ($acc === 'crear') {
            if ($nombre === '') throw new Exception('Poné el nombre de la categoría.');
            $slug = slugify($nombre);
            for ($i = 2; q("SELECT 1 FROM categorias WHERE slug=?", [$slug])->fetchColumn(); $i++) $slug = slugify($nombre) . "-$i";
            q("INSERT INTO categorias (slug, nombre, descripcion, orden, en_menu) VALUES (?,?,?,?,?)", [$slug, $nombre, $desc, $orden, $menu]);
            flash('ok', "Categoría “{$nombre}” creada.");
        } elseif ($acc === 'editar') {
            if ($nombre === '') throw new Exception('El nombre no puede quedar vacío.');
            // El slug (la dirección /colecciones.php?cat=…) no cambia, para no romper links ya compartidos
            q("UPDATE categorias SET nombre=?, descripcion=?, orden=?, en_menu=? WHERE id=?", [$nombre, $desc, $orden, $menu, (int)$_POST['id']]);
            flash('ok', 'Categoría guardada.');
        } elseif ($acc === 'borrar') {
            $id = (int)$_POST['id'];
            $n = (int)q("SELECT COUNT(*) FROM colecciones WHERE categoria_id=?", [$id])->fetchColumn();
            if ($n) {
                $destino = (int)($_POST['mover_a'] ?? 0);
                if (!$destino || $destino === $id) throw new Exception("Tiene $n colecciones: elegí a qué categoría pasarlas antes de borrarla.");
                q("UPDATE colecciones SET categoria_id=? WHERE categoria_id=?", [$destino, $id]);
            }
            q("DELETE FROM categorias WHERE id=?", [$id]);
            flash('ok', 'Categoría borrada' . ($n ? " y sus $n colecciones pasaron a la otra." : '.'));
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/categorias.php');
}

$cats = q("SELECT cat.*, COUNT(c.id) AS colecciones, SUM(c.activa) AS visibles FROM categorias cat LEFT JOIN colecciones c ON c.categoria_id=cat.id
           GROUP BY cat.id ORDER BY cat.orden, cat.nombre")->fetchAll();
$sin_cat = (int)q("SELECT COUNT(*) FROM colecciones WHERE categoria_id IS NULL")->fetchColumn();
$en_menu = count(array_filter($cats, fn($c) => $c['en_menu']));

admin_header('Categorías', 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Stock</a></p>
<div class="section-head">
    <h1 style="margin:0">Categorías</h1>
    <button class="btn btn-primario btn-chico" type="button" onclick="document.getElementById('modal-cat').showModal()">+ Nueva categoría</button>
</div>
<div class="aviso" style="margin-bottom:16px;background:var(--teal-50);color:var(--teal-900)">
    Las categorías agrupan las colecciones. Las marcadas <b>“En el menú”</b> aparecen arriba, al lado de “Colecciones” (hoy <?= $en_menu ?>; con más de 4 o 5 el menú se satura en pantallas chicas).
    Todas aparecen igual en la página de colecciones y en la home (“Elegí tu universo”), en el orden que pongas.
    <?php if ($sin_cat): ?><br><b><?= $sin_cat ?> colecciones no tienen categoría</b>: asignáselas desde cada colección.<?php endif; ?>
</div>

<div class="cats-admin">
    <?php foreach ($cats as $c): ?>
        <form method="post" class="card form cat-fila">
            <?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div class="cat-campos">
                <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($c['nombre']) ?>" required></label>
                <label class="campo cat-desc">Descripción <small>(se ve en la home)</small><input type="text" name="descripcion" value="<?= e($c['descripcion']) ?>" maxlength="255"></label>
                <label class="campo cat-orden">Orden<input type="number" name="orden" value="<?= (int)$c['orden'] ?>"></label>
                <label class="tengo-toggle cat-menu" style="color:var(--ink)"><input type="checkbox" name="en_menu" value="1" <?= $c['en_menu'] ? 'checked' : '' ?>> En el menú</label>
            </div>
            <div class="cat-pie">
                <span class="muted small"><?= (int)$c['colecciones'] ?> colecciones<?= $c['colecciones'] ? ' (' . (int)$c['visibles'] . ' publicadas)' : '' ?> · <a href="<?= url('colecciones.php?cat=' . urlencode($c['slug'])) ?>" target="_blank">ver ↗</a></span>
                <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button class="btn btn-teal btn-chico" type="submit">Guardar</button>
                    <button class="btn-texto" type="button" style="color:var(--red)" onclick="borrarCat(<?= (int)$c['id'] ?>, <?= e(json_encode($c['nombre'])) ?>, <?= (int)$c['colecciones'] ?>)">Borrar</button>
                </span>
            </div>
        </form>
    <?php endforeach; ?>
</div>

<dialog class="modal" id="modal-cat" aria-labelledby="modal-cat-titulo">
    <form method="post" class="modal-caja form">
        <button class="modal-cerrar" type="button" aria-label="Cerrar" onclick="this.closest('dialog').close()">×</button>
        <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
        <h3 id="modal-cat-titulo">Nueva categoría</h3>
        <label class="campo">Nombre<input type="text" name="nombre" required autofocus placeholder="Ej: Muñecos y figuras"></label>
        <label class="campo">Descripción <small>(una línea, se ve en la home)</small><input type="text" name="descripcion" maxlength="255" placeholder="Ej: Figuras y muñecos de colección para exhibir."></label>
        <div class="form-row">
            <label class="campo">Orden<input type="number" name="orden" value="<?= count($cats) + 1 ?>"></label>
            <label class="tengo-toggle" style="color:var(--ink);align-self:end"><input type="checkbox" name="en_menu" value="1" checked> Mostrar en el menú</label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-texto" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
            <button class="btn btn-primario" type="submit">Crear categoría</button>
        </div>
    </form>
</dialog>

<dialog class="modal" id="modal-borrar-cat" aria-labelledby="modal-borrar-titulo">
    <form method="post" class="modal-caja form">
        <button class="modal-cerrar" type="button" aria-label="Cerrar" onclick="this.closest('dialog').close()">×</button>
        <?= csrf_field() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id">
        <h3 id="modal-borrar-titulo">Borrar categoría</h3>
        <p class="muted" id="borrar-texto"></p>
        <label class="campo" id="borrar-mover">Pasar sus colecciones a
            <select name="mover_a"><option value="">Elegí…</option>
                <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select></label>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-texto" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
            <button class="btn btn-primario" type="submit" style="background:var(--red);border-color:var(--red);color:#fff">Borrar</button>
        </div>
    </form>
</dialog>

<script>
function borrarCat(id, nombre, colecciones) {
    const d = document.getElementById('modal-borrar-cat');
    d.querySelector('[name=id]').value = id;
    d.querySelector('#borrar-texto').textContent = colecciones
        ? `“${nombre}” tiene ${colecciones} colecciones. Elegí a qué categoría pasarlas.`
        : `¿Borrar “${nombre}”? No tiene colecciones.`;
    const mover = d.querySelector('#borrar-mover');
    mover.hidden = !colecciones;
    mover.querySelector('select').required = !!colecciones;
    [...mover.querySelectorAll('option')].forEach(o => o.hidden = o.value === String(id));
    d.showModal();
}
</script>

<?php admin_footer(); ?>
