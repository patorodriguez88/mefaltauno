<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/notificaciones.php';

$cli = requiere_login();
// Stock y precio de Brihet al día para lo que está en el carrito (solo esos números, sin fotos)
require_once __DIR__ . '/inc/brihet.php';
brihet_refrescar_items(array_keys(carrito()));
$c = carrito_lineas();
if (!$c['lineas']) redirect('carrito.php');

$puntos = puntos_retiro_activos();
$d = [
    'punto_id'    => (int)($_POST['punto_id'] ?? 0),
    'pago_metodo' => $_POST['pago_metodo'] ?? 'mercadopago',
    'nombre'      => $_POST['nombre'] ?? nombre_cliente($cli),
    'telefono'    => $_POST['telefono'] ?? $cli['telefono'],
    'notas'       => $_POST['notas'] ?? '',
];
$referencia = trim(implode(', ', array_filter([$cli['direccion'], $cli['localidad']])));
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    foreach (['pago_metodo', 'nombre', 'telefono', 'notas'] as $k) $d[$k] = trim((string)$d[$k]);
    $punto = $d['punto_id'] ? q("SELECT * FROM puntos_retiro WHERE id=? AND activo=1", [$d['punto_id']])->fetch() : null;
    if (!$punto) $errores[] = 'Elegí en el mapa el punto donde vas a retirar.';
    if (!in_array($d['pago_metodo'], PAGO_METODOS_ACTIVOS, true)) $errores[] = 'Elegí cómo querés pagar.';
    if ($d['nombre'] === '') $errores[] = 'Indicá quién retira.';
    if ($d['telefono'] === '') $errores[] = 'Dejanos un teléfono para avisarte cuando esté listo.';

    if (!$errores) {
        $db = db();
        $db->beginTransaction();
        try {
            // Revalidar disponibilidad con bloqueo y descontarla (se devuelve si el pedido se cancela)
            $subtotal = 0;
            $recargo = 0;
            $fisicos = [];
            reservas_mapa(true);
            foreach ($c['lineas'] as $l) {
                $fila = q("SELECT id, stock, congelado, limite, max_por_cliente, brihet_stock FROM items WHERE id=? FOR UPDATE", [$l['item']['id']])->fetch();
                if (!$fila) throw new Exception('Se agotó ' . $l['item']['coleccion'] . ' ' . num((int)$l['item']['numero']) . '. Revisá tu carrito.');
                $puede = comprable($fila, (int)$cli['id']);
                if ($puede < $l['cantidad']) {
                    throw new Exception($l['item']['coleccion'] . ' ' . num((int)$l['item']['numero']) . ': '
                        . ($puede < disponible_total($fila, (int)$cli['id']) ? motivo_tope($fila, $puede) : 'se agotó.') . ' Revisá tu carrito.');
                }
                $fisicos[$l['item']['id']] = min($l['cantidad'], disponible($fila, (int)$cli['id']));
                $subtotal += $l['total'];
                $recargo += $l['recargo'] * $l['cantidad'];
            }
            // Código de descuento: se revalida y se cuenta el uso dentro de la misma transacción
            $cupon = null;
            $descuento = 0;
            if (carrito_cupon()) {
                q("SELECT id FROM cupones WHERE codigo=? FOR UPDATE", [carrito_cupon()]);
                $v = cupon_validar(carrito_cupon(), $cli, $subtotal);
                $cupon = $v['cupon']['codigo'];
                $descuento = $v['descuento'];
                q("UPDATE cupones SET usos = usos + 1 WHERE codigo=?", [$cupon]);
            }
            q("INSERT INTO pedidos (cliente_id, subtotal, cupon_codigo, descuento, recargo, total, envio_metodo, punto_id, envio_punto, envio_nombre, envio_direccion, envio_localidad, envio_provincia, envio_cp, envio_telefono, pago_metodo, notas)
               VALUES (?,?,?,?,?,?,'retiro',?,?,?,?,?,?,?,?,?,?)", [
                $cli['id'], $subtotal, $cupon, $descuento, $recargo, $subtotal - $descuento + $recargo, $punto['id'], $punto['nombre'], $d['nombre'], $punto['direccion'], $punto['localidad'],
                $punto['provincia'], $punto['cp'], $d['telefono'], $d['pago_metodo'], $d['notas'] ?: null,
            ]);
            $pid = (int)$db->lastInsertId();
            foreach ($c['lineas'] as $l) {
                $it = $l['item'];
                $fis = $fisicos[$it['id']] ?? $l['cantidad'];
                $enc = $l['cantidad'] - $fis;
                q("INSERT INTO pedido_items (pedido_id, item_id, coleccion, numero, titulo, precio, recargo, cantidad, fisico, encargue, encargue_estado) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                  [$pid, $it['id'], $it['coleccion'], $it['numero'], $it['titulo'], $l['precio'], $l['recargo'], $l['cantidad'], $fis, $enc, $enc ? 'pendiente' : null]);
                // Solo lo físico sale del stock del depósito; el tope del operador cuenta todo lo vendido
                q("UPDATE items SET stock = stock - ?, limite = IF(limite IS NULL, NULL, limite - ?) WHERE id=?", [$fis, $l['cantidad'], $it['id']]);
                if ($fis) reservas_usar((int)$cli['id'], (int)$it['id'], $fis, $pid);
                q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $it['coleccion_id']]);
            }
            registrar_historial('pedido', $pid, 'pendiente', null, nombre_cliente($cli));
            if (!$cli['telefono']) q("UPDATE clientes SET telefono=? WHERE id=?", [$d['telefono'], $cli['id']]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', $e->getMessage());
            redirect('carrito.php');
        }
        carrito_vaciar();
        notificar_pedido_nuevo($pid);
        // Mercado Pago: directo a pagar. Si no se puede armar el link, el pedido queda pendiente con el botón para pagar.
        if ($d['pago_metodo'] === 'mercadopago' && mp_activo()) {
            try {
                redirect(mp_link_de_pago($pid));
            } catch (Exception $e) {
                error_log("[mercadopago] link del pedido #$pid: " . $e->getMessage());
                flash('error', 'Recibimos tu pedido, pero no pudimos abrir Mercado Pago. Probá de nuevo con el botón "Pagar con Mercado Pago".');
                redirect('pedido.php?id=' . $pid);
            }
        }
        flash('ok', '¡Misión en marcha! Recibimos tu pedido y te mandamos el detalle por mail.');
        redirect('pedido.php?id=' . $pid);
    }
}

