<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth_volver.php';

if (cliente()) redirect('cuenta.php');

$d = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    foreach ($d as $k => $_) $d[$k] = trim($_POST[$k] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($d['nombre'] === '') $errores[] = 'Ingresá tu nombre.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no es válido.';
    if (strlen($pass) < 8) $errores[] = 'La contraseña tiene que tener al menos 8 caracteres.';
    if (!$errores && q("SELECT 1 FROM clientes WHERE email=?", [$d['email']])->fetchColumn()) {
        $errores[] = 'Ya hay una cuenta con ese email. ¿Querés ingresar?';
    }
    if (!$errores) {
        q("INSERT INTO clientes (nombre, apellido, email, telefono, password_hash) VALUES (?,?,?,?,?)",
          [$d['nombre'], $d['apellido'], $d['email'], $d['telefono'] ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        login_cliente(q("SELECT * FROM clientes WHERE id=?", [db()->lastInsertId()])->fetch());
        flash('ok', '¡Bienvenido/a, ' . $d['nombre'] . '! Entrá a una colección y marcá los números que ya tenés.');
        header('Location: ' . destino_post_login());
        exit;
    }
}

$titulo = 'Crear cuenta';
require __DIR__ . '/inc/header.php';
?>

<div class="container auth-wrap">
    <div class="card">
        <h1 style="font-size:1.8rem">Armá tu colección</h1>
        <p class="muted">Con tu cuenta marcás los números que tenés, ves los que te faltan y te avisamos cuando los conseguimos.</p>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="<?= e(volver_actual()) ?>">
            <div class="form-row">
                <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($d['nombre']) ?>" required autocomplete="given-name"></label>
                <label class="campo">Apellido<input type="text" name="apellido" value="<?= e($d['apellido']) ?>" autocomplete="family-name"></label>
            </div>
            <label class="campo">Email<input type="email" name="email" value="<?= e($d['email']) ?>" required autocomplete="email"></label>
            <label class="campo">Teléfono <small>(opcional, para coordinar entregas)</small><input type="tel" name="telefono" value="<?= e($d['telefono']) ?>" autocomplete="tel"></label>
            <label class="campo">Contraseña <small>(mínimo 8 caracteres)</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <button class="btn btn-primario btn-bloque" type="submit">Crear mi cuenta</button>
        </form>
        <p class="small muted" style="margin:20px 0 0;text-align:center">¿Ya tenés cuenta? <a href="<?= url('ingresar.php') ?>">Ingresá</a></p>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
