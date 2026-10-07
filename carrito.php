<?php
require __DIR__ . '/inc/bootstrap.php';

$c = carrito_lineas();
foreach ($c['ajustes'] as $a) flash('info', "Ajustamos tu carrito por stock — $a");

$titulo = 'Carrito';
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <h1>Tu carrito</h1>

        <?php if (!$c['lineas']): ?>
            <div class="card vacio">
                <h3>Tu carrito está vacío</h3>
                <p>Entrá a una colección y sumá los números que te faltan.</p>
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Ver colecciones</a>
            </div>
        <?php else: ?>
            <div class="layout-2">
                <div class="card">
                    <?php foreach ($c['lineas'] as $l): $it = $l['item']; ?>
                        <div class="linea">
                            <img src="<?= e($it['imagen'] ?: $it['coleccion_imagen']) ?>" alt="" loading="lazy">
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
                                    <button type="button" aria-label="Agregar uno" onclick="cambiarCantidad(<?= (int)$it['id'] ?>, 1, <?= $l['cantidad'] ?>)" <?= $l['cantidad'] >= (int)$it['stock'] ? 'disabled' : '' ?>>+</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <aside class="card resumen">
                    <h3>Resumen</h3>
                    <div class="resumen-fila"><span>Subtotal</span><span><?= precio($c['subtotal']) ?></span></div>
                    <div class="resumen-fila muted"><span>Envío</span><span>A coordinar</span></div>
                    <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio($c['subtotal']) ?></span></div>
                    <a class="btn btn-primario btn-bloque" style="margin-top:16px" href="<?= url('checkout.php') ?>">Finalizar compra</a>
                    <a class="btn btn-texto btn-bloque" href="<?= url('colecciones.php') ?>">Seguir comprando</a>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
