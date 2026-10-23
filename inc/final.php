<?php // Final común de la tienda y del panel: toast, ventana de confirmación y scripts ($page_scripts) ?>
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
