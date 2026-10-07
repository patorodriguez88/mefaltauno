<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);
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
                    <img src="<?= e($l['imagen'] ?? '') ?>" alt="" loading="lazy">
                    <div>
                        <div class="linea-sub"><?= e($l['coleccion']) ?> · <?= num((int)$l['numero']) ?></div>
                        <div class="linea-titulo"><?= e($l['titulo']) ?></div>
                        <div class="linea-sub"><?= (int)$l['cantidad'] ?> × <?= precio((float)$l['precio']) ?></div>
                    </div>
                    <div class="linea-der"><strong><?= precio($l['precio'] * $l['cantidad']) ?></strong></div>
                </div>
            <?php endforeach; ?>
            <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio((float)$p['total']) ?></span></div>
        </div>

        <div class="card">
            <h3>Cliente y entrega</h3>
            <p class="small" style="margin:0">
                <b><?= e(nombre_cliente($p)) ?></b> · <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a> · <?= e($p['telefono'] ?: '') ?><br><br>
                <b><?= e(ENVIO_METODOS[$p['envio_metodo']] ?? '') ?></b> — recibe <?= e($p['envio_nombre']) ?> (<?= e($p['envio_telefono']) ?>)<br>
                <?= e($p['envio_direccion']) ?>, <?= e($p['envio_localidad']) ?> <?= e($p['envio_provincia']) ?> <?= e($p['envio_cp']) ?><br><br>
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
            <p class="muted small" style="margin:0">Cancelar devuelve el stock. Entregado suma los números a la colección del cliente.</p>
            <button class="btn btn-primario" type="submit">Guardar</button>
        </form>
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
