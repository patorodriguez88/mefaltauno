</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <img src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="160" height="54" loading="lazy">
            <p><b style="color:#fff">Ningún héroe queda atrás.</b><br>Cada colección merece su final épico.</p>
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

<dialog class="modal" id="modal" aria-labelledby="modal-titulo">
    <form method="dialog" class="modal-caja">
        <button class="modal-cerrar" value="cancelar" aria-label="Cerrar">&times;</button>
        <div class="modal-icono" id="modal-icono" hidden></div>
        <h3 id="modal-titulo"></h3>
        <div class="modal-cuerpo" id="modal-cuerpo"></div>
        <label class="campo modal-texto" id="modal-texto-wrap" hidden>
            <span id="modal-texto-label"></span>
            <textarea id="modal-texto" maxlength="2000"></textarea>
        </label>
        <div class="modal-acciones">
            <button class="btn btn-texto" value="cancelar" id="modal-cancelar">Cancelar</button>
            <button class="btn btn-primario" value="ok" id="modal-ok">Aceptar</button>
        </div>
    </form>
</dialog>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<?php if (!empty($page_scripts)) foreach ($page_scripts as $s): ?>
<script src="<?= preg_match('#^https?://#', $s) ? e($s) : asset($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
