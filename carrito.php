<?php
require __DIR__ . '/inc/bootstrap.php';

// Aplicar / quitar código de descuento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    if (($_POST['accion'] ?? '') === 'quitar_cupon') {
        carrito_set_cupon(null);
    } else {
        $codigo = strtoupper(trim($_POST['cupon'] ?? ''));
        $c = carrito_lineas();
        try {
            if ($codigo === '') throw new Exception('Escribí el código.');
            $v = cupon_validar($codigo, cliente(), $c['subtotal']);
            carrito_set_cupon($codigo);
            flash('ok', 'Código ' . $codigo . ' aplicado: ' . cupon_etiqueta($v['cupon']) . ' ⚡');
        } catch (Exception $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('carrito.php');
}

$c = carrito_lineas();
foreach ($c['ajustes'] as $a) flash('info', "Ajustamos tu carrito por stock — $a");
if ($c['cupon_error']) {
    flash('error', 'El código ' . carrito_cupon() . ' no se aplicó: ' . $c['cupon_error']);
    carrito_set_cupon(null);
}

$titulo = 'Carrito';
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <h1>Tu carrito</h1>
        <?php if ($c['lineas']): ?><p class="muted" style="margin-top:-8px">El equipo está casi listo. Solo falta confirmar.</p><?php endif; ?>

        <?php if (!$c['lineas']): ?>
            <div class="card vacio">
                <h3>Tu carrito está vacío… por ahora</h3>
                <p>Toda leyenda empieza con un primer número. Elegí una colección y reclutá al que te falta.</p>
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Explorar colecciones</a>
            </div>
        <?php else: ?>
            <div class="layout-2">
                <div class="card">
                    <?php foreach ($c['lineas'] as $l): $it = $l['item']; ?>
                        <div class="linea">
                            <img src="<?= e(img($it['imagen'] ?: $it['coleccion_imagen'])) ?>" alt="" loading="lazy">
                            <div>
                                <div class="linea-sub"><a href="<?= url('coleccion.php?c=' . urlencode($it['coleccion_slug'])) ?>"><?= e($it['coleccion']) ?></a> · <?= num((int)$it['numero']) ?></div>
                                <div class="linea-titulo"><?= e($it['titulo']) ?></div>
                                <div class="linea-sub"><?= precio($l['precio']) ?> c/u</div>
                            </div>
                            <div class="linea-der">
                                <strong><?= precio($l['total']) ?></strong>
                                <div class="cant">
                                    <button type="button" aria-label="Quitar uno" onclick="cambiarCantidad(<?= (int)$it['id'] ?>, -1, <?= $l['cantidad'] ?>)">−</button>
                                    <span><?= $l['cantidad'] ?></span>
                                    <button type="button" aria-label="Agregar uno" onclick="cambiarCantidad(<?= (int)$it['id'] ?>, 1, <?= $l['cantidad'] ?>)" <?= $l['cantidad'] >= $l['disponible'] ? 'disabled' : '' ?>>+</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <aside class="card resumen">
                    <h3>Resumen</h3>
                    <div class="resumen-fila"><span>Subtotal</span><span><?= precio($c['subtotal']) ?></span></div>
                    <?php if ($c['cupon']): ?>
                        <div class="resumen-fila descuento">
                            <span>Código <b><?= e($c['cupon']['codigo']) ?></b> <small>(<?= e(cupon_etiqueta($c['cupon'])) ?>)</small></span>
                            <span>−<?= precio($c['descuento']) ?></span>
                        </div>
                        <form method="post" style="text-align:right;margin:-4px 0 4px"><?= csrf_field() ?><input type="hidden" name="accion" value="quitar_cupon"><button class="btn-texto small" type="submit">Quitar código</button></form>
                    <?php else: ?>
                        <form method="post" class="cupon-form">
                            <?= csrf_field() ?>
                            <input type="text" name="cupon" placeholder="¿Tenés un código?" aria-label="Código de descuento" autocomplete="off" style="text-transform:uppercase">
                            <button class="btn btn-linea btn-chico" type="submit">Aplicar</button>
                        </form>
                    <?php endif; ?>
                    <div class="resumen-fila muted"><span>Retiro</span><span>Sin cargo</span></div>
                    <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio($c['total']) ?></span></div>
                    <a class="btn btn-primario btn-bloque" style="margin-top:16px" href="<?= url('checkout.php') ?>">Finalizar compra</a>
                    <a class="btn btn-texto btn-bloque" href="<?= url('colecciones.php') ?>">Seguir comprando</a>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
