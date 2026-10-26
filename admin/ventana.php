<?php
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    try {
        $v = [
            'activa'     => !empty($_POST['activa']) ? '1' : '0',
            'frecuencia' => ($_POST['frecuencia'] ?? '') === 'siempre' ? 'siempre' : 'una_vez',
            'hasta'      => '',   // la fecha ahora va en cada presentación
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
                'hasta'           => preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['hasta'] ?? '') ? $p['hasta'] : '',
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

// Formulario de una presentación. $k: clave estable del bloque (los campos y la imagen van juntos aunque se muevan)
function ventana_form_slide(string $k, array $s, array $colecciones): string {
    $yt = youtube_id($s['video']);
    $fondo = $s['imagen'] !== '' ? url($s['imagen']) : ($yt ? "https://img.youtube.com/vi/$yt/hqdefault.jpg" : '');
    $n = "slides[$k]";
    ob_start(); ?>
    <fieldset class="card form slide-form" data-k="<?= e($k) ?>" data-fondo="<?= e($fondo) ?>" hidden>
        <div class="slide-cabeza">
            <h3 style="margin:0">Presentación <span class="slide-num"></span></h3>
            <div class="slide-botones">
                <button type="button" class="btn btn-linea btn-chico" data-mover="-1" title="Mostrarla antes">← Antes</button>
                <button type="button" class="btn btn-linea btn-chico" data-mover="1" title="Mostrarla después">Después →</button>
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
                <label class="campo">Mostrar hasta <small>(opcional: después de esa fecha deja de aparecer sola)</small>
                    <input type="date" name="<?= $n ?>[hasta]" value="<?= e($s['hasta']) ?>"></label>
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

<?php if ($v['activa'] === '1' && !ventana_preparar($v)['slides']): ?>
    <div class="aviso" style="margin-bottom:16px">La ventana está activa pero todas las presentaciones vencieron: no se muestra nada. Cambiá alguna fecha o dejala vacía.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-ventana">
    <?= csrf_field() ?>
    <div class="card form" style="margin-bottom:16px">
        <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $v['activa'] === '1' ? 'checked' : '' ?>> <b>Mostrar la ventana al entrar a la tienda</b></label>
        <label class="campo">Frecuencia
            <select name="frecuencia">
                <option value="una_vez" <?= $v['frecuencia'] !== 'siempre' ? 'selected' : '' ?>>Una vez por visitante (vuelve a aparecer si cambiás algo)</option>
                <option value="siempre" <?= $v['frecuencia'] === 'siempre' ? 'selected' : '' ?>>En cada visita a la tienda</option>
            </select></label>
    </div>

    <p class="small muted" style="margin:0 0 8px">En la tienda se muestran en este orden. Tocá una para editarla.</p>
    <div class="slides-tira" id="slides-tira"></div>

    <div id="slides">
        <?php foreach ($slides as $i => $s) echo ventana_form_slide('s' . $i, $s, $colecciones); ?>
    </div>
    <template id="slide-nueva"><?= ventana_form_slide('__K__', array_fill_keys(VENTANA_SLIDE_CAMPOS, ''), $colecciones) ?></template>

    <div class="slide-pie">
        <button class="btn btn-primario" type="submit">Guardar</button>
    </div>
</form>

<script>
// Presentaciones: arriba una miniatura de cada una (en el orden en que se muestran); tocar una abre su ficha abajo.
// Agregar, mover y quitar sin perder lo cargado (cada ficha conserva su clave). Las miniaturas siguen lo que se escribe.
(() => {
    const cont = document.getElementById('slides'), tira = document.getElementById('slides-tira'), max = <?= VENTANA_MAX_SLIDES ?>;
    const hoy = '<?= date('Y-m-d') ?>';
    const fichas = () => [...cont.querySelectorAll('.slide-form')];
    let elegida = null;
    const val = (f, campo) => (f.querySelector(`[name$="[${campo}]"]`) || {}).value || '';
    const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));   // app.js carga después
    const fecha = d => d ? d.split('-').reverse().join('/') : '';
    const pintar = () => {
        tira.innerHTML = '';
        fichas().forEach((f, i) => {
            f.querySelector('.slide-num').textContent = i + 1;
            f.querySelector('[data-mover="-1"]').disabled = i === 0;
            f.querySelector('[data-mover="1"]').disabled = i === fichas().length - 1;
            const hasta = val(f, 'hasta'), vencida = hasta && hasta < hoy;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'slide-mini' + (f === elegida ? ' activa' : '') + (vencida ? ' vencida' : '');
            b.innerHTML = `<div class="ventana-mini"${f.dataset.fondo ? ` style="background-image:url('${f.dataset.fondo.replace(/'/g, '%27')}')"` : ''}>
                <div class="ventana-mini-texto">${val(f, 'kicker') ? `<span>${esc(val(f, 'kicker'))}</span>` : ''}<b>${esc(val(f, 'titulo') || 'Sin título')}</b>${val(f, 'boton_texto') ? `<i>${esc(val(f, 'boton_texto'))}</i>` : ''}</div></div>
                <div class="slide-mini-pie"><b>${i + 1}</b> · ${hasta ? (vencida ? 'Venció el ' : 'Hasta el ') + fecha(hasta) : 'Sin vencimiento'}</div>`;
            b.addEventListener('click', () => elegir(f));
            tira.appendChild(b);
        });
        if (fichas().length < max) {
            const mas = document.createElement('button');
            mas.type = 'button';
            mas.className = 'slide-mini slide-mini-mas';
            mas.innerHTML = '<span>+</span>Agregar presentación';
            mas.addEventListener('click', agregar);
            tira.appendChild(mas);
        }
    };
    const elegir = (f) => {
        elegida = f;
        fichas().forEach(x => x.hidden = x !== f);
        pintar();
    };
    const agregar = () => {
        const k = 'n' + Date.now();
        cont.insertAdjacentHTML('beforeend', document.getElementById('slide-nueva').innerHTML.replaceAll('__K__', k));
        elegir(cont.lastElementChild);
        cont.lastElementChild.querySelector('[name$="[titulo]"]').focus();
    };
    cont.addEventListener('input', pintar);
    cont.addEventListener('change', ev => {
        // Imagen elegida: se ve en la miniatura antes de guardar
        if (ev.target.type === 'file' && ev.target.files[0]) { ev.target.closest('.slide-form').dataset.fondo = URL.createObjectURL(ev.target.files[0]); }
        pintar();
    });
    cont.addEventListener('click', async ev => {
        const f = ev.target.closest('.slide-form');
        if (!f) return;
        if (ev.target.matches('[data-mover]')) {
            const otro = +ev.target.dataset.mover < 0 ? f.previousElementSibling : f.nextElementSibling;
            if (otro) +ev.target.dataset.mover < 0 ? cont.insertBefore(f, otro) : cont.insertBefore(otro, f);
            pintar();
        }
        if (ev.target.matches('[data-quitar]')) {
            const ok = await modal({ titulo: '¿Quitar esta presentación?', cuerpo: '<p style="margin:0">Se borra al tocar “Guardar”.</p>', ok: 'Quitar' });
            if (ok === null) return;
            const sig = f.nextElementSibling || f.previousElementSibling;
            f.remove();
            sig ? elegir(sig) : agregar();
        }
    });
    elegir(fichas()[0]);
})();
</script>

<?php admin_footer(); ?>
