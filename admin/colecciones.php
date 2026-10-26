<?php
require __DIR__ . '/_inc.php';

// Alta rápida de colección con N números
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_exigir();
    $nombre = trim($_POST['nombre'] ?? '');
    $cant = max(1, min(500, (int)($_POST['cantidad'] ?? 1)));
    $monto = fn($v) => trim((string)$v) === '' ? null : (float)str_replace(['.', ','], ['', '.'], (string)$v);
    // Los primeros números suelen tener precio propio (lanzamiento); después queda fijo
    $precio_resto = $monto($_POST['precio'] ?? '') ?? 0;
    $precios = [1 => $monto($_POST['precio_1'] ?? ''), 2 => $monto($_POST['precio_2'] ?? ''), 3 => $monto($_POST['precio_3'] ?? '')];
    // Recargo interior: igual al general → NULL (sigue al general); otro monto → propio; sin tilde → 0
    if (empty($_POST['lleva_recargo'])) {
        $recargo = 0;
    } else {
        $r = $monto($_POST['recargo'] ?? '');
        $recargo = ($r === null || abs($r - recargo_general()) < 0.01) ? null : max(0, $r);
    }
    if ($nombre === '') {
        flash('error', 'Poné el nombre de la colección.');
        redirect('admin/colecciones.php');
    }
    $slug = slugify($nombre);
    $base = $slug;
    for ($i = 2; q("SELECT 1 FROM colecciones WHERE slug=?", [$slug])->fetchColumn(); $i++) $slug = "$base-$i";
    $db = db();
    $db->beginTransaction();
    q("INSERT INTO colecciones (categoria_id, slug, nombre, activa, recargo) VALUES (?,?,?,0,?)", [(int)($_POST['categoria_id'] ?? 0) ?: null, $slug, $nombre, $recargo]);
    $cid = (int)$db->lastInsertId();
    for ($n = 1; $n <= $cant; $n++) {
        q("INSERT INTO items (coleccion_id, numero, titulo, precio, stock) VALUES (?,?,?,?,0)", [$cid, $n, "Número $n", $precios[$n] ?? $precio_resto]);
    }
    $db->commit();
    flash('ok', 'Colección creada (oculta). Completá títulos, imágenes y stock, y activala.');
    redirect('admin/coleccion.php?id=' . $cid);
}

