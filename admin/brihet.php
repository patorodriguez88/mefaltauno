<?php
// Importar colecciones desde brihet.com.ar (ver inc/brihet.php). Se elige qué colecciones traer y se
// importan de a una (cada una baja sus fotos), con el avance en pantalla.
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/brihet.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) json_out(['ok' => false, 'error' => 'La sesión expiró: recargá la página.'], 419);
    try {
        if (($_POST['accion'] ?? '') === 'importar') {
            json_out(['ok' => true] + brihet_importar((int)$_POST['brihet_id'], (int)($_POST['destino'] ?? 0)));
        }
        if (($_POST['accion'] ?? '') === 'actualizar_todo') {
            $r = brihet_actualizar_todo();
            flash('ok', "Stock y precios de Brihet actualizados en {$r['actualizados']} números" . ($r['sin_dato'] ? " ({$r['sin_dato']} ya no están en Brihet: quedan sin stock virtual)" : '') . '.');
            redirect('admin/brihet.php');
        }
        if (($_POST['accion'] ?? '') === 'releer') {
            brihet_catalogo(true);
            flash('ok', 'Catálogo de Brihet actualizado.');
            redirect('admin/brihet.php');
        }
    } catch (Exception $e) {
        if (($_POST['accion'] ?? '') === 'importar') json_out(['ok' => false, 'error' => $e->getMessage()]);
        flash('error', $e->getMessage());
        redirect('admin/brihet.php');
    }
}

try {
    $cat = brihet_catalogo();
    $error = null;
} catch (Exception $e) {
    $cat = ['colecciones' => [], 'variantes' => [], 'leido' => null];
    $error = $e->getMessage();
}
$filas = [];
foreach ($cat['colecciones'] as $id => $p) {
    $vs = $cat['variantes'][$id] ?? [];
    $stock = array_filter($vs, fn($v) => !empty($v['is_in_stock']) && brihet_numero($v));
    if (!$stock) continue;
    $precios = array_map('brihet_precio', $stock);
    $web = brihet_coleccion_web($p);
    $filas[] = [
        'id' => (int)$id, 'nombre' => brihet_nombre($p), 'stock' => count($stock), 'total' => count($vs),
        'precio' => [min($precios), max($precios)], 'web' => $web,
        'en_web' => $web ? (int)q("SELECT COUNT(*) FROM items WHERE coleccion_id=?", [$web['id']])->fetchColumn() : 0,
        'foto' => $p['images'][0]['src'] ?? null,
    ];
}
usort($filas, fn($a, $b) => [(bool)$b['web'], $b['stock']] <=> [(bool)$a['web'], $a['stock']]);
$cols_web = q("SELECT id, nombre FROM colecciones ORDER BY nombre")->fetchAll();

admin_header('Importar desde Brihet', 'colecciones');
?>

<p><a href="<?= url('admin/colecciones.php') ?>">← Stock</a></p>
<div class="section-head">
    <h1 style="margin:0">Importar desde Brihet</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="accion" value="actualizar_todo"><button class="btn btn-primario btn-chico" type="submit" title="Relee Brihet y actualiza cantidad y precio de todos los números ya importados (no toca fotos ni agrega números)">Actualizar stock y precios</button></form>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="accion" value="releer"><button class="btn btn-linea btn-chico" type="submit">Volver a leer de Brihet</button></form>
    </div>
</div>
<div class="aviso" style="margin-bottom:16px;background:var(--teal-50);color:var(--teal-900)">
    Trae de <b>brihet.com.ar</b> las colecciones y <b>solo los números que tienen en stock</b>, con título, foto y <b>precio de Brihet tal cual</b> (su envío fijo se cobra aparte en el carrito como “Recargo interior”, ver Ajustes).
    Se publican; el stock lo sigue informando WePoint (mientras tanto se ven “Sin stock · Conseguímelo”).
    Si la colección ya existe en la web se completa: <b>no se pisa</b> lo que hayas cambiado a mano (título, foto o precio).
    Se puede volver a importar para traer números nuevos o precios actualizados.
    <?php if ($cat['leido']): ?><br><span class="small">Catálogo leído el <?= e(fecha($cat['leido'])) ?><?= ($cat['origen'] ?? '') === 'enviado' ? ' (mandado desde una PC de la oficina)' : '' ?>.</span><?php endif; ?>
    <?php if (brihet_bloqueado()): ?><br><span class="small"><b>Brihet bloquea a este servidor:</b> para traer catálogo, stock, precios y fotos nuevas, en una PC de la oficina correr <code>php tools/brihet-enviar.php</code> y después importar acá.</span><?php endif; ?>
