<?php
// Pedido libre: un número que no está en la web (o que el cliente quiere describir a mano).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/notificaciones.php';

$cli = cliente();
$cols = q("SELECT id, nombre FROM colecciones WHERE activa=1 ORDER BY nombre")->fetchAll();
$d = ['coleccion_id' => $_GET['coleccion'] ?? '', 'coleccion_otra' => '', 'numeros' => '', 'mensaje' => ''];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cli = requiere_login();
    csrf_exigir();
    foreach ($d as $k => $_) $d[$k] = trim(mb_substr($_POST[$k] ?? '', 0, 2000));
    $col_nombre = '';
    foreach ($cols as $c) if ((string)$c['id'] === $d['coleccion_id']) $col_nombre = $c['nombre'];
    if ($col_nombre === '') $col_nombre = $d['coleccion_otra'];
    if ($col_nombre === '') $errores[] = 'Decinos de qué colección es.';
    if ($d['numeros'] === '') $errores[] = 'Decinos qué números o títulos te faltan.';

    if (!$errores) {
        $db = db();
        $db->beginTransaction();
        q("INSERT INTO solicitudes (cliente_id, mensaje) VALUES (?,?)", [$cli['id'], $d['mensaje'] ?: null]);
        $sol = (int)$db->lastInsertId();
        q("INSERT INTO solicitud_items (solicitud_id, item_id, descripcion) VALUES (?,NULL,?)", [$sol, mb_substr("$col_nombre — {$d['numeros']}", 0, 255)]);
        registrar_historial('solicitud', $sol, 'pendiente', null, nombre_cliente($cli));
        $db->commit();
        notificar_solicitud_nueva($sol);
        flash('ok', '¡Misión aceptada! Salimos a buscarlo y te avisamos por mail.');
        redirect('cuenta.php?tab=faltantes');
    }
}

$titulo = '¿Te falta uno?';
require __DIR__ . '/inc/header.php';
?>

<div class="container" style="max-width:640px;margin:40px auto">
    <div class="card">
        <h1 style="font-size:2rem">¿Te falta uno?</h1>
        <p class="muted">Danos la pista y salimos a buscarlo: colección, número y cualquier dato que sirva. Te avisamos apenas lo rescatamos.</p>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>

        <?php if (!$cli): ?>
            <div class="aviso" style="margin-bottom:16px">Para pedirlo necesitás una cuenta, así te avisamos y podés seguir el pedido. <a href="<?= url('ingresar.php?volver=' . urlencode(url('me-falta.php'))) ?>">Ingresá</a> o <a href="<?= url('registro.php?volver=' . urlencode(url('me-falta.php'))) ?>">creá tu cuenta</a>.</div>
        <?php endif; ?>

        <form method="post" class="form">
            <?= csrf_field() ?>
            <label class="campo">Colección
                <select name="coleccion_id" onchange="document.getElementById('otra').hidden = this.value !== ''">
                    <option value="">Otra (no está en la lista)</option>
                    <?php foreach ($cols as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === (string)$d['coleccion_id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="campo" id="otra" <?= $d['coleccion_id'] !== '' ? 'hidden' : '' ?>>¿Cuál? <small>Nombre de la colección y editorial, si la sabés</small>
                <input type="text" name="coleccion_otra" value="<?= e($d['coleccion_otra']) ?>" placeholder="Ej: Grandes Maestros de la Pintura">
            </label>
            <label class="campo">¿Qué números te faltan? <small>Números o títulos</small>
                <input type="text" name="numeros" value="<?= e($d['numeros']) ?>" placeholder="Ej: 4, 12 y 15 — o “El Principito”" required>
            </label>
            <label class="campo">Comentario <small>(opcional)</small>
                <textarea name="mensaje" placeholder="Cualquier dato que nos ayude a encontrarlo"><?= e($d['mensaje']) ?></textarea>
            </label>
            <button class="btn btn-primario" type="submit" <?= $cli ? '' : 'disabled' ?>>Activar la búsqueda</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
