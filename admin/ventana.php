<?php
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/subidas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    try {
        $v = [
            'activa'          => !empty($_POST['activa']) ? '1' : '0',
            'video'           => trim(mb_substr($_POST['video'] ?? '', 0, 300)),
            'kicker'          => trim(mb_substr($_POST['kicker'] ?? '', 0, 60)),
            'titulo'          => trim(mb_substr($_POST['titulo'] ?? '', 0, 120)),
            'texto'           => trim(mb_substr($_POST['texto'] ?? '', 0, 300)),
            'boton_texto'     => trim(mb_substr($_POST['boton_texto'] ?? '', 0, 40)),
            'boton_coleccion' => trim($_POST['boton_coleccion'] ?? ''),
            'boton_url'       => trim(mb_substr($_POST['boton_url'] ?? '', 0, 300)),
            'frecuencia'      => ($_POST['frecuencia'] ?? '') === 'siempre' ? 'siempre' : 'una_vez',
            'hasta'           => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hasta'] ?? '') ? $_POST['hasta'] : '',
        ];
        if ($v['video'] !== '' && youtube_id($v['video']) === '') throw new Exception('El video tiene que ser un link de YouTube (youtube.com/watch?v=… o youtu.be/…).');
        if ($v['boton_url'] !== '' && !preg_match('#^(https?://|/)#', $v['boton_url'])) throw new Exception('El link del botón tiene que empezar con https:// o con /.');
        if ($v['activa'] === '1' && $v['titulo'] === '') throw new Exception('Para mostrar la ventana falta el título.');
        $imagen = subir_imagen($_FILES['imagen'] ?? null, 'ventana', 'ventana-inicio');
        if ($imagen) $v['imagen'] = $imagen;
        if (!empty($_POST['quitar_imagen'])) $v['imagen'] = '';
        foreach ($v as $k => $valor) q("REPLACE INTO ajustes (clave, valor) VALUES (?, ?)", ['ventana_' . $k, $valor]);
        flash('ok', $v['activa'] === '1' ? 'Ventana de inicio guardada: ya aparece en la home.' : 'Ventana de inicio guardada (apagada).');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/ventana.php');
}

$v = ventana_ajustes();
$yt = youtube_id($v['video']);
$fondo = $v['imagen'] !== '' ? url($v['imagen']) : ($yt ? "https://img.youtube.com/vi/$yt/hqdefault.jpg" : '');
$colecciones = q("SELECT slug, nombre, proximamente FROM colecciones WHERE activa=1 ORDER BY nombre")->fetchAll();
$vencida = $v['hasta'] !== '' && $v['hasta'] < date('Y-m-d');

admin_header('Ventana de inicio', 'ventana');
?>
<div class="section-head">
    <h1 style="margin:0">Ventana de inicio</h1>
    <a class="btn btn-linea" href="<?= url('?ventana=ver') ?>" target="_blank" rel="noopener">Ver cómo queda ↗</a>
</div>
<p class="muted" style="max-width:720px;margin-top:-8px">La presentación grande que se abre al entrar a la tienda, con un video de YouTube de fondo (sin sonido y en loop) o una imagen. Mientras está activa reemplaza a la ventana de novedades.</p>

<?php if ($v['activa'] === '1' && $vencida): ?>
    <div class="aviso" style="margin-bottom:16px">La ventana está activa pero venció el <?= e(date('d/m/Y', strtotime($v['hasta']))) ?>: no se muestra. Cambiá la fecha o dejala vacía.</div>
<?php endif; ?>

