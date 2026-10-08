<?php
require __DIR__ . '/inc/bootstrap.php';

$col = q("SELECT c.*, cat.nombre AS categoria, cat.slug AS categoria_slug
          FROM colecciones c LEFT JOIN categorias cat ON cat.id=c.categoria_id
          WHERE c.slug=? AND c.activa=1", [$_GET['c'] ?? ''])->fetch();
if (!$col) {
    http_response_code(404);
    $titulo = 'No encontrada';
    require __DIR__ . '/inc/header.php';
    echo '<div class="container vacio"><h3>Esta colección desapareció del mapa.</h3><p><a href="' . url('colecciones.php') . '">Volver a la base de colecciones</a></p></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

$items = q("SELECT * FROM items WHERE coleccion_id=? AND activo=1 ORDER BY numero", [$col['id']])->fetchAll();
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
        <div class="col-hero-izq">
            <?php if ($col['imagen']): ?>
                <img class="col-hero-img" src="<?= e(img($col['imagen'])) ?>" alt="<?= e($col['nombre']) ?>">
            <?php endif; ?>
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
        <div>
            <div class="crumbs">
                <a href="<?= url('colecciones.php') ?>">Colecciones</a>
                <?php if ($col['categoria']): ?> / <a href="<?= url('colecciones.php?cat=' . urlencode($col['categoria_slug'])) ?>"><?= e($col['categoria']) ?></a><?php endif; ?>
            </div>
            <h1><?= e($col['nombre']) ?></h1>
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
                        <?= $siguiendo ? '✓ En mi base' : '+ Sumar a mi base' ?>
                    </button>
                    <button class="btn btn-primario btn-chico" type="button" onclick="seleccionarFaltantes()">Seleccionar los que me faltan</button>
                    <button type="button" class="btn btn-texto btn-chico" data-suscripto="<?= $suscripto ? '1' : '0' ?>" data-coleccion="<?= (int)$col['id'] ?>" onclick="toggleSuscripcion(this)" title="<?= $suscripto ? 'Tocá para dejar de recibir avisos' : 'Te avisamos de números nuevos, reposiciones y lanzamientos' ?>">
                        <?= $suscripto ? '🔔 Suscripto a novedades' : '🔔 Avisarme novedades' ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$cli): ?>
                <div class="mi-progreso">
                    <strong>¿Ya tenés parte del equipo?</strong>
                    <p class="muted small" style="margin:4px 0 12px">Creá tu cuenta, pasá lista de los que ya tenés y salimos a buscar al resto.</p>
                    <a class="btn btn-teal btn-chico" href="<?= url('registro.php') ?>">Crear mi cuenta</a>
                    <a class="btn btn-texto" href="<?= url('ingresar.php') ?>">Ya tengo cuenta</a>
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
                $stock = disponible($it); ?>
                <article class="item" data-id="<?= (int)$it['id'] ?>" data-estado="<?= e($estado) ?>" data-stock="<?= $stock ?>" data-precio="<?= $p ?>">
                    <div class="item-img" <?php if ($it['imagen']): ?>style="background-image:url('<?= e(img($it['imagen'])) ?>')"<?php endif; ?>>
                        <span class="item-num"><?= num((int)$it['numero']) ?></span>
                        <span class="item-estado"><?= $chips[$estado] ?? '' ?></span>
                    </div>
                    <div class="item-body">
                        <h3 class="item-titulo"><?= e($it['titulo']) ?></h3>
                        <div class="item-precio">
                            <strong><?= precio($p) ?></strong>
                            <?php if ($p < (float)$it['precio']): ?><s><?= precio((float)$it['precio']) ?></s><?php endif; ?>
                        </div>
                        <div class="item-stock <?= $stock > 0 ? 'si' : 'no' ?>">
                            <?= $stock > 0 ? ($stock <= 3 ? "¡Quedan solo $stock!" : 'Disponible') : 'Sin stock · salimos a buscarlo' ?>
                        </div>
                        <div class="item-acciones">
                            <?php if ($socio): ?>
                                <label class="tengo-toggle"><input type="checkbox" <?= $estado === 'tengo' ? 'checked' : '' ?>> Lo tengo</label>
                                <label class="sel-toggle" <?= $estado !== 'falta' ? 'hidden' : '' ?>><input type="checkbox"> Lo quiero</label>
                            <?php endif; ?>
                            <?php if ($stock > 0): ?>
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

<?php require __DIR__ . '/inc/footer.php'; ?>
