<?php
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    try {
        $v = [
            'activa'     => !empty($_POST['activa']) ? '1' : '0',
            'frecuencia' => ($_POST['frecuencia'] ?? '') === 'siempre' ? 'siempre' : 'una_vez',
            'hasta'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hasta'] ?? '') ? $_POST['hasta'] : '',
        ];
        // Las presentaciones llegan en el orden en que están en la pantalla (se pueden mover y quitar)
        $slides = [];
        foreach ((array)($_POST['slides'] ?? []) as $k => $p) {
            $n = count($slides) + 1;
            $s = [
                'video'           => trim(mb_substr($p['video'] ?? '', 0, 300)),
                'imagen'          => !empty($p['quitar_imagen']) ? '' : (string)($p['imagen_actual'] ?? ''),
                'kicker'          => trim(mb_substr($p['kicker'] ?? '', 0, 60)),
                'titulo'          => trim(mb_substr($p['titulo'] ?? '', 0, 120)),
                'texto'           => trim(mb_substr($p['texto'] ?? '', 0, 300)),
                'boton_texto'     => trim(mb_substr($p['boton_texto'] ?? '', 0, 40)),
                'boton_coleccion' => trim($p['boton_coleccion'] ?? ''),
                'boton_url'       => trim(mb_substr($p['boton_url'] ?? '', 0, 300)),
            ];
            if ($s['imagen'] !== '' && !str_starts_with($s['imagen'], 'uploads/')) $s['imagen'] = '';
            $archivo = isset($_FILES['imagen']['name'][$k]) ? ['name' => $_FILES['imagen']['name'][$k], 'tmp_name' => $_FILES['imagen']['tmp_name'][$k],
                        'error' => $_FILES['imagen']['error'][$k], 'size' => $_FILES['imagen']['size'][$k]] : null;
            if ($subida = subir_imagen($archivo, 'ventana', 'ventana-inicio-' . $n)) $s['imagen'] = $subida;
            if ($s['titulo'] === '' && $s['video'] === '' && $s['imagen'] === '') continue;   // presentación vacía: se descarta
            if ($s['titulo'] === '') throw new Exception("A la presentación $n le falta el título.");
            if ($s['video'] !== '' && youtube_id($s['video']) === '') throw new Exception("Presentación $n: el video tiene que ser un link de YouTube (youtube.com/watch?v=… o youtu.be/…).");
            if ($s['boton_url'] !== '' && !preg_match('#^(https?://|/)#', $s['boton_url'])) throw new Exception("Presentación $n: el link del botón tiene que empezar con https:// o con /.");
            $slides[] = $s;
        }
        if (count($slides) > VENTANA_MAX_SLIDES) throw new Exception('Como máximo ' . VENTANA_MAX_SLIDES . ' presentaciones.');
        if ($v['activa'] === '1' && !$slides) throw new Exception('Para mostrar la ventana cargá al menos una presentación con título.');
        foreach ($v as $k => $valor) q("REPLACE INTO ajustes (clave, valor) VALUES (?, ?)", ['ventana_' . $k, $valor]);
        q("REPLACE INTO ajustes (clave, valor) VALUES ('ventana_slides', ?)", [json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $n = count($slides);
        flash('ok', $v['activa'] === '1' ? "Ventana de inicio guardada con $n presentaci" . ($n === 1 ? 'ón' : 'ones') . ': ya aparece en la home.' : 'Ventana de inicio guardada (apagada).');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/ventana.php');
}

$v = ventana_ajustes();
$slides = $v['slides'] ?: [array_fill_keys(VENTANA_SLIDE_CAMPOS, '')];
$colecciones = q("SELECT slug, nombre, proximamente FROM colecciones WHERE activa=1 ORDER BY nombre")->fetchAll();
$vencida = $v['hasta'] !== '' && $v['hasta'] < date('Y-m-d');

// Formulario de una presentación. $k: clave estable del bloque (los campos y la imagen van juntos aunque se muevan)
function ventana_form_slide(string $k, array $s, array $colecciones): string {
    $yt = youtube_id($s['video']);
    $fondo = $s['imagen'] !== '' ? url($s['imagen']) : ($yt ? "https://img.youtube.com/vi/$yt/hqdefault.jpg" : '');
    $n = "slides[$k]";
    ob_start(); ?>
    <fieldset class="card form slide-form" data-k="<?= e($k) ?>">
        <div class="slide-cabeza">
            <h3 style="margin:0">Presentación <span class="slide-num"></span></h3>
            <div class="slide-botones">
                <button type="button" class="btn btn-linea btn-chico" data-mover="-1" title="Subir">↑</button>
                <button type="button" class="btn btn-linea btn-chico" data-mover="1" title="Bajar">↓</button>
                <button type="button" class="btn btn-peligro btn-chico" data-quitar>Quitar</button>
            </div>
        </div>
        <div class="slide-cuerpo">
            <div>
                <label class="campo">Video de YouTube <small>(se ve sin sonido y en loop)</small>
                    <input type="url" name="<?= $n ?>[video]" value="<?= e($s['video']) ?>" placeholder="https://www.youtube.com/watch?v=…"></label>
                <label class="campo">Imagen <small>(si no hay video o mientras carga; vacía = la del video de YouTube)</small>
                    <input type="file" name="imagen[<?= e($k) ?>]" accept="image/jpeg,image/png,image/webp"></label>
                <input type="hidden" name="<?= $n ?>[imagen_actual]" value="<?= e($s['imagen']) ?>">
                <?php if ($s['imagen'] !== ''): ?><label class="tengo-toggle small" style="color:var(--ink)"><input type="checkbox" name="<?= $n ?>[quitar_imagen]" value="1"> Quitar la imagen actual</label><?php endif; ?>
                <label class="campo">Etiqueta <small>(arriba del título, chica)</small>
                    <input type="text" name="<?= $n ?>[kicker]" maxlength="60" value="<?= e($s['kicker']) ?>" placeholder="Nuevo lanzamiento"></label>
                <label class="campo">Título
                    <input type="text" name="<?= $n ?>[titulo]" maxlength="120" value="<?= e($s['titulo']) ?>" placeholder="¿Estás preparado, coleccionista?"></label>
                <label class="campo">Texto
                    <textarea name="<?= $n ?>[texto]" maxlength="300" rows="3"><?= e($s['texto']) ?></textarea></label>
                <div class="form-row">
                    <label class="campo">Texto del botón
                        <input type="text" name="<?= $n ?>[boton_texto]" maxlength="40" value="<?= e($s['boton_texto']) ?>" placeholder="Quiero ser piloto"></label>
                    <label class="campo">Lleva a la colección
                        <select name="<?= $n ?>[boton_coleccion]">
                            <option value="">— Un link (abajo) —</option>
                            <?php foreach ($colecciones as $c): ?>
                                <option value="<?= e($c['slug']) ?>" <?= $s['boton_coleccion'] === $c['slug'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['proximamente'] ? ' (próximamente)' : '' ?></option>
                            <?php endforeach; ?>
                        </select></label>
                </div>
                <label class="campo">…o a este link <small>(si no elegís colección)</small>
                    <input type="text" name="<?= $n ?>[boton_url]" maxlength="300" value="<?= e($s['boton_url']) ?>" placeholder="https://… o /colecciones.php?cat=modelismo"></label>
            </div>
            <div class="ventana-mini" style="<?= $fondo ? 'background-image:url(\'' . e($fondo) . '\')' : '' ?>">
                <div class="ventana-mini-texto">
                    <?php if ($s['kicker'] !== ''): ?><span><?= e($s['kicker']) ?></span><?php endif; ?>
                    <b><?= e($s['titulo'] ?: 'Título de la presentación') ?></b>
                    <?php if ($s['texto'] !== ''): ?><small><?= e(mb_strimwidth($s['texto'], 0, 110, '…')) ?></small><?php endif; ?>
                    <?php if ($s['boton_texto'] !== ''): ?><i><?= e($s['boton_texto']) ?></i><?php endif; ?>
                </div>
            </div>
        </div>
    </fieldset>
    <?php return ob_get_clean();
}

admin_header('Ventana de inicio', 'ventana');
?>
<div class="section-head">
    <h1 style="margin:0">Ventana de inicio</h1>
    <a class="btn btn-linea" href="<?= url('?ventana=ver') ?>" target="_blank" rel="noopener">Ver cómo queda ↗</a>
</div>
<p class="muted" style="max-width:760px;margin-top:-8px">La presentación grande que se abre al entrar a la tienda, con un video de YouTube de fondo (sin sonido y en loop) o una imagen. Podés cargar <b>varias</b>: se pasan con flechas y cada visita arranca por la siguiente. Mientras está activa reemplaza a la ventana de novedades.</p>

<?php if ($v['activa'] === '1' && $vencida): ?>
    <div class="aviso" style="margin-bottom:16px">La ventana está activa pero venció el <?= e(date('d/m/Y', strtotime($v['hasta']))) ?>: no se muestra. Cambiá la fecha o dejala vacía.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-ventana">
    <?= csrf_field() ?>
    <div class="card form" style="margin-bottom:16px">
        <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $v['activa'] === '1' ? 'checked' : '' ?>> <b>Mostrar la ventana al entrar a la tienda</b></label>
        <div class="form-row">
            <label class="campo">Frecuencia
                <select name="frecuencia">
                    <option value="una_vez" <?= $v['frecuencia'] !== 'siempre' ? 'selected' : '' ?>>Una vez por visitante (vuelve a aparecer si cambiás algo)</option>
                    <option value="siempre" <?= $v['frecuencia'] === 'siempre' ? 'selected' : '' ?>>En cada visita a la tienda</option>
                </select></label>
            <label class="campo">Mostrar hasta <small>(opcional: después se apaga sola)</small>
                <input type="date" name="hasta" value="<?= e($v['hasta']) ?>"></label>
        </div>
    </div>

    <div id="slides">
        <?php foreach ($slides as $i => $s) echo ventana_form_slide('s' . $i, $s, $colecciones); ?>
    </div>
    <template id="slide-nueva"><?= ventana_form_slide('__K__', array_fill_keys(VENTANA_SLIDE_CAMPOS, ''), $colecciones) ?></template>

    <div class="slide-pie">
        <button type="button" class="btn btn-linea" id="slide-agregar">+ Agregar presentación</button>
        <button class="btn btn-primario" type="submit">Guardar</button>
    </div>
</form>

<script>
// Presentaciones: agregar, mover y quitar sin perder lo cargado (cada bloque conserva su clave)
(() => {
    const cont = document.getElementById('slides'), max = <?= VENTANA_MAX_SLIDES ?>;
    const numerar = () => {
        const bloques = [...cont.querySelectorAll('.slide-form')];
        bloques.forEach((b, i) => {
            b.querySelector('.slide-num').textContent = bloques.length > 1 ? i + 1 : '';
            b.querySelector('[data-mover="-1"]').disabled = i === 0;
            b.querySelector('[data-mover="1"]').disabled = i === bloques.length - 1;
        });
        document.getElementById('slide-agregar').hidden = bloques.length >= max;
    };
    document.getElementById('slide-agregar').addEventListener('click', () => {
        const k = 'n' + Date.now();
        cont.insertAdjacentHTML('beforeend', document.getElementById('slide-nueva').innerHTML.replaceAll('__K__', k));
        numerar();
        cont.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    cont.addEventListener('click', async ev => {
        const b = ev.target.closest('.slide-form');
        if (!b) return;
        if (ev.target.matches('[data-mover]')) {
            const otro = +ev.target.dataset.mover < 0 ? b.previousElementSibling : b.nextElementSibling;
            if (otro) +ev.target.dataset.mover < 0 ? cont.insertBefore(b, otro) : cont.insertBefore(otro, b);
            numerar();
        }
        if (ev.target.matches('[data-quitar]')) {
            const ok = await modal({ titulo: '¿Quitar esta presentación?', cuerpo: '<p style="margin:0">Se borra al tocar “Guardar”.</p>', ok: 'Quitar' });
            if (ok === null) return;
            b.remove();
            if (!cont.querySelector('.slide-form')) document.getElementById('slide-agregar').click();
            numerar();
        }
    });
    numerar();
})();
</script>

<?php admin_footer(); ?>
