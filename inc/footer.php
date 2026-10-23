</main>

<div class="franja-epica" aria-hidden="true">
    <div class="franja-pista">
        <?php for ($vuelta = 0; $vuelta < 2; $vuelta++): foreach (FRASES_EPICAS as $f): ?>
            <span><?= e($f) ?></span><i>★</i>
        <?php endforeach; endfor; ?>
    </div>
</div>

<footer class="site-footer">
    <div class="container footer-liga">
        <div>
            <h3>Sumate a la liga</h3>
            <p>Lanzamientos, reposiciones y búsquedas épicas, directo a tu mail.</p>
        </div>
        <form class="form-suscribir form-liga" data-origen="footer">
            <?php if (!cliente()): ?><input type="email" name="email" placeholder="Tu email" required autocomplete="email" aria-label="Tu email"><?php endif; ?>
            <input type="text" name="web" class="trampa" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="btn btn-primario" type="submit">Quiero sumarme</button>
        </form>
    </div>
    <div class="container footer-grid">
        <div>
            <img src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="160" height="54" loading="lazy">
            <p><b style="color:#fff">Ningún héroe queda atrás.</b><br>Cada colección merece su final épico.</p>
        </div>
        <div>
            <h4>Tienda</h4>
            <a href="<?= url('colecciones.php') ?>">Todas las colecciones</a>
            <?php foreach (categorias() as $nav_cat): ?>
                <a href="<?= url('colecciones.php?cat=' . urlencode($nav_cat['slug'])) ?>"><?= e($nav_cat['nombre']) ?></a>
            <?php endforeach; ?>
        </div>
        <div>
            <?php if (es_admin()): ?>
                <h4>Operador</h4>
                <a href="<?= url('admin/') ?>">Panel</a>
                <a href="<?= url('admin/pedidos.php') ?>">Pedidos</a>
                <a href="<?= url('admin/faltantes.php') ?>">Faltantes</a>
            <?php else: ?>
                <h4>Tu cuenta</h4>
                <a href="<?= url('cuenta.php') ?>">Mis colecciones</a>
                <a href="<?= url('cuenta.php?tab=pedidos') ?>">Mis pedidos</a>
                <a href="<?= url('me-falta.php') ?>">Pedir un número que me falta</a>
            <?php endif; ?>
        </div>
        <div>
            <h4>Ayuda y legales</h4>
            <a href="<?= url('terminos.php') ?>">Términos y condiciones</a>
            <a href="<?= url('privacidad.php') ?>">Privacidad y cookies</a>
            <a class="link-consumidor" href="<?= e(URL_DEFENSA_CONSUMIDOR) ?>" target="_blank" rel="noopener">Defensa de las y los consumidores. Para reclamos ingrese aquí</a>
            <a class="btn-arrepentimiento" href="<?= url('arrepentimiento.php') ?>">Botón de arrepentimiento</a>
        </div>
    </div>
    <div class="container footer-legal">&copy; <?= date('Y') ?> MeFaltaUno · <?= e(legal('razon_social')) ?><?= legal('cuit') ? ' · CUIT ' . e(legal('cuit')) : '' ?><?= legal('domicilio') ? ' · ' . e(legal('domicilio')) : '' ?></div>
</footer>

<div class="aviso-cookies" id="aviso-cookies" role="region" aria-label="Aviso de cookies" hidden>
    <span>🍪 Usamos solo cookies necesarias para que funcionen tu sesión y tu carrito. <a href="<?= url('privacidad.php#cookies') ?>">Más info</a></span>
    <button class="btn btn-primario btn-chico" type="button">Entendido</button>
</div>
<?php require __DIR__ . '/final.php'; ?>
