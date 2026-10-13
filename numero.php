<?php
// Página de un número: galería de fotos, video, precio y compra. URL: numero.php?c=<slug colección>&n=<número>
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vistas.php';

$operador = es_admin();
// El operador lo ve aunque no esté publicado (vista previa); el público solo lo publicado
$it = q("SELECT i.*, c.nombre AS coleccion, c.slug, c.id AS col_id, c.proximamente, c.lanzamiento, c.imagen AS coleccion_imagen, c.activa
         FROM items i JOIN colecciones c ON c.id=i.coleccion_id
         WHERE c.slug=? AND i.numero=?" . ($operador ? '' : ' AND i.activo=1 AND c.activa=1'), [$_GET['c'] ?? '', (int)($_GET['n'] ?? 0)])->fetch();
if (!$it) {
    http_response_code(404);
    $titulo = 'No encontrado';
    require __DIR__ . '/inc/header.php';
    echo '<div class="container vacio"><h3>Este número desapareció del mapa.</h3><p><a href="' . url('colecciones.php') . '">Volver a las colecciones</a></p></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

$cli = cliente();
$socio = $cli && !es_admin($cli);
$fotos = fotos_item($it);
$video = video_info($it['video_url']);
$p = precio_item($it);
$stock = disponible_total($it);
[$hay, $txt_disp] = texto_disponibilidad($it);
$puede = comprable($it);
$mi_res = $socio ? reserva_de((int)$it['id'], (int)$cli['id']) : null;
$en_carrito = carrito()[$it['id']] ?? 0;
$vecinos = q("SELECT numero, titulo FROM items WHERE coleccion_id=? AND activo=1 AND numero IN (?, ?) ORDER BY numero", [$it['col_id'], $it['numero'] - 1, $it['numero'] + 1])->fetchAll();

$titulo = $it['coleccion'] . ' ' . num((int)$it['numero']) . ' — ' . $it['titulo'];
$descripcion = $titulo . '. ' . ($stock > 0 ? 'Disponible en MeFaltaUno.' : 'Pedilo en MeFaltaUno y salimos a buscarlo.');
require __DIR__ . '/inc/header.php';
?>

<section class="section" style="padding-top:24px">
    <div class="container">
        <?php if (!$it['activo'] || !$it['activa']): ?>
            <div class="aviso" style="margin-bottom:12px">👁️ <b>Vista previa:</b> <?= !$it['activa'] ? 'la colección no está publicada' : 'este número no está publicado' ?>. Solo lo ves vos como operador. <a href="<?= url('admin/numero.php?id=' . (int)$it['id']) ?>">Fotos y video</a></div>
        <?php endif; ?>
        <div class="crumbs small" style="margin-bottom:12px">
            <a href="<?= url('colecciones.php') ?>">Colecciones</a> / <a href="<?= url('coleccion.php?c=' . urlencode($it['slug'])) ?>"><?= e($it['coleccion']) ?></a>
        </div>
        <div class="numero-grid">
            <div class="numero-galeria">
                <div class="numero-foto" id="foto-grande">
                    <?= foto($fotos[0] ?? $it['coleccion_imagen'], '📦') ?>
                    <span class="item-num"><?= num((int)$it['numero']) ?></span>
                </div>
                <p class="foto-aviso">* Imagen ilustrativa: puede no coincidir con la edición real.</p>
                <?php if (count($fotos) > 1 || $video): ?>
                    <div class="numero-miniaturas">
                        <?php foreach ($fotos as $k => $f): ?>
                            <button type="button" class="<?= $k ? '' : 'activa' ?>" data-src="<?= e(img($f)) ?>" aria-label="Foto <?= $k + 1 ?>"><img src="<?= e(img($f)) ?>" alt="" loading="lazy"></button>
                        <?php endforeach; ?>
                        <?php if ($video && $video[0] === 'youtube'): ?>
                            <button type="button" class="mini-video" data-video="<?= e($video[1]) ?>" aria-label="Ver video"><img src="<?= e($video[2]) ?>" alt="" loading="lazy"><span>▶</span></button>
                        <?php elseif ($video): ?>
                            <a class="mini-video ig" href="<?= e($video[1]) ?>" target="_blank" rel="noopener" aria-label="Ver video en Instagram"><span>▶</span><small>Instagram</small></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="numero-info">
                <div class="kicker"><?= e($it['coleccion']) ?></div>
                <h1><?= num((int)$it['numero']) ?> · <?= e($it['titulo']) ?></h1>
                <div class="item-precio numero-precio">
                    <?php if ($hay && !$it['proximamente']): ?>
                        <strong><?= precio($p) ?></strong>
                        <?php if ($p < (float)$it['precio']): ?><s><?= precio((float)$it['precio']) ?></s><?php endif; ?>
                    <?php else: ?>
                        <span class="precio-confirmar">Precio a confirmar</span>
                    <?php endif; ?>
                </div>

                <?php if ($it['proximamente']): ?>
                    <div class="proximamente-banner">🚀 <b>Próximamente<?= $it['lanzamiento'] ? ' · ' . e($it['lanzamiento']) : '' ?>.</b> Todavía no se vende.</div>
                <?php elseif ($mi_res): ?>
                    <div class="item-stock si">🎯 Te lo guardamos hasta el <?= e(fecha($mi_res['vence_at'])) ?></div>
                <?php else: ?>
                    <div class="item-stock <?= $hay ? 'si' : 'no' ?>"><?= e($txt_disp) ?></div>
                    <?php if ($hay && !disponible($it)): ?><p class="muted small" style="margin:0">Lo pedimos al editor y te contactamos para coordinar la entrega.</p><?php endif; ?>
                <?php endif; ?>

                <div class="numero-acciones">
                    <?php if ($it['proximamente']): ?>
                        <a class="btn btn-linea" href="<?= url('coleccion.php?c=' . urlencode($it['slug'])) ?>">Seguir la colección</a>
                    <?php elseif ($puede > 0 && $it['activo'] && $it['activa']): ?>
                        <button class="btn btn-primario btn-carrito" type="button" onclick="agregarAlCarrito(<?= (int)$it['id'] ?>, this)"><?= $en_carrito ? 'Agregar otro' : 'Agregar al carrito' ?></button>
                    <?php elseif ($stock > 0): ?>
                        <div class="aviso"><?= e(motivo_tope($it, $puede)) ?></div>
                    <?php else: ?>
                        <a class="btn btn-teal" href="<?= url($cli ? 'me-falta.php?coleccion=' . (int)$it['col_id'] : 'ingresar.php') ?>">Conseguímelo</a>
                    <?php endif; ?>
                    <a class="btn btn-texto" href="<?= url('coleccion.php?c=' . urlencode($it['slug'])) ?>">Ver toda la colección</a>
                </div>


                <?php if ($vecinos): ?>
                    <div class="numero-vecinos">
                        <?php foreach ($vecinos as $v): ?>
                            <a href="<?= url_numero($it['slug'], (int)$v['numero']) ?>"><?= (int)$v['numero'] < (int)$it['numero'] ? '← ' : '' ?><?= num((int)$v['numero']) ?> · <?= e($v['titulo']) ?><?= (int)$v['numero'] > (int)$it['numero'] ? ' →' : '' ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
// Galería: tocar una miniatura la muestra grande; la del video lo reproduce en el mismo lugar
const grande = document.getElementById('foto-grande');
const fotoInicial = grande.querySelector('img, .foto-vacia')?.outerHTML || '';
document.querySelectorAll('.numero-miniaturas button').forEach(b => b.addEventListener('click', () => {
    grande.querySelector('.video-en-foto')?.remove();
    if (b.dataset.video) {
        const v = document.createElement('div');
        v.className = 'video-en-foto';
        v.innerHTML = `<iframe src="${b.dataset.video}" title="Video" allow="autoplay; accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
        grande.appendChild(v);
    } else {
        let img = grande.querySelector('img');
        if (!img) { grande.insertAdjacentHTML('afterbegin', fotoInicial); img = grande.querySelector('img'); }
        if (img) img.src = b.dataset.src;
    }
    document.querySelectorAll('.numero-miniaturas button').forEach(x => x.classList.toggle('activa', x === b));
}));
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
