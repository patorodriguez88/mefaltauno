</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <img src="<?= asset('assets/img/logo.jpg') ?>" alt="MeFaltaUno" width="150" height="62" loading="lazy">
            <p>Colecciones para grandes. Si te falta uno, lo conseguimos.</p>
        </div>
        <div>
            <h4>Tienda</h4>
            <a href="<?= url('colecciones.php') ?>">Todas las colecciones</a>
            <?php foreach (categorias() as $cat): ?>
                <a href="<?= url('colecciones.php?cat=' . urlencode($cat['slug'])) ?>"><?= e($cat['nombre']) ?></a>
            <?php endforeach; ?>
        </div>
        <div>
            <h4>Tu cuenta</h4>
            <a href="<?= url('cuenta.php') ?>">Mis colecciones</a>
            <a href="<?= url('cuenta.php?tab=pedidos') ?>">Mis pedidos</a>
            <a href="<?= url('me-falta.php') ?>">Pedir un número que me falta</a>
        </div>
    </div>
    <div class="container footer-legal">&copy; <?= date('Y') ?> MeFaltaUno · Dinter S.A.</div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<?php if (!empty($page_scripts)) foreach ($page_scripts as $s): ?>
<script src="<?= asset($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
