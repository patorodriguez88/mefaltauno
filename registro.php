<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth_volver.php';

if ($c = cliente()) redirect(es_admin($c) ? 'admin/' : 'cuenta.php');

$d = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    if (trim($_POST['web'] ?? '') !== '') redirect('');   // trampa para bots: campo invisible que una persona no completa
    foreach ($d as $k => $_) $d[$k] = trim(mb_substr((string)($_POST[$k] ?? ''), 0, 160));
    $pass = $_POST['password'] ?? '';
    // Los bots mandan el formulario apenas lo bajan y ponen publicidad con links en el nombre
    $abierto = (int)($_SESSION['registro_form_at'] ?? 0);
    if (!$abierto || time() - $abierto < 3) $errores[] = 'Revisá los datos y volvé a tocar “Crear mi cuenta”.';
    if (preg_match('~https?:|www\.|://|[<>]|\.(com|net|org|pink|xyz|ru)\b~i', $d['nombre'] . ' ' . $d['apellido'])) $errores[] = 'El nombre y el apellido no pueden tener links.';
    elseif (mb_strlen($d['nombre']) > 60 || mb_strlen($d['apellido']) > 60) $errores[] = 'El nombre o el apellido son demasiado largos.';
    if ($d['nombre'] === '') $errores[] = 'Ingresá tu nombre.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no es válido.';
    if (strlen($pass) < 8) $errores[] = 'La contraseña tiene que tener al menos 8 caracteres.';
    if (!$errores && q("SELECT 1 FROM clientes WHERE email=?", [$d['email']])->fetchColumn()) {
        $errores[] = 'Ya hay una cuenta con ese email. ¿Querés ingresar?';
    }
    if (!$errores && in_array(strtolower($d['email']), maestros_emails(), true)) {
        // Mail de maestro (ADMIN_EMAILS): da acceso al panel, así que primero tiene que probar que el mail es suyo.
        // La cuenta se crea sin contraseña usable y la elige desde el link que le llega.
        q("INSERT INTO clientes (nombre, apellido, email, telefono, password_hash) VALUES (?,?,?,?,?)",
          [$d['nombre'], $d['apellido'], $d['email'], $d['telefono'] ?: null, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        clave_mail_maestro(q("SELECT id, nombre, email FROM clientes WHERE id=?", [db()->lastInsertId()])->fetch());
        flash('ok', 'Te mandamos un mail a ' . $d['email'] . ' para confirmar la cuenta y elegir tu contraseña.');
        redirect('ingresar.php');
    }
    if (!$errores) {
        q("INSERT INTO clientes (nombre, apellido, email, telefono, password_hash) VALUES (?,?,?,?,?)",
          [$d['nombre'], $d['apellido'], $d['email'], $d['telefono'] ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        login_cliente(q("SELECT * FROM clientes WHERE id=?", [db()->lastInsertId()])->fetch());
        flash('ok', '¡Bienvenido/a a la liga, ' . $d['nombre'] . '! Entrá a una colección y pasá lista de los que ya tenés.');
        header('Location: ' . destino_post_login());
        exit;
    }
}

$_SESSION['registro_form_at'] = time();
$titulo = 'Crear cuenta';
require __DIR__ . '/inc/header.php';
?>

<div class="container auth-wrap">
    <?php require __DIR__ . '/inc/auth_panel.php'; ?>
    <div class="auth-form">
        <h1>Sumate a la liga</h1>
        <p class="muted">Armá Mis colecciones: marcá los números que tenés, mirá qué te falta y te avisamos cuando lo rescatamos.</p>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="<?= e(volver_actual()) ?>">
            <input type="text" name="web" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
            <div class="form-row">
                <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($d['nombre']) ?>" required autocomplete="given-name"></label>
                <label class="campo">Apellido<input type="text" name="apellido" value="<?= e($d['apellido']) ?>" autocomplete="family-name"></label>
            </div>
            <div class="form-row">
                <label class="campo">Email<input type="email" name="email" value="<?= e($d['email']) ?>" required autocomplete="email"></label>
                <label class="campo">Teléfono <small>(opcional)</small><input type="tel" name="telefono" value="<?= e($d['telefono']) ?>" autocomplete="tel"></label>
            </div>
            <label class="campo">Contraseña <small>(mínimo 8 caracteres)</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <button class="btn btn-primario btn-bloque" type="submit">Crear mi cuenta</button>
        </form>
        <p class="small muted" style="margin:20px 0 0;text-align:center">¿Ya tenés cuenta? <a href="<?= url('ingresar.php') ?>">Ingresá</a></p>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