</div>
<?php if ($error): ?><div class="aviso" style="background:var(--red-100);color:var(--red)"><?= e($error) ?></div><?php endif; ?>

<div class="tabla-wrap">
    <table class="tabla" id="tabla-brihet">
        <tr><th><input type="checkbox" id="todas" title="Todas"></th><th></th><th>Colección en Brihet</th><th>Con stock</th><th>Precio</th><th>En la web</th><th>Estado</th></tr>
        <?php foreach ($filas as $f): ?>
            <tr data-id="<?= $f['id'] ?>">
                <td><input type="checkbox" class="sel"></td>
                <td><?php if ($f['foto']): ?><img src="<?= e($f['foto']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:44px;height:44px;object-fit:cover;border-radius:8px"><?php endif; ?></td>
                <td><b><?= e($f['nombre']) ?></b></td>
                <td><?= $f['stock'] ?> <span class="muted small">de <?= $f['total'] ?></span></td>
                <td class="small"><?= precio($f['precio'][0]) ?><?= $f['precio'][1] > $f['precio'][0] ? ' a ' . precio($f['precio'][1]) : '' ?></td>
                <td>
                    <select class="destino" style="padding:6px 8px;font-size:.85rem;width:auto;max-width:230px">
                        <option value="0">➕ Crear colección nueva</option>
                        <?php foreach ($cols_web as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['web'] && (int)$f['web']['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($f['web']): ?><div class="small muted">ya tiene <?= $f['en_web'] ?> números</div><?php endif; ?>
                </td>
                <td class="estado small"><?= $f['web'] && $f['web']['brihet_id'] ? '<span class="badge badge-verde">Importada</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<div style="display:flex;gap:12px;align-items:center;margin-top:16px;flex-wrap:wrap">
    <button class="btn btn-primario" type="button" id="btn-importar">Importar seleccionadas</button>
    <span class="muted small" id="avance"></span>
</div>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
document.getElementById('todas').addEventListener('change', e => document.querySelectorAll('.sel').forEach(c => c.checked = e.target.checked));
document.getElementById('btn-importar').addEventListener('click', async ev => {
    const filas = [...document.querySelectorAll('#tabla-brihet tr[data-id]')].filter(tr => tr.querySelector('.sel').checked);
    if (!filas.length) return toast('Tildá al menos una colección.');
    ev.target.disabled = true;
    let hechas = 0;
    for (const tr of filas) {
        const est = tr.querySelector('.estado');
        est.innerHTML = '<span class="badge badge-amarillo">Importando…</span>';
        document.getElementById('avance').textContent = `Colección ${hechas + 1} de ${filas.length} (cada una baja sus fotos, puede tardar)…`;
        try {
            const body = new URLSearchParams({ csrf, accion: 'importar', brihet_id: tr.dataset.id, destino: tr.querySelector('.destino').value });
            const r = await (await fetch(location.pathname, { method: 'POST', body })).json();
            if (!r.ok) throw new Error(r.error || 'Error');
            est.innerHTML = `<span class="badge badge-verde">${r.nueva ? 'Creada' : 'Completada'}</span> ${r.creados} nuevos · ${r.actualizados} actualizados`
                + ` · <a href="<?= url('admin/coleccion.php?id=') ?>${r.coleccion_id}">ver</a>`;
            tr.querySelector('.sel').checked = false;
        } catch (e) {
            est.innerHTML = `<span class="badge badge-rojo">Error</span> ${e.message}`;
        }
        hechas++;
    }
    document.getElementById('avance').textContent = `Listo: ${hechas} colecciones procesadas.`;
    ev.target.disabled = false;
});
</script>

<?php admin_footer(); ?>
