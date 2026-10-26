<?php
// "¿Olvidaste tu contraseña?": manda por mail un link para elegir una nueva (inc/claves.php).
// Responde lo mismo exista o no la cuenta, así no se puede averiguar qué mails están registrados.
require __DIR__ . '/inc/bootstrap.php';

if (cliente()) redirect('cuenta.php');

$enviado = false;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $email = trim($_POST['email'] ?? '');
    // Freno simple: 3 pedidos por sesión cada 15 minutos
    $pedidos = array_filter($_SESSION['olvide_pedidos'] ?? [], fn ($t) => $t > time() - 900);
    if (count($pedidos) >= 3) {
        flash('error', 'Ya pediste varios links. Revisá tu correo (también el spam) o esperá unos minutos.');
        redirect('olvide.php');
    }
    $pedidos[] = time();
    $_SESSION['olvide_pedidos'] = $pedidos;
    $c = filter_var($email, FILTER_VALIDATE_EMAIL) ? q("SELECT id, nombre, email FROM clientes WHERE email=?", [$email])->fetch() : null;
    if ($c) clave_mail_recuperar($c);
    $enviado = true;
}

$titulo = 'Olvidé mi contraseña';
require __DIR__ . '/inc/header.php';
?>

<div class="container auth-wrap">
    <?php require __DIR__ . '/inc/auth_panel.php'; ?>
    <div class="auth-form">
        <h1>¿Olvidaste tu contraseña?</h1>
        <?php if ($enviado): ?>
            <p>Si <b><?= e($email) ?></b> tiene una cuenta, te mandamos un mail con un link para elegir una contraseña nueva. Vence en <?= CLAVES_HORAS['recuperar'] ?> horas.</p>
            <p class="small muted">¿No llega? Revisá el correo no deseado o <a href="<?= url('olvide.php') ?>">pedilo de nuevo</a>.</p>
        <?php else: ?>
            <p class="muted">Poné el mail con el que te registraste y te mandamos un link para elegir una nueva.</p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label class="campo">Email<input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email"></label>
                <button class="btn btn-primario btn-bloque" type="submit">Mandarme el link</button>
            </form>
        <?php endif; ?>
        <p class="small muted" style="margin:20px 0 0;text-align:center"><a href="<?= url('ingresar.php') ?>">← Volver a ingresar</a></p>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