// Qué hay para vender: en el depósito (WePoint, entrega en 24 h) y en Brihet (por encargue)
$ver = in_array($_GET['ver'] ?? '', ['deposito', 'brihet', 'calle', 'salieron'], true) ? $_GET['ver'] : '';
$filtro_ver = ['deposito' => 'HAVING en_deposito > 0', 'brihet' => 'HAVING en_brihet > 0', 'calle' => 'HAVING c.en_calle = 1', 'salieron' => 'HAVING c.en_calle = 0 AND c.proximamente = 0'][$ver] ?? '';
$cols = q("SELECT c.*, cat.nombre AS categoria, COUNT(i.id) AS total,
                  SUM(i.stock>0) AS en_deposito, SUM(GREATEST(i.stock,0)) AS unidades,
                  SUM(COALESCE(i.brihet_stock,0)>0) AS en_brihet,
                  (SELECT COUNT(*) FROM cliente_colecciones cc WHERE cc.coleccion_id=c.id) AS seguidores
           FROM colecciones c LEFT JOIN categorias cat ON cat.id=c.categoria_id LEFT JOIN items i ON i.coleccion_id=c.id AND i.activo=1
           GROUP BY c.id $filtro_ver ORDER BY c.activa DESC, c.orden, c.nombre")->fetchAll();

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
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($ver): ?><span class="small">Solo <?= ['deposito' => 'con stock en depósito', 'brihet' => 'con stock en Brihet', 'calle' => 'las que están en la calle', 'salieron' => 'las que ya salieron'][$ver] ?> · <a href="<?= url('admin/colecciones.php') ?>">Ver todas</a></span><?php endif; ?>
        <a class="btn btn-linea btn-chico" href="<?= url('admin/categorias.php') ?>">Categorías</a>
        <button class="btn btn-primario btn-chico" type="button" onclick="document.getElementById('modal-crear').showModal()">+ Crear colección</button>
        <a class="btn btn-linea btn-chico" href="<?= url('admin/brihet.php') ?>">Importar desde Brihet</a>    </div>
</div>

<nav class="adm-tabs">
    <a href="<?= url('admin/colecciones.php') ?>" class="<?= !in_array($ver, ['calle', 'salieron'], true) ? 'activo' : '' ?>">Todas</a>
    <a href="<?= url('admin/colecciones.php?ver=calle') ?>" class="<?= $ver === 'calle' ? 'activo' : '' ?>">🗞️ En la calle</a>
    <a href="<?= url('admin/colecciones.php?ver=salieron') ?>" class="<?= $ver === 'salieron' ? 'activo' : '' ?>">Ya salieron</a>
</nav>

<div class="buscador-stock">
    <input type="search" id="buscar-col" placeholder="🔍 Buscar colección o categoría…" autocomplete="off" autofocus>
    <span class="muted small" id="buscar-cuenta"><?= count($cols) ?> colecciones</span>
</div>
<div class="tabla-wrap" style="margin-bottom:24px">
    <table class="tabla" id="tabla-cols">
        <tr><th></th><th>Colección</th><th>Categoría</th><th>Números</th><th title="Números con unidades en WePoint: entrega en 24 h">En depósito</th><th title="Números que Brihet tiene disponibles: por encargue">En Brihet</th><th>La siguen</th><th>Estado</th></tr>
        <?php foreach ($cols as $c): ?>
            <tr class="clic" data-busca="<?= e($c['nombre'] . ' ' . ($c['categoria'] ?? '')) ?>" onclick="location='<?= url('admin/coleccion.php?id=' . (int)$c['id']) ?>'">
                <td><?= foto($c['imagen'] ?? '', '📚', 'width:44px;height:44px;border-radius:8px;object-fit:cover;background:var(--teal-50);font-size:1.1rem') ?></td>
                <td><b><?= e($c['nombre']) ?></b><?= $c['destacada'] ? ' <span class="badge badge-amarillo">Destacada</span>' : '' ?></td>
                <td class="small"><?= e($c['categoria'] ?? '—') ?></td>
                <td><?= (int)$c['total'] ?></td>
                <td><?= $c['en_deposito'] ? '<span class="badge badge-verde">' . (int)$c['en_deposito'] . '</span> <span class="small muted">' . (int)$c['unidades'] . ' u.</span>' : '<span class="muted">—</span>' ?></td>
                <td><?= $c['en_brihet'] ? '<span class="badge badge-azul">' . (int)$c['en_brihet'] . '</span>' : '<span class="muted">—</span>' ?></td>
                <td><?= (int)$c['seguidores'] ?></td>
                <td><?= $c['activa'] ? '<span class="badge badge-verde">Visible</span>' : '<span class="badge badge-gris">Oculta</span>' ?>
                    <?= $c['en_calle'] ? '<span class="badge badge-amarillo" title="' . e(PERIODICIDADES[$c['periodicidad']][0] ?? 'Sale en kioscos') . '">En la calle</span>' : ($c['proximamente'] ? '<span class="badge badge-azul">Próximamente</span>' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        <tr id="buscar-vacio" hidden><td colspan="8" class="muted">Ninguna colección coincide con la búsqueda.</td></tr>
    </table>
</div>

<dialog class="modal" id="modal-crear" aria-labelledby="modal-crear-titulo">
    <form method="post" class="modal-caja form">
        <button class="modal-cerrar" type="button" aria-label="Cerrar" onclick="this.closest('dialog').close()">×</button>
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">
        <h3 id="modal-crear-titulo">Nueva colección</h3>
        <p class="muted small" style="margin:-4px 0 0">Se crea oculta, con sus números listos para completar título, foto y precio. Después la publicás.</p>
        <label class="campo">Nombre<input type="text" name="nombre" required autofocus></label>
        <div class="form-row">
            <label class="campo">Categoría
                <select name="categoria_id"><option value="">—</option>
                    <?php foreach (categorias() as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="campo">Cantidad de números<input type="number" name="cantidad" min="1" max="500" value="10" required></label>
        </div>
        <fieldset class="precios-escalon">
            <legend>Precios <small>(los primeros suelen ser distintos; vacío = el del resto)</small></legend>
            <label class="campo">N° 1<input type="text" name="precio_1" inputmode="decimal" placeholder="Ej: 4500"></label>
            <label class="campo">N° 2<input type="text" name="precio_2" inputmode="decimal" placeholder="Ej: 9900"></label>
            <label class="campo">N° 3<input type="text" name="precio_3" inputmode="decimal"></label>
            <label class="campo">Resto<input type="text" name="precio" inputmode="decimal" placeholder="Ej: 19390" required></label>
        </fieldset>
        <div class="recargo-crear">
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="lleva_recargo" value="1" checked onchange="toggleRecargo(this)"> Lleva recargo interior</label>
            <label class="campo" style="margin:0">por unidad <small>(general: <?= precio(recargo_general()) ?>)</small><input type="text" name="recargo" inputmode="decimal" value="<?= e((string)recargo_general()) ?>"></label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
            <button class="btn btn-texto" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
            <button class="btn btn-primario" type="submit">Crear colección</button>
        </div>
    </form>
</dialog>
<script>
// Buscador: filtra mientras escribís (sin tildes ni mayúsculas); Enter abre la colección si queda una sola
(() => {
    const inp = document.getElementById('buscar-col'), cuenta = document.getElementById('buscar-cuenta'), vacio = document.getElementById('buscar-vacio');
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const filas = [...document.querySelectorAll('#tabla-cols tr[data-busca]')].map(tr => [tr, norm(tr.dataset.busca)]);
    const filtrar = () => {
        const pal = norm(inp.value).split(/\s+/).filter(Boolean);
        let n = 0;
        filas.forEach(([tr, txt]) => { const ok = pal.every(p => txt.includes(p)); tr.hidden = !ok; if (ok) n++; });
        cuenta.textContent = pal.length ? `${n} de ${filas.length}` : `${filas.length} colecciones`;
        vacio.hidden = n > 0;
    };
    inp.addEventListener('input', filtrar);
    inp.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') { const vis = filas.filter(([tr]) => !tr.hidden); if (vis.length === 1) vis[0][0].click(); }
        if (ev.key === 'Escape') { inp.value = ''; filtrar(); }
    });
    if (inp.value) filtrar();
})();

// Sin recargo: el monto queda en 0 y bloqueado; al volver a tildar recupera el que tenía
function toggleRecargo(cb) {
    const inp = cb.closest('.recargo-crear').querySelector('input[name=recargo]');
    if (cb.checked) { inp.value = inp.dataset.antes || inp.defaultValue; inp.disabled = false; }
    else { inp.dataset.antes = inp.value; inp.value = '0'; inp.disabled = true; }
}
</script>

<?php admin_footer(); ?>