$titulo = 'Finalizar compra';
[$page_css, $page_scripts] = mapa_recursos();
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <h1>Último paso, coleccionista</h1>
        <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>

        <form method="post" class="layout-2">
            <?= csrf_field() ?>
            <input type="hidden" name="punto_id" id="punto-id" value="<?= $d['punto_id'] ?: '' ?>">
            <div>
                <div class="card">
                    <h3>Elegí tu punto de encuentro</h3>
                    <?php if (!$puntos): ?>
                        <div class="aviso">Todavía no hay puntos de retiro disponibles. Escribinos y coordinamos la entrega.</div>
                    <?php else: ?>
                        <p class="muted small" style="margin-top:-4px">Elegí el kiosco que te quede más cómodo. Te avisamos cuando tu pedido esté listo para retirar.</p>
                        <div id="selector-punto" data-puntos="<?= e(json_encode($puntos, JSON_UNESCAPED_UNICODE)) ?>">
                            <div class="buscador-dir">
                                <input type="search" id="buscar-dir" value="<?= e($referencia) ?>" placeholder="Tu dirección o barrio, ej: Av. Colón 1200, Córdoba" autocomplete="street-address">
                                <button class="btn btn-teal" type="button" id="btn-buscar-dir">Buscar</button>
                            </div>
                            <div class="buscar-pie">
                                <button class="btn-texto" type="button" id="btn-mi-ubicacion">📍 Usar mi ubicación</button>
                                <span class="muted small" id="buscar-msg"></span>
                            </div>
                            <div class="mapa" id="mapa-puntos"></div>
                            <div class="puntos-lista" id="puntos-lista" hidden></div>
                            <div class="punto-elegido" id="punto-elegido" hidden></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>¿Quién va a buscarlo?</h3>
                    <div class="form-row">
                        <label class="campo">Nombre y apellido<input type="text" name="nombre" value="<?= e($d['nombre']) ?>" required></label>
                        <label class="campo">Teléfono <small>(te avisamos cuando esté listo)</small><input type="tel" name="telefono" value="<?= e($d['telefono']) ?>" required></label>
                    </div>
                </div>

                <div class="card">
                    <h3>¿Cómo pagás?</h3>
                    <div class="opciones">
                        <label class="opcion"><input type="radio" name="pago_metodo" value="mercadopago" <?= $d['pago_metodo'] === 'mercadopago' ? 'checked' : '' ?>>
                            <div><strong>Mercado Pago</strong><span><?= mp_activo() ? 'Tarjeta, débito o dinero en cuenta. Al confirmar, pagás en Mercado Pago.' : 'Tarjeta, débito o dinero en cuenta. Te enviamos el link de pago.' ?></span></div></label>
                        <label class="opcion"><input type="radio" name="pago_metodo" value="transferencia" <?= $d['pago_metodo'] === 'transferencia' ? 'checked' : '' ?>>
                            <div style="flex:1"><strong>Transferencia bancaria</strong><span>Transferís desde tu banco o billetera virtual. Te dejamos los datos acá y en el mail.</span>
                                <div class="opcion-extra"><?= html_datos_bancarios($c['total']) ?></div></div></label>
                    </div>
                    <label class="campo" style="margin-top:16px">Notas para nosotros <small>(opcional)</small><textarea name="notas"><?= e($d['notas']) ?></textarea></label>
                </div>
            </div>

            <aside class="card resumen">
                <h3>Tu pedido</h3>
                <?php foreach ($c['lineas'] as $l): ?>
                    <div class="resumen-fila small">
                        <span><?= e($l['item']['coleccion']) ?> <?= num((int)$l['item']['numero']) ?><?= $l['cantidad'] > 1 ? ' ×' . $l['cantidad'] : '' ?><?= $l['encargue'] ? '<br><small class="txt-encargue">📦 ' . ($l['fisico'] ? $l['encargue'] . ' por encargue' : 'por encargue') . '</small>' : '' ?></span>
                        <span><?= precio($l['total']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($c['cupon']): ?>
                    <div class="resumen-fila descuento"><span>Código <b><?= e($c['cupon']['codigo']) ?></b></span><span>−<?= precio($c['descuento']) ?></span></div>
                <?php endif; ?>
                <?= fila_recargo($c['recargo'], $c['unidades']) ?>
                <?php if ($c['encargue']): ?><div class="aviso small" style="margin:8px 0">📦 Parte de tu pedido va por encargue: la entrega queda sujeta a los plazos del editor y te contactamos para coordinarla.</div><?php endif; ?>
                <div class="resumen-fila muted"><span>Retiro</span><span>Sin cargo</span></div>
                <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio($c['total']) ?></span></div>
                <button class="btn btn-primario btn-bloque" style="margin-top:16px" type="submit" <?= $puntos ? '' : 'disabled' ?>>Confirmar pedido ⚡</button>
                <p class="muted small" style="margin:12px 0 0">Te mandamos el detalle por mail y podés seguirlo desde tu cuenta.</p>
            </aside>
        </form>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
