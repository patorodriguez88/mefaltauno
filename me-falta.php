<?php
// Pedido libre: un número que no está en la web (o que el cliente quiere describir a mano).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/notificaciones.php';

$cli = cliente();
$cols = q("SELECT id, nombre FROM colecciones WHERE activa=1 ORDER BY nombre")->fetchAll();
$d = ['coleccion' => '', 'numeros' => '', 'mensaje' => ''];
// Viene desde una colección: precargar su nombre
foreach ($cols as $c) if ((string)$c['id'] === (string)($_GET['coleccion'] ?? '')) $d['coleccion'] = $c['nombre'];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cli = requiere_login();
    csrf_exigir();
    foreach ($d as $k => $_) $d[$k] = trim(mb_substr($_POST[$k] ?? '', 0, 2000));
    // Si escribió una colección de la tienda, usamos su nombre tal cual; si no, lo que escribió
    $col_nombre = $d['coleccion'];
    foreach ($cols as $c) if (mb_strtolower($c['nombre']) === mb_strtolower($col_nombre)) $col_nombre = $c['nombre'];
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

<div class="container auth-wrap">
    <aside class="auth-panel">
        <div class="kicker">★ Ningún héroe queda atrás ★</div>
        <h2>Activá una misión de búsqueda</h2>
        <ul class="auth-beneficios">
            <li><span>🧭</span><div><b>Danos la pista:</b> colección y número, aunque no esté en la web.</div></li>
            <li><span>🔎</span><div><b>Salimos a rastrearlo</b> con distribuidores, editoriales y coleccionistas.</div></li>
            <li><span>⚡</span><div><b>Te avisamos por mail</b> apenas lo rescatamos y seguís la misión desde tu cuenta.</div></li>
        </ul>
    </aside>
    <div class="auth-form">
        <h1>¿Te falta uno?</h1>
        <p class="muted">Cuantos más datos nos des, más rápido lo encontramos.</p>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>

        <?php if (!$cli):
            $volver = urlencode(url('me-falta.php')); ?>
            <div class="sin-sesion">
                <div class="modal-icono">🔒</div>
                <h3>Para activar una búsqueda necesitás tu cuenta</h3>
                <p class="muted">Así te avisamos por mail apenas lo encontramos y podés seguir la misión desde tu panel.</p>
                <div class="sin-sesion-botones">
                    <a class="btn btn-primario" href="<?= url('registro.php?volver=' . $volver) ?>">Crear mi cuenta</a>
                    <a class="btn btn-linea" href="<?= url('ingresar.php?volver=' . $volver) ?>">Ya tengo cuenta</a>
                </div>
            </div>
        <?php elseif (es_admin($cli)): ?>
            <div class="sin-sesion">
                <div class="modal-icono">🛠️</div>
                <h3>Estás como operador</h3>
                <p class="muted">Las búsquedas las activan los clientes. Las que llegan las gestionás desde el panel.</p>
                <div class="sin-sesion-botones">
                    <a class="btn btn-teal" href="<?= url('admin/faltantes.php') ?>">Ver faltantes</a>
                </div>
            </div>
        <?php else: ?>

        <form method="post" class="form">
            <?= csrf_field() ?>
            <label class="campo">Colección <small>Elegila de la lista o escribila si no está en la tienda</small>
                <input type="text" name="coleccion" value="<?= e($d['coleccion']) ?>" list="lista-colecciones" placeholder="Ej: Racing Cars" required autocomplete="off">
            </label>
            <datalist id="lista-colecciones">
                <?php foreach ($cols as $c): ?><option value="<?= e($c['nombre']) ?>"></option><?php endforeach; ?>
            </datalist>
            <label class="campo">¿Qué números te faltan? <small>Números o títulos</small>
                <input type="text" name="numeros" value="<?= e($d['numeros']) ?>" placeholder="Ej: 4, 12 y 15 — o “El Principito”" required>
            </label>
            <label class="campo">Comentario <small>(opcional)</small>
                <textarea name="mensaje" placeholder="Editorial, año, estado, cualquier dato que nos ayude a encontrarlo"><?= e($d['mensaje']) ?></textarea>
            </label>
            <button class="btn btn-primario btn-bloque" type="submit">🔎 Activar la búsqueda</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
