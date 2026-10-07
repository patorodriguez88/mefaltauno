<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/notificaciones.php';

$cli = requiere_login();
$c = carrito_lineas();
if (!$c['lineas']) redirect('carrito.php');

$d = [
    'envio_metodo' => $_POST['envio_metodo'] ?? 'domicilio',
    'pago_metodo'  => $_POST['pago_metodo'] ?? 'mercadopago',
    'nombre'       => $_POST['nombre'] ?? nombre_cliente($cli),
    'telefono'     => $_POST['telefono'] ?? $cli['telefono'],
    'direccion'    => $_POST['direccion'] ?? $cli['direccion'],
    'localidad'    => $_POST['localidad'] ?? $cli['localidad'],
    'provincia'    => $_POST['provincia'] ?? $cli['provincia'],
    'cp'           => $_POST['cp'] ?? $cli['cp'],
    'notas'        => $_POST['notas'] ?? '',
];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $d = array_map(fn($v) => trim((string)$v), $d);
    if (!isset(ENVIO_METODOS[$d['envio_metodo']])) $errores[] = 'Elegí cómo querés recibirlo.';
    if (!isset(PAGO_METODOS[$d['pago_metodo']])) $errores[] = 'Elegí cómo querés pagar.';
    if ($d['nombre'] === '') $errores[] = 'Indicá quién recibe.';
    if ($d['telefono'] === '') $errores[] = 'Dejanos un teléfono para coordinar.';
    if ($d['direccion'] === '' || $d['localidad'] === '') {
        $errores[] = $d['envio_metodo'] === 'kiosco' ? 'Indicá el kiosco (dirección y localidad).' : 'Completá la dirección y la localidad.';
    }

    if (!$errores) {
        $db = db();
        $db->beginTransaction();
        try {
            // Revalidar stock con bloqueo y descontarlo (se devuelve si el pedido se cancela)
            $subtotal = 0;
            foreach ($c['lineas'] as $l) {
                $st = (int)q("SELECT stock FROM items WHERE id=? FOR UPDATE", [$l['item']['id']])->fetchColumn();
                if ($st < $l['cantidad']) throw new Exception('Se agotó ' . $l['item']['coleccion'] . ' ' . num((int)$l['item']['numero']) . '. Revisá tu carrito.');
                $subtotal += $l['total'];
            }
            q("INSERT INTO pedidos (cliente_id, subtotal, total, envio_metodo, envio_nombre, envio_direccion, envio_localidad, envio_provincia, envio_cp, envio_telefono, pago_metodo, notas)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [
                $cli['id'], $subtotal, $subtotal, $d['envio_metodo'], $d['nombre'], $d['direccion'], $d['localidad'],
                $d['provincia'] ?: null, $d['cp'] ?: null, $d['telefono'], $d['pago_metodo'], $d['notas'] ?: null,
            ]);
            $pid = (int)$db->lastInsertId();
            foreach ($c['lineas'] as $l) {
                $it = $l['item'];
                q("INSERT INTO pedido_items (pedido_id, item_id, coleccion, numero, titulo, precio, cantidad) VALUES (?,?,?,?,?,?,?)",
                  [$pid, $it['id'], $it['coleccion'], $it['numero'], $it['titulo'], $l['precio'], $l['cantidad']]);
                q("UPDATE items SET stock = stock - ? WHERE id=?", [$l['cantidad'], $it['id']]);
                q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $it['coleccion_id']]);
            }
            registrar_historial('pedido', $pid, 'pendiente', null, nombre_cliente($cli));
            // Guardar los datos de envío en el perfil si no los tenía
            q("UPDATE clientes SET telefono=COALESCE(telefono,?), direccion=COALESCE(direccion,?), localidad=COALESCE(localidad,?), provincia=COALESCE(provincia,?), cp=COALESCE(cp,?) WHERE id=?",
              [$d['telefono'], $d['envio_metodo'] === 'domicilio' ? $d['direccion'] : null, $d['envio_metodo'] === 'domicilio' ? $d['localidad'] : null, $d['provincia'] ?: null, $d['cp'] ?: null, $cli['id']]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', $e->getMessage());
            redirect('carrito.php');
        }
        carrito_vaciar();
        notificar_pedido_nuevo($pid);
        flash('ok', '¡Gracias! Recibimos tu pedido. Te mandamos el detalle por mail.');
        redirect('pedido.php?id=' . $pid);
    }
}