<div class="layout-2">
    <form method="post" class="card form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activa" value="1" <?= $v['activa'] === '1' ? 'checked' : '' ?>> <b>Mostrar la ventana al entrar a la tienda</b></label>

        <h3 style="margin-top:12px">Fondo</h3>
        <label class="campo">Video de YouTube <small>(link del video: se ve sin sonido y en loop)</small>
            <input type="url" name="video" value="<?= e($v['video']) ?>" placeholder="https://www.youtube.com/watch?v=…"></label>
        <label class="campo">Imagen <small>(se ve mientras carga el video, si no hay video o si el visitante no quiere animaciones; JPG, PNG o WEBP)</small>
            <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp"></label>
        <?php if ($v['imagen'] !== ''): ?>
            <label class="tengo-toggle small" style="color:var(--ink)"><input type="checkbox" name="quitar_imagen" value="1"> Quitar la imagen actual</label>
        <?php endif; ?>

        <h3 style="margin-top:12px">Texto</h3>
        <label class="campo">Etiqueta <small>(arriba del título, chica)</small>
            <input type="text" name="kicker" maxlength="60" value="<?= e($v['kicker']) ?>" placeholder="Nuevo lanzamiento"></label>
        <label class="campo">Título
            <input type="text" name="titulo" maxlength="120" value="<?= e($v['titulo']) ?>" placeholder="¿Estás preparado, coleccionista?"></label>
        <label class="campo">Texto
            <textarea name="texto" maxlength="300" rows="3" placeholder="Hoy te convertís en piloto: armá el RB19 de Oracle Red Bull Racing a escala 1/8, número a número."><?= e($v['texto']) ?></textarea></label>

        <h3 style="margin-top:12px">Botón</h3>
        <label class="campo">Texto del botón
            <input type="text" name="boton_texto" maxlength="40" value="<?= e($v['boton_texto']) ?>" placeholder="Quiero ser piloto"></label>
        <label class="campo">Lleva a la colección
            <select name="boton_coleccion">
                <option value="">— Un link (abajo) —</option>
                <?php foreach ($colecciones as $c): ?>
                    <option value="<?= e($c['slug']) ?>" <?= $v['boton_coleccion'] === $c['slug'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['proximamente'] ? ' (próximamente)' : '' ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="campo">…o a este link <small>(si no elegís colección)</small>
            <input type="text" name="boton_url" maxlength="300" value="<?= e($v['boton_url']) ?>" placeholder="https://… o /colecciones.php?cat=modelismo"></label>

        <h3 style="margin-top:12px">Cuándo aparece</h3>
        <label class="campo">Frecuencia
            <select name="frecuencia">
                <option value="una_vez" <?= $v['frecuencia'] !== 'siempre' ? 'selected' : '' ?>>Una vez por visitante (vuelve a aparecer si cambiás la ventana)</option>
                <option value="siempre" <?= $v['frecuencia'] === 'siempre' ? 'selected' : '' ?>>En cada visita a la tienda</option>
            </select></label>
        <label class="campo">Mostrar hasta <small>(opcional: después se apaga sola)</small>
            <input type="date" name="hasta" value="<?= e($v['hasta']) ?>"></label>

        <button class="btn btn-primario" type="submit">Guardar</button>
    </form>

    <aside>
        <div class="card">
            <h3>Vista previa</h3>
            <div class="ventana-mini" style="<?= $fondo ? 'background-image:url(\'' . e($fondo) . '\')' : '' ?>">
                <div class="ventana-mini-texto">
                    <?php if ($v['kicker'] !== ''): ?><span><?= e($v['kicker']) ?></span><?php endif; ?>
                    <b><?= e($v['titulo'] ?: 'Título de la ventana') ?></b>
                    <?php if ($v['texto'] !== ''): ?><small><?= e(mb_strimwidth($v['texto'], 0, 110, '…')) ?></small><?php endif; ?>
                    <?php if ($v['boton_texto'] !== ''): ?><i><?= e($v['boton_texto']) ?></i><?php endif; ?>
                </div>
            </div>
            <p class="small muted" style="margin:10px 0 0"><?= $yt ? '▶ Con video de fondo.' : 'Sin video: se ve la imagen.' ?> Para verla en movimiento, <a href="<?= url('?ventana=ver') ?>" target="_blank" rel="noopener">abrí la tienda</a>.</p>
        </div>
    </aside>
</div>

<?php admin_footer(); ?>
