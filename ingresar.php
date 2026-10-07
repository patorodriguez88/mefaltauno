<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth_volver.php';

if (cliente()) redirect('cuenta.php');

$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $email = trim($_POST['email'] ?? '');
    $c = q("SELECT * FROM clientes WHERE email=?", [$email])->fetch();
    // Freno simple a la fuerza bruta: 5 intentos fallidos por sesión cada 10 minutos
    $intentos = array_filter($_SESSION['login_fallos'] ?? [], fn($t) => $t > time() - 600);
    if (count($intentos) >= 5) {
        flash('error', 'Demasiados intentos. Esperá unos minutos y probá de nuevo.');
    } elseif ($c && password_verify($_POST['password'] ?? '', $c['password_hash'])) {
        unset($_SESSION['login_fallos']);
        login_cliente($c);
        header('Location: ' . destino_post_login());
        exit;
    } else {
        $intentos[] = time();
        $_SESSION['login_fallos'] = $intentos;
        flash('error', 'Email o contraseña incorrectos.');
    }
    $_SESSION['volver_a'] = $_POST['volver'] ?? '';
    redirect('ingresar.php');
}

$titulo = 'Ingresar';
require __DIR__ . '/inc/header.php';
?>

<div class="container auth-wrap">
    <div class="card">
        <h1 style="font-size:1.8rem">Hola de nuevo 👋</h1>
        <p class="muted">Ingresá para ver tus colecciones y los números que te faltan.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="<?= e(volver_actual()) ?>">
            <label class="campo">Email<input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email"></label>
            <label class="campo">Contraseña<input type="password" name="password" required autocomplete="current-password"></label>
            <button class="btn btn-primario btn-bloque" type="submit">Ingresar</button>
        </form>
        <p class="small muted" style="margin:20px 0 0;text-align:center">¿No tenés cuenta? <a href="<?= url('registro.php' . (volver_actual() ? '?volver=' . urlencode(volver_actual()) : '')) ?>">Creala en un minuto</a></p>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
