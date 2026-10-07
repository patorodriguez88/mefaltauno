<?php
// Baja de una suscripción con el link del mail (sin necesidad de ingresar).
require __DIR__ . '/inc/bootstrap.php';

$s = q("SELECT s.*, c.nombre AS coleccion FROM suscripciones s LEFT JOIN colecciones c ON c.id=s.coleccion_id WHERE s.token=?", [(string)($_GET['t'] ?? '')])->fetch();
if ($s && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    q("UPDATE suscripciones SET activo=0, baja_at=NOW() WHERE id=?", [$s['id']]);
    $s['activo'] = 0;
    $hecha = true;
}

$titulo = 'Darme de baja';
require __DIR__ . '/inc/header.php';
?>
<div class="container" style="max-width:560px;margin:48px auto">
    <div class="card" style="text-align:center">
        <?php if (!$s): ?>
            <h1 style="font-size:1.8rem">Link inválido</h1>
            <p class="muted">Este link de baja no existe o ya no es válido.</p>
        <?php elseif (!empty($hecha) || !$s['activo']): ?>
            <h1 style="font-size:1.8rem">Listo, te diste de baja</h1>
            <p class="muted">Ya no vas a recibir novedades de <b><?= e($s['coleccion'] ?: 'MeFaltaUno') ?></b>. Si cambiás de idea, podés volver a suscribirte cuando quieras.</p>
            <a class="btn btn-linea" href="<?= url() ?>">Volver a la web</a>
        <?php else: ?>
            <h1 style="font-size:1.8rem">¿Te das de baja?</h1>
            <p class="muted">Vas a dejar de recibir novedades de <b><?= e($s['coleccion'] ?: 'MeFaltaUno') ?></b> en <?= e($s['email']) ?>.</p>
            <form method="post"><?= csrf_field() ?><button class="btn btn-primario" type="submit">Sí, darme de baja</button></form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
