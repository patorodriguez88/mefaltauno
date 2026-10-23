<?php // Avisos flotantes (flash) de la tienda y del panel ?>
<?php $fl = flashes(); if ($fl): ?>
<div class="avisos" role="status" aria-live="polite">
    <?php foreach ($fl as $f):
        $icono = ['ok' => '⚡', 'error' => '⚠️', 'info' => '🦸'][$f['tipo']] ?? '🦸'; ?>
        <div class="aviso-flotante aviso-<?= e($f['tipo']) ?>" data-auto="<?= $f['tipo'] === 'error' ? '0' : '1' ?>">
            <span class="aviso-icono"><?= $icono ?></span>
            <span class="aviso-msg"><?= e($f['msg']) ?></span>
            <button type="button" class="aviso-cerrar" aria-label="Cerrar" onclick="cerrarAviso(this.parentNode)">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
