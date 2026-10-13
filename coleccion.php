<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vistas.php';
require __DIR__ . '/inc/comics.php';

$col = q("SELECT c.*, cat.nombre AS categoria, cat.slug AS categoria_slug
          FROM colecciones c LEFT JOIN categorias cat ON cat.id=c.categoria_id
          WHERE c.slug=? AND c.activa=1", [$_GET['c'] ?? ''])->fetch();
if (!$col) {
    http_response_code(404);
    $titulo = 'No encontrada';
    require __DIR__ . '/inc/header.php';
    echo '<div class="container vacio"><h3>Esta colección desapareció del mapa.</h3><p><a href="' . url('colecciones.php') . '">Volver a las colecciones</a></p></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

$items = q("SELECT i.*, (SELECT COUNT(*) FROM item_fotos f WHERE f.item_id=i.id) AS fotos_extra FROM items i WHERE coleccion_id=? AND activo=1 ORDER BY numero", [$col['id']])->fetchAll();
$cli = cliente();
$op = es_admin($cli);           // el operador no tiene base propia
$socio = $cli && !$op;          // cliente logueado
$estados = $cli ? estados_items((int)$cli['id'], (int)$col['id']) : [];
$siguiendo = $cli && q("SELECT 1 FROM cliente_colecciones WHERE cliente_id=? AND coleccion_id=?", [$cli['id'], $col['id']])->fetchColumn();
$suscripto = suscripto($cli, (int)$col['id']);
$en_carrito = carrito();

$chips = [
    'tengo'     => '<span class="badge badge-verde">Lo tengo</span>',
    'buscando'  => '<span class="badge badge-amarillo">En búsqueda</span>',
    'en_camino' => '<span class="badge badge-azul">En camino</span>',
];

$titulo = $col['nombre'];
$descripcion = $col['bajada'];
require __DIR__ . '/inc/header.php';
?>

<section class="col-hero">
    <div class="container col-hero-grid">
        <?php
        // Ficha de la colección: lo que un coleccionista quiere ver de un vistazo
        $f_va = $items ? max(array_map(fn($i) => (int)$i['numero'], $items)) : 0;
        $f_total = max(coleccion_total($col) ?? count($items), $f_va);
        $f_24h = $f_enc = 0;
        foreach ($items as $i) {
            if (disponible($i) > 0) $f_24h++;
            elseif (disponible_encargue($i) > 0) $f_enc++;
        }
        $f_tengo = $socio ? count(array_filter($items, fn($i) => ($estados[$i['id']] ?? '') === 'tengo')) : 0;
        ?>
        <div class="col-hero-izq">
            <?php if ($col['imagen']): ?>
                <img class="col-hero-img" src="<?= e(img($col['imagen'])) ?>" alt="<?= e($col['nombre']) ?>">
            <?php endif; ?>
            <div class="ficha-col">
                <div class="ficha-titulo">Ficha de la colección</div>
                <div class="ficha-dato"><span class="ficha-ico">📚</span><small>Total</small><span><b><?= $f_total ?></b> números</span></div>
                <div class="ficha-dato"><span class="ficha-ico">🗓️</span><small><?= $col['proximamente'] ? 'Lanzamiento' : 'Va por el' ?></small><span><b><?= $col['proximamente'] ? e($col['lanzamiento'] ?: 'A confirmar') : ($f_va ? num($f_va) : '—') ?></b><?= !$col['proximamente'] && $f_va && $f_total > $f_va ? ' de ' . $f_total : '' ?></span></div>
                <?php if ($col['proximamente']): ?>
                    <div class="ficha-dato"><span class="ficha-ico">🚀</span><small>Anunciados</small><span><b><?= count($items) ?></b> números<em>todavía no se venden</em></span></div>
                <?php else: ?>
                    <div class="ficha-dato"><span class="ficha-ico">✅</span><small>Disponibles hoy</small><span><b><?= $f_24h + $f_enc ?></b> números<?php if ($f_24h + $f_enc): ?><em><?= $f_24h ? $f_24h . ' en 24 h' : '' ?><?= $f_24h && $f_enc ? ' · ' : '' ?><?= $f_enc ? $f_enc . ' por encargue' : '' ?></em><?php endif; ?></span></div>
                <?php endif; ?>
                <?php if ($socio): ?>
                    <div class="ficha-dato ficha-tuya"><span class="ficha-ico">🧩</span><small>Tu colección</small><span><b><?= $f_tengo ?></b> de <?= $f_total ?><em><?= $f_total - $f_tengo > 0 ? 'te faltan ' . ($f_total - $f_tengo) : '¡completa! 🏆' ?></em></span></div>
                <?php endif; ?>
            </div>
            <?php if (!$cli): ?>
                <div class="suscribir-mini">
                    <strong>🔔 Novedades de esta colección</strong>
                    <p class="muted small">Te avisamos de números nuevos y reposiciones.</p>
                    <form class="form-suscribir" data-coleccion="<?= (int)$col['id'] ?>" data-origen="coleccion">
                        <input type="email" name="email" placeholder="Tu email" required autocomplete="email">
                        <input type="text" name="web" class="trampa" tabindex="-1" autocomplete="off" aria-hidden="true">
                        <button class="btn btn-teal btn-chico" type="submit">Avisarme</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-hero-der">
            <div class="crumbs">
                <a href="<?= url('colecciones.php') ?>">Colecciones</a>
                <?php if ($col['categoria']): ?> / <a href="<?= url('colecciones.php?cat=' . urlencode($col['categoria_slug'])) ?>"><?= e($col['categoria']) ?></a><?php endif; ?>
            </div>
            <h1><?= e($col['nombre']) ?></h1>
            <?php if ($col['proximamente']): ?>
                <div class="proximamente-banner">🚀 <b>Próximamente<?= $col['lanzamiento'] ? ' · ' . e($col['lanzamiento']) : '' ?>.</b> Todavía no se vende: sumala a Mis colecciones y activá las novedades para enterarte primero.</div>
            <?php endif; ?>
            <div class="col-desc recortada" id="col-desc"><?= e($col['descripcion']) ?></div>
            <button class="btn-texto" type="button" data-vermas="col-desc">Ver más</button>

            <?php if ($op): ?>
                <div class="aviso-op">
                    <span>🛠️ Estás viendo la tienda como <b>operador</b>.</span>
                    <a class="btn btn-teal btn-chico" href="<?= url('admin/coleccion.php?id=' . (int)$col['id']) ?>">Editar colección</a>
                </div>
            <?php elseif ($socio): ?>
                <div class="mi-progreso">
                    <div class="progreso"><span id="prog-bar" style="width:0"></span></div>
                    <div class="progreso-txt"><span id="prog-txt"></span></div>
                </div>
                <div class="col-acciones">
                    <button class="btn btn-linea btn-chico" id="btn-seguir" type="button" data-siguiendo="<?= $siguiendo ? '1' : '0' ?>" onclick="toggleSeguir(this)">
                        <?= $siguiendo ? '✓ En Mis colecciones' : '+ Sumar a Mis colecciones' ?>
                    </button>
                    <button class="btn btn-primario btn-chico" type="button" onclick="seleccionarFaltantes()">Seleccionar los que me faltan</button>
                    <button type="button" class="btn btn-texto btn-chico" data-suscripto="<?= $suscripto ? '1' : '0' ?>" data-coleccion="<?= (int)$col['id'] ?>" onclick="toggleSuscripcion(this)" title="<?= $suscripto ? 'Tocá para dejar de recibir avisos' : 'Te avisamos de números nuevos, reposiciones y lanzamientos' ?>">
                        <?= $suscripto ? '🔔 Suscripto a novedades' : '🔔 Avisarme novedades' ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$cli): ?>
                <div class="col-acciones">
                    <a class="btn btn-linea btn-chico" href="<?= url('ingresar.php?volver=' . urlencode(url('coleccion.php?c=' . $col['slug']))) ?>">+ Sumar a Mis colecciones</a>
                    <span class="muted small">Ingresá para marcar los que ya tenés.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section" style="padding-top:28px">
    <div class="container">
        <?php if ($socio): ?>
            <div class="filtros" role="tablist">
                <button class="filtro activo" data-filtro="todos" type="button">Todos <span class="cnt" data-cnt="todos"></span></button>
                <button class="filtro" data-filtro="tengo" type="button">Los tengo <span class="cnt" data-cnt="tengo"></span></button>
                <button class="filtro" data-filtro="faltan" type="button">Me faltan <span class="cnt" data-cnt="faltan"></span></button>
            </div>
        <?php endif; ?>

        <div class="grid-items" id="grid-items" data-logueado="<?= $socio ? '1' : '0' ?>" data-coleccion="<?= (int)$col['id'] ?>">
            <?php foreach ($items as $it):
                $estado = $estados[$it['id']] ?? 'falta';
                $p = precio_item($it);
                $stock = disponible_total($it);
                [$hay, $txt_disp] = texto_disponibilidad($it); ?>
                <?php $ver_precio = $hay && !$col['proximamente']; // sin stock en ningún lado no sabemos el precio real ?>
                <article class="item" data-id="<?= (int)$it['id'] ?>" data-estado="<?= e($estado) ?>" data-stock="<?= $stock ?>" data-precio="<?= $ver_precio ? $p : 0 ?>">
                    <a class="item-img" href="<?= url_numero($col['slug'], (int)$it['numero']) ?>" <?php if ($it['imagen']): ?>style="background-image:url('<?= e(img($it['imagen'])) ?>')"<?php endif; ?>>
                        <span class="item-num"><?= num((int)$it['numero']) ?></span>
                        <span class="item-estado"><?= $chips[$estado] ?? '' ?></span>
                        <?php $hay_video = (bool)video_info($it['video_url']); if ($it['fotos_extra'] || $hay_video): ?><span class="item-media"><?= $it['fotos_extra'] ? '📷 ' . (1 + (int)$it['fotos_extra']) : '' ?><?= $hay_video ? ' ▶' : '' ?></span><?php endif; ?>
                    </a>
                    <div class="item-body">
                        <h3 class="item-titulo"><a href="<?= url_numero($col['slug'], (int)$it['numero']) ?>"><?= e($it['titulo']) ?></a></h3>
                        <div class="item-precio">
                            <?php if ($ver_precio): ?>
                                <strong><?= precio($p) ?></strong>
                                <?php if ($p < (float)$it['precio']): ?><s><?= precio((float)$it['precio']) ?></s><?php endif; ?>
                            <?php else: ?>
                                <span class="precio-confirmar">Precio a confirmar</span>
                            <?php endif; ?>
                        </div>
                        <?php $mi_res = $socio ? reserva_de((int)$it['id'], (int)$cli['id']) : null; ?>
                        <div class="item-stock <?= $stock > 0 && !$col['proximamente'] ? 'si' : 'no' ?>">
                            <?php if ($col['proximamente']): ?>Próximamente
                            <?php elseif ($mi_res): ?>🎯 Te lo guardamos hasta el <?= e(fecha($mi_res['vence_at'])) ?>
                            <?php else: ?><?= e($txt_disp) ?><?php endif; ?>
                        </div>
                        <div class="item-acciones">
                            <?php if ($socio): ?>
                                <label class="tengo-toggle"><input type="checkbox" <?= $estado === 'tengo' ? 'checked' : '' ?>> Lo tengo</label>
                                <label class="sel-toggle" <?= $estado !== 'falta' ? 'hidden' : '' ?>><input type="checkbox"> Lo quiero</label>
                            <?php endif; ?>
                            <?php if ($col['proximamente']): ?>
                                <span class="btn btn-linea btn-chico btn-bloque" aria-disabled="true" style="pointer-events:none;opacity:.7">Próximamente</span>
                            <?php elseif ($stock > 0): ?>
                                <button class="btn btn-teal btn-chico btn-bloque btn-carrito" type="button" onclick="agregarAlCarrito(<?= (int)$it['id'] ?>, this)">
                                    <?= isset($en_carrito[$it['id']]) ? 'Agregar otro' : 'Agregar al carrito' ?>
                                </button>
                            <?php elseif (!$cli): ?>
                                <a class="btn btn-linea btn-chico btn-bloque" href="<?= url('ingresar.php') ?>">Conseguímelo</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($socio): ?>
<div class="barra-sel" id="barra-sel">
    <div class="container">
        <div class="info"></div>
        <button class="btn btn-primario btn-chico" id="btn-sel-carrito" type="button" onclick="selAlCarrito(this)" hidden></button>
        <button class="btn btn-claro btn-chico" id="btn-sel-solicitar" type="button" onclick="selSolicitar(this)" hidden></button>
        <button class="btn-texto" type="button" style="color:#fff" onclick="limpiarSeleccion()">Cancelar</button>
    </div>
</div>
<?php endif; ?>

<?php $personaje = comic_personaje_coleccion($col['nombre'], $col['categoria_slug'] ?? null); ?>
<?= comic_cameo($personaje, 'der', '#grid-items') ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
