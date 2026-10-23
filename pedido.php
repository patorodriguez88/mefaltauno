<?php
require __DIR__ . '/inc/bootstrap.php';

$cli = requiere_login();
$p = q("SELECT * FROM pedidos WHERE id=? AND cliente_id=?", [(int)($_GET['id'] ?? 0), $cli['id']])->fetch();
if (!$p) redirect('cuenta.php?tab=pedidos');

// Mercado Pago: el botón "Pagar" arma (o reusa) el link; al volver de pagar se mira el pago en el momento,
// así el cliente ve su pedido confirmado sin esperar el aviso de Mercado Pago.
if (mp_activo() && $p['pago_metodo'] === 'mercadopago') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'pagar_mp') {
        csrf_exigir();
        try {
            redirect(mp_link_de_pago((int)$p['id']));
        } catch (Exception $e) {
            error_log("[mercadopago] link del pedido #{$p['id']}: " . $e->getMessage());
            flash('error', 'No pudimos abrir Mercado Pago. Probá de nuevo en un momento.');
            redirect('pedido.php?id=' . $p['id']);
        }
    }
    if (isset($_GET['mp'])) {
        $pago_id = preg_replace('/\D/', '', (string)($_GET['payment_id'] ?? $_GET['collection_id'] ?? ''));
        $estado_mp = null;
        if ($pago_id !== '') {
            try {
                $estado_mp = mp_procesar_pago($pago_id);
            } catch (Exception $e) {
                error_log("[mercadopago] vuelta del pago $pago_id: " . $e->getMessage());
            }
        }
        $estado_mp = $estado_mp ?? (string)($_GET['status'] ?? $_GET['collection_status'] ?? '');
        if ($estado_mp === 'approved') {
            flash('ok', '¡Pago aprobado! Ya estamos preparando tu pedido.');
        } elseif (in_array($estado_mp, ['pending', 'in_process', 'authorized'], true)) {
            flash('info', 'Tu pago está en proceso. Apenas Mercado Pago lo apruebe, confirmamos el pedido y te avisamos por mail.');
        } else {
            flash('error', 'El pago no se completó. Podés intentarlo de nuevo con el botón "Pagar con Mercado Pago".');
        }
        redirect('pedido.php?id=' . $p['id']);
    }
}
$lineas = q("SELECT pi.*, i.imagen FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$p['id']])->fetchAll();
$hist = historial('pedido', (int)$p['id']);
$punto = $p['punto_id'] ? q("SELECT horario, lat, lng FROM puntos_retiro WHERE id=?", [$p['punto_id']])->fetch() : null;

$titulo = 'Pedido #' . $p['id'];
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <p><a href="<?= url('cuenta.php?tab=pedidos') ?>">← Mis pedidos</a></p>
        <div class="section-head">
            <h1 style="margin:0">Pedido #<?= (int)$p['id'] ?></h1>
            <?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?>
        </div>
        <p class="muted"><?= e(ESTADOS_PEDIDO[$p['estado']][1] ?? '') ?></p>

        <div class="layout-2">
            <div class="card">
                <?php foreach ($lineas as $l): ?>
                    <div class="linea">
                        <?= foto($l['imagen'] ?? '') ?>
                        <div>
                            <div class="linea-sub"><?= e($l['coleccion']) ?> · <?= num((int)$l['numero']) ?></div>
                            <div class="linea-titulo"><?= e($l['titulo']) ?></div>
                            <div class="linea-sub"><?= (int)$l['cantidad'] ?> × <?= precio((float)$l['precio']) ?></div>
                            <?php if ((int)$l['encargue']): ?><div class="linea-sub"><?= linea_entrega((int)$l['fisico'], (int)$l['encargue']) ?></div><?php endif; ?>
                        </div>
                        <div class="linea-der"><strong><?= precio($l['precio'] * $l['cantidad']) ?></strong></div>
                    </div>
                <?php endforeach; ?>
                <?php if ((float)$p['descuento'] > 0): ?>
                    <div class="resumen-fila descuento"><span>Código <b><?= e($p['cupon_codigo']) ?></b></span><span>−<?= precio((float)$p['descuento']) ?></span></div>
                <?php endif; ?>
                <?= fila_recargo((float)$p['recargo'], (int)array_sum(array_column($lineas, 'cantidad'))) ?>
                <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio((float)$p['total']) ?></span></div>
            </div>

            <aside>
                <?php if ($p['pago_metodo'] === 'mercadopago' && $p['estado'] === 'pendiente' && mp_activo()): ?>
                    <div class="card card-destacada">
                        <h3>Falta el pago</h3>
                        <p class="small" style="margin:0 0 12px"><?= str_starts_with((string)$p['mp_estado'], 'in_process') || str_starts_with((string)$p['mp_estado'], 'pending')
                            ? 'Tu pago está en proceso en Mercado Pago. Apenas se apruebe, confirmamos el pedido.'
                            : 'Pagá con tarjeta, débito o dinero en cuenta. Apenas se aprueba, empezamos a preparar tu pedido.' ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="pagar_mp">
                            <button class="btn btn-primario btn-bloque" type="submit">Pagar con Mercado Pago</button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if ($p['pago_metodo'] === 'transferencia' && $p['estado'] === 'pendiente'): ?>
                    <div class="card card-destacada">
                        <h3>Datos para transferir</h3>
                        <?= html_datos_bancarios((float)$p['total']) ?>
                        <p class="small" style="margin:12px 0 0">Cuando transfieras, enviá el comprobante respondiendo el mail del pedido. Apenas lo confirmamos, empezamos a prepararlo.</p>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <h3>Retiro y pago</h3>
                    <p class="small" style="margin:0">
                        <b><?= e($p['envio_punto'] ?: (ENVIO_METODOS[$p['envio_metodo']] ?? '')) ?></b><br>
                        <?= e($p['envio_direccion']) ?>, <?= e($p['envio_localidad']) ?><br>
                        <?php if (!empty($punto['horario'])): ?><span class="muted"><?= e($punto['horario']) ?></span><br><?php endif; ?>
                        <?php if (!empty($punto['lat'])): ?><a href="https://www.google.com/maps/search/?api=1&query=<?= e($punto['lat'] . ',' . $punto['lng']) ?>" target="_blank" rel="noopener">Cómo llegar ↗</a><br><?php endif; ?>
                        <br>Retira: <?= e($p['envio_nombre']) ?> · <?= e($p['envio_telefono']) ?><br><br>
                        <b><?= e(PAGO_METODOS[$p['pago_metodo']] ?? '') ?></b>
                    </p>
                </div>
                <div class="card">
                    <h3>Seguimiento</h3>
                    <ul class="timeline">
                        <?php foreach ($hist as $h): ?>
                            <li>
                                <b><?= e(ESTADOS_PEDIDO[$h['estado']][0] ?? $h['estado']) ?></b>
                                <div class="cuando"><?= fecha($h['created_at']) ?></div>
                                <?php if ($h['nota']): ?><div class="small"><?= nl2br(e($h['nota'])) ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
