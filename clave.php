<?php
// Elegir contraseña desde el link del mail (invitación de operador u "olvidé mi contraseña", inc/claves.php).
// Al guardarla queda adentro: el operador va al panel y el cliente a su cuenta.
require __DIR__ . '/inc/bootstrap.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$link = clave_link_vigente($token);

if ($link && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $clave = (string)($_POST['password'] ?? '');
    if (mb_strlen($clave) < 8) {
        flash('error', 'La contraseña tiene que tener al menos 8 caracteres.');
    } elseif ($clave !== ($_POST['password2'] ?? '')) {
        flash('error', 'Las dos contraseñas no coinciden.');
    } else {
        clave_guardar((int)$link['cliente_id'], $clave);
        $c = q("SELECT * FROM clientes WHERE id=?", [$link['cliente_id']])->fetch();
        login_cliente($c);
        flash('ok', $link['tipo'] === 'invitacion' ? '¡Bienvenido/a al equipo! Ya podés usar el panel.' : 'Listo, ya tenés tu contraseña nueva.');
        redirect(es_admin($c) ? 'admin/' : 'cuenta.php');
    }
    redirect('clave.php?t=' . $token);
}

$titulo = 'Elegí tu contraseña';
require __DIR__ . '/inc/header.php';
?>

<div class="container auth-wrap">
    <?php require __DIR__ . '/inc/auth_panel.php'; ?>
    <div class="auth-form">
        <?php if (!$link): ?>
            <h1>Este link ya no sirve</h1>
            <p class="muted">Venció o ya se usó. Pedí uno nuevo y te llega al mail.</p>
            <p><a class="btn btn-primario" href="<?= url('olvide.php') ?>">Pedir un link nuevo</a></p>
        <?php else: ?>
            <h1><?= $link['tipo'] === 'invitacion' ? 'Bienvenido/a al equipo' : 'Elegí tu contraseña nueva' ?></h1>
            <p class="muted">Para <b><?= e($link['email']) ?></b>. Mínimo 8 caracteres.</p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <label class="campo">Contraseña<input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
                <label class="campo">Repetila<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
                <button class="btn btn-primario btn-bloque" type="submit">Guardar y entrar</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