$titulo = 'Finalizar compra';
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <h1>Finalizar compra</h1>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>

        <form method="post" class="layout-2">
            <?= csrf_field() ?>
            <div>
                <div class="card">
                    <h3>¿Cómo lo recibís?</h3>
                    <div class="opciones">
                        <label class="opcion"><input type="radio" name="envio_metodo" value="domicilio" <?= $d['envio_metodo'] === 'domicilio' ? 'checked' : '' ?>>
                            <div><strong>Envío a domicilio</strong><span>Te lo llevamos a tu casa. El costo se coordina según la zona.</span></div></label>
                        <label class="opcion"><input type="radio" name="envio_metodo" value="kiosco" <?= $d['envio_metodo'] === 'kiosco' ? 'checked' : '' ?>>
                            <div><strong>Retiro en kiosco</strong><span>Lo retirás en el kiosco de diarios que elijas. Indicá su dirección abajo.</span></div></label>
                    </div>
                </div>

                <div class="card">
                    <h3>Datos de entrega</h3>
                    <div class="form">
                        <div class="form-row">
                            <label class="campo">Quién recibe<input type="text" name="nombre" value="<?= e($d['nombre']) ?>" required></label>
                            <label class="campo">Teléfono<input type="tel" name="telefono" value="<?= e($d['telefono']) ?>" required></label>
                        </div>
                        <label class="campo">Dirección <small>(o la del kiosco)</small><input type="text" name="direccion" value="<?= e($d['direccion']) ?>" required></label>
                        <div class="form-row">
                            <label class="campo">Localidad<input type="text" name="localidad" value="<?= e($d['localidad']) ?>" required></label>
                            <label class="campo">Provincia<input type="text" name="provincia" value="<?= e($d['provincia']) ?>"></label>
                        </div>
                        <div class="form-row">
                            <label class="campo">Código postal<input type="text" name="cp" value="<?= e($d['cp']) ?>"></label>
                            <div></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>¿Cómo pagás?</h3>
                    <div class="opciones">
                        <label class="opcion"><input type="radio" name="pago_metodo" value="mercadopago" <?= $d['pago_metodo'] === 'mercadopago' ? 'checked' : '' ?>>
                            <div><strong>Mercado Pago</strong><span>Tarjeta, débito o dinero en cuenta. Te enviamos el link de pago.</span></div></label>
                        <label class="opcion"><input type="radio" name="pago_metodo" value="contra_entrega" <?= $d['pago_metodo'] === 'contra_entrega' ? 'checked' : '' ?>>
                            <div><strong>Pago contra entrega</strong><span>Pagás cuando lo recibís.</span></div></label>
                    </div>
                    <label class="campo" style="margin-top:16px">Notas para nosotros <small>(opcional)</small><textarea name="notas"><?= e($d['notas']) ?></textarea></label>
                </div>
            </div>

            <aside class="card resumen">
                <h3>Tu pedido</h3>
                <?php foreach ($c['lineas'] as $l): ?>
                    <div class="resumen-fila small">
                        <span><?= e($l['item']['coleccion']) ?> <?= num((int)$l['item']['numero']) ?><?= $l['cantidad'] > 1 ? ' ×' . $l['cantidad'] : '' ?></span>
                        <span><?= precio($l['total']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="resumen-fila muted"><span>Envío</span><span>A coordinar</span></div>
                <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio($c['subtotal']) ?></span></div>
                <button class="btn btn-primario btn-bloque" style="margin-top:16px" type="submit">Confirmar pedido</button>
                <p class="muted small" style="margin:12px 0 0">Te mandamos el detalle por mail y podés seguirlo desde tu cuenta.</p>
            </aside>
        </form>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
