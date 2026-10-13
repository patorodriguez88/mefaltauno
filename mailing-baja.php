<?php
// Baja de las novedades por mail (link de cada campaña). No hace falta ingresar: el link va firmado.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vistas.php';
require __DIR__ . '/inc/marketing.php';

$email = strtolower(trim((string)($_GET['e'] ?? '')));
$valido = $email !== '' && hash_equals(mailing_token($email), (string)($_GET['t'] ?? ''));
if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    q("INSERT IGNORE INTO mailing_bajas (email) VALUES (?)", [$email]);
    q("UPDATE suscripciones SET activo=0, baja_at=NOW() WHERE email=? AND activo=1", [$email]);
    $hecha = true;
}

$titulo = 'No recibir más novedades';
require __DIR__ . '/inc/header.php';
?>
<section class="section">
    <div class="container" style="max-width:560px">
        <div class="card" style="text-align:center">
            <?php if (!$valido): ?>
                <h3>El link no es válido</h3>
                <p class="muted">Escribinos a <?= e(legal('email')) ?> y te damos de baja.</p>
            <?php elseif (!empty($hecha)): ?>
                <div class="modal-icono" style="margin:0 auto">👋</div>
                <h3>Listo, no te mandamos más novedades</h3>
                <p class="muted">Vas a seguir recibiendo solo los mails de tus pedidos y búsquedas. Si cambiás de idea, te suscribís de nuevo desde cualquier colección.</p>
            <?php else: ?>
                <h3>¿No querés recibir más novedades?</h3>
                <p class="muted">Dejamos de mandarle lanzamientos y llegadas a <b><?= e($email) ?></b>. Los mails de tus pedidos y búsquedas te siguen llegando.</p>
                <form method="post"><?= csrf_field() ?><button class="btn btn-primario" type="submit">Sí, darme de baja</button></form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
