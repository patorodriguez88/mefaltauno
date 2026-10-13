<?php
// Fotos y video de un número: la foto principal (la de la tabla de la colección) + hasta FOTOS_EXTRA_MAX
// fotos más, y un link de video (YouTube / Instagram). Se ven en la página del número en la tienda.
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';
require_once dirname(__DIR__) . '/inc/vistas.php';

$id = (int)($_GET['id'] ?? 0);
$it = q("SELECT i.*, c.nombre AS coleccion, c.slug, c.id AS col_id, c.recargo AS coleccion_recargo FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.id=?", [$id])->fetch();
if (!$it) redirect('admin/colecciones.php');
$extras = q("SELECT * FROM item_fotos WHERE item_id=? ORDER BY orden, id", [$id])->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acc = $_POST['accion'] ?? '';
    try {
        $prefijo = $it['slug'] . '-' . (int)$it['numero'];
        if ($acc === 'principal_subir') {
            $f = subir_imagen($_FILES['foto'] ?? null, 'items', $prefijo);
            if (!$f) throw new Exception('Elegí una foto.');
            q("UPDATE items SET imagen=?, foto_origen='manual' WHERE id=?", [$f, $id]);
            flash('ok', 'Foto principal actualizada.');
        } elseif ($acc === 'extra_subir') {
            $n = 0;
            $files = $_FILES['fotos'] ?? null;
            foreach (($files['name'] ?? []) as $k => $_) {
                if (count($extras) + $n >= FOTOS_EXTRA_MAX) break;
                $f = subir_imagen(['name' => $files['name'][$k], 'tmp_name' => $files['tmp_name'][$k], 'error' => $files['error'][$k], 'size' => $files['size'][$k]], 'items', $prefijo);
                if (!$f) continue;
                q("INSERT INTO item_fotos (item_id, imagen, orden) VALUES (?,?,?)", [$id, $f, count($extras) + $n + 1]);
                $n++;
            }
            if (!$n) throw new Exception('Elegí al menos una foto.');
            flash('ok', $n === 1 ? 'Se agregó 1 foto.' : "Se agregaron $n fotos.");
        } elseif ($acc === 'extra_borrar') {
            q("DELETE FROM item_fotos WHERE id=? AND item_id=?", [(int)$_POST['foto_id'], $id]);
            flash('ok', 'Foto quitada.');
        } elseif ($acc === 'extra_principal') {
            // La extra pasa a principal y la principal anterior queda como extra
            $f = q("SELECT * FROM item_fotos WHERE id=? AND item_id=?", [(int)$_POST['foto_id'], $id])->fetch();
            if (!$f) throw new Exception('Esa foto ya no está.');
            q("UPDATE items SET imagen=?, foto_origen='manual' WHERE id=?", [$f['imagen'], $id]);
            if ($it['imagen']) q("UPDATE item_fotos SET imagen=? WHERE id=?", [$it['imagen'], $f['id']]);
            else q("DELETE FROM item_fotos WHERE id=?", [$f['id']]);
            flash('ok', 'Foto principal cambiada.');
        } elseif ($acc === 'recargo') {
            $v = trim((string)($_POST['recargo'] ?? ''));
            q("UPDATE items SET recargo=? WHERE id=?", [$v === '' ? null : max(0, (float)str_replace(['.', ','], ['', '.'], $v)), $id]);
            flash('ok', $v === '' ? 'Este número usa el recargo de la colección / general.' : 'Recargo de este número guardado.');
        } elseif ($acc === 'video') {
            $url = trim((string)($_POST['video_url'] ?? ''));
            if ($url !== '' && !video_info($url)) throw new Exception('Ese link no es un video de YouTube o Instagram. Pegá el link del video (ej: https://www.youtube.com/watch?v=…).');
            q("UPDATE items SET video_url=? WHERE id=?", [$url ?: null, $id]);
            flash('ok', $url ? 'Video guardado.' : 'Video quitado.');
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/numero.php?id=' . $id);
}

$video = video_info($it['video_url']);
admin_header('Fotos y video · ' . $it['coleccion'] . ' ' . num((int)$it['numero']), 'colecciones');
?>

<p><a href="<?= url('admin/coleccion.php?id=' . (int)$it['col_id']) ?>">← <?= e($it['coleccion']) ?></a> · <a href="<?= url_numero($it['slug'], (int)$it['numero']) ?>" target="_blank">Ver en la tienda ↗</a>
    <?php if ($it['foto_origen'] !== 'manual'): ?> · <a href="<?= url('admin/salvat.php?coleccion=' . (int)$it['col_id'] . '&todas=1') ?>">📷 Buscar foto en Salvat</a><?php endif; ?></p>
<div class="section-head">
    <h1 style="margin:0"><?= e($it['coleccion']) ?> <?= num((int)$it['numero']) ?> <span class="muted" style="font-size:1rem;font-weight:400">· <?= e($it['titulo']) ?></span></h1>
</div>

<div class="layout-2">
    <div class="card">
        <h3>Fotos</h3>
        <p class="muted small">La principal es la que se ve en la colección. Las demás aparecen en la página del número (hasta <?= FOTOS_EXTRA_MAX ?> más).</p>
        <div class="galeria-admin">
            <figure class="galeria-foto principal">
                <?= foto($it['imagen'], '📷') ?>
                <figcaption>Principal</figcaption>
                <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="accion" value="principal_subir">
                    <label class="btn-texto">Cambiar<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" hidden onchange="this.form.submit()"></label></form>
            </figure>
            <?php foreach ($extras as $f): ?>
                <figure class="galeria-foto">
                    <?= foto($f['imagen']) ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>">
                        <button class="btn-texto" name="accion" value="extra_principal">Hacer principal</button>
                        <button class="btn-texto" name="accion" value="extra_borrar" style="color:var(--red)" onclick="return confirm('¿Quitar esta foto?')">Quitar</button>
                    </form>
                </figure>
            <?php endforeach; ?>
            <?php if (count($extras) < FOTOS_EXTRA_MAX): ?>
                <form method="post" enctype="multipart/form-data" class="galeria-foto agregar"><?= csrf_field() ?><input type="hidden" name="accion" value="extra_subir">
                    <label><span>＋</span>Agregar fotos<small>hasta <?= FOTOS_EXTRA_MAX - count($extras) ?> más</small>
                        <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple hidden onchange="this.form.submit()"></label>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="accion" value="video">
        <h3>Video</h3>
        <label class="campo">Link de YouTube o Instagram
            <input type="url" name="video_url" value="<?= e($it['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=…">
        </label>
        <p class="muted small" style="margin:0">Subí el video a YouTube (puede ser “no listado”) o a Instagram y pegá el link. Así carga rápido en el celular y no ocupa el servidor.</p>
        <?php if ($video && $video[0] === 'youtube'): ?>
            <div class="video-marco"><iframe src="<?= e(str_replace('&autoplay=1', '', $video[1])) ?>" title="Video" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>
        <?php elseif ($video): ?>
            <a class="btn btn-linea btn-chico" href="<?= e($video[1]) ?>" target="_blank" rel="noopener">Ver video en Instagram ↗</a>
        <?php elseif ($it['video_url']): ?>
            <div class="aviso">El link guardado no es un video de YouTube o Instagram, así que no se muestra en la tienda. Reemplazalo o borralo.</div>
        <?php endif; ?>
        <button class="btn btn-primario" type="submit">Guardar video</button>
    </form>
    <form method="post" class="card form" style="margin-top:0">
        <?= csrf_field() ?><input type="hidden" name="accion" value="recargo">
        <h3>Recargo interior</h3>
        <?php $base = $it['coleccion_recargo'] !== null ? (float)$it['coleccion_recargo'] : recargo_general(); ?>
        <label class="campo">Por unidad, solo para este número <small>(vacío = <?= precio($base) ?>, <?= $it['coleccion_recargo'] !== null ? 'el de la colección' : 'el general' ?>)</small>
            <input type="text" name="recargo" inputmode="decimal" value="<?= $it['recargo'] !== null ? e((float)$it['recargo']) : '' ?>" placeholder="<?= e((string)$base) ?>"></label>
        <button class="btn btn-linea btn-chico" type="submit">Guardar recargo</button>
    </form>
</div>

<?php admin_footer(); ?>
