<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'wepoint') {
    csrf_exigir();
    try {
        $oid = wepoint_crear_orden($id);
        flash('ok', "Orden enviada a WePoint ($oid).");
    } catch (Exception $e) {
        flash('error', 'WePoint: ' . $e->getMessage());
    }
    redirect('admin/pedido.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    try {
        pedido_cambiar_estado($id, $_POST['estado'] ?? '', trim($_POST['nota'] ?? '') ?: null, !empty($_POST['avisar']), nombre_cliente($admin));
        flash('ok', 'Pedido actualizado' . (!empty($_POST['avisar']) ? ' y cliente avisado por mail.' : '.'));
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('admin/pedido.php?id=' . $id);
}

$p = q("SELECT p.*, c.nombre, c.apellido, c.email, c.telefono FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$id])->fetch();
if (!$p) redirect('admin/pedidos.php');
$lineas = q("SELECT pi.*, i.imagen, i.stock FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$id])->fetchAll();
$hist = historial('pedido', $id);

admin_header('Pedido #' . $id, 'pedidos');
?>

<p><a href="<?= url('admin/pedidos.php') ?>">← Pedidos</a></p>
<div class="section-head">
    <h1 style="margin:0">Pedido #<?= $id ?></h1>
    <?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?>
</div>

<div class="layout-2">
    <div>
        <div class="card">
            <?php foreach ($lineas as $l): ?>
                <div class="linea">
                    <img src="<?= e(img($l['imagen'] ?? '')) ?>" alt="" loading="lazy">
                    <div>
                        <div class="linea-sub"><?= e($l['coleccion']) ?> · <?= num((int)$l['numero']) ?></div>
                        <div class="linea-titulo"><?= e($l['titulo']) ?></div>
                        <div class="linea-sub"><?= (int)$l['cantidad'] ?> × <?= precio((float)$l['precio']) ?></div>
                    </div>
                    <div class="linea-der"><strong><?= precio($l['precio'] * $l['cantidad']) ?></strong></div>
                </div>
            <?php endforeach; ?>
            <?php if ((float)$p['descuento'] > 0): ?>
                <div class="resumen-fila descuento"><span>Código <b><?= e($p['cupon_codigo']) ?></b></span><span>−<?= precio((float)$p['descuento']) ?></span></div>
            <?php endif; ?>
            <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio((float)$p['total']) ?></span></div>
        </div>

        <div class="card">
            <h3>Cliente y retiro</h3>
            <p class="small" style="margin:0">
                <b><?= e(nombre_cliente($p)) ?></b> · <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a> · <?= e($p['telefono'] ?: '') ?><br><br>
                <b><?= e($p['envio_punto'] ? 'Retira en ' . $p['envio_punto'] : (ENVIO_METODOS[$p['envio_metodo']] ?? '')) ?></b><br>
                <?= e($p['envio_direccion']) ?>, <?= e($p['envio_localidad']) ?> <?= e($p['envio_provincia']) ?><br>
                Retira: <?= e($p['envio_nombre']) ?> (<?= e($p['envio_telefono']) ?>)<br><br>
                <b>Pago:</b> <?= e(PAGO_METODOS[$p['pago_metodo']] ?? '') ?>
                <?php if ($p['notas']): ?><br><br><b>Notas del cliente:</b> <?= nl2br(e($p['notas'])) ?><?php endif; ?>
            </p>
        </div>
    </div>

    <aside>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <h3>Actualizar</h3>
            <label class="campo">Estado
                <select name="estado">
                    <?php foreach (ESTADOS_PEDIDO as $k => [$l]): ?><option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="campo">Mensaje para el cliente <small>(opcional: link de pago, seguimiento del envío…)</small><textarea name="nota"></textarea></label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="avisar" value="1" checked> Avisarle por mail</label>
            <p class="muted small" style="margin:0">“Listo para retirar” le avisa que pase a buscarlo. Cancelar devuelve el stock. Retirado suma los números a la colección del cliente.</p>
            <button class="btn btn-primario" type="submit">Guardar</button>
        </form>
        <div class="card">
            <h3>WePoint</h3>
            <?php if ($p['wepoint_orden_id']): ?>
                <p class="small" style="margin:0"><span class="badge badge-verde">Orden enviada</span> <b>id <?= e($p['wepoint_orden_id']) ?></b> · ref MFU-<?= (int)$p['id'] ?><br>
                    Estado en WePoint: <b><?= e($p['wepoint_estado'] ?: 'Emitida') ?></b><br>
                    <span class="muted">Se actualiza solo con el cron (cada 5 min) o desde Admin → WePoint.</span></p>
            <?php elseif (!wepoint_listo()): ?>
                <p class="muted small" style="margin:0">WePoint todavía no está conectado. Cuando lo esté, la orden se envía sola al marcar “Pago confirmado”.</p>
            <?php elseif (!wepoint_ordenes_activas()): ?>
                <p class="muted small" style="margin:0"><span class="badge badge-gris">Envío de órdenes apagado</span><br>Modo prueba: los pedidos no se mandan al depósito. Se activa con <code>WEPOINT_CREAR_ORDENES</code> en config.php.</p>
            <?php else: ?>
                <?php if ($p['wepoint_error']): ?><div class="aviso" style="background:var(--red-100);color:var(--red);margin-bottom:10px"><?= e($p['wepoint_error']) ?></div><?php endif; ?>
                <p class="muted small">La orden se envía sola al marcar “Pago confirmado”. Si falló o querés mandarla ahora:</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="wepoint"><button class="btn btn-teal btn-chico" type="submit">Enviar a WePoint</button></form>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Historial</h3>
            <ul class="timeline">
                <?php foreach ($hist as $h): ?>
                    <li><b><?= e(ESTADOS_PEDIDO[$h['estado']][0] ?? $h['estado']) ?></b>
                        <div class="cuando"><?= fecha($h['created_at']) ?> · <?= e($h['usuario']) ?></div>
                        <?php if ($h['nota']): ?><div class="small"><?= nl2br(e($h['nota'])) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
</div>

<?php admin_footer(); ?>
