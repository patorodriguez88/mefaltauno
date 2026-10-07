<?php
require __DIR__ . '/_inc.php';

$estado = $_GET['estado'] ?? '';
$where = '1';
$params = [];
if ($estado === 'curso') {
    $where = "p.estado IN ('confirmado','preparando','enviado')";
} elseif (isset(ESTADOS_PEDIDO[$estado])) {
    $where = 'p.estado=?';
    $params[] = $estado;
}
$buscar = trim($_GET['q'] ?? '');
if ($buscar !== '') {
    $where .= ' AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.email LIKE ? OR p.id = ?)';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%", (int)$buscar);
}
$peds = q("SELECT p.*, c.nombre, c.apellido, c.email, (SELECT SUM(cantidad) FROM pedido_items WHERE pedido_id=p.id) AS unidades
           FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE $where ORDER BY p.created_at DESC LIMIT 300", $params)->fetchAll();

admin_header('Pedidos', 'pedidos');
?>

<div class="section-head">
    <h1 style="margin:0">Pedidos</h1>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
        <select name="estado" onchange="this.form.submit()" style="width:auto">
            <option value="">Todos los estados</option>
            <option value="curso" <?= $estado === 'curso' ? 'selected' : '' ?>>En curso</option>
            <?php foreach (ESTADOS_PEDIDO as $k => [$l]): ?><option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Cliente, email o #" style="width:220px">
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla">
        <tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Números</th><th>Entrega</th><th>Pago</th><th>Total</th><th>Estado</th></tr>
        <?php foreach ($peds as $p): ?>
            <tr class="clic" onclick="location='<?= url('admin/pedido.php?id=' . (int)$p['id']) ?>'">
                <td><?= (int)$p['id'] ?></td>
                <td class="small"><?= fecha($p['created_at']) ?></td>
                <td><?= e(nombre_cliente($p)) ?><div class="muted small"><?= e($p['email']) ?></div></td>
                <td><?= (int)$p['unidades'] ?></td>
                <td class="small"><?= e(ENVIO_METODOS[$p['envio_metodo']] ?? '') ?><div class="muted"><?= e($p['envio_localidad']) ?></div></td>
                <td class="small"><?= e(PAGO_METODOS[$p['pago_metodo']] ?? '') ?></td>
                <td><b><?= precio((float)$p['total']) ?></b></td>
                <td><?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$peds): ?><tr><td colspan="8" class="muted">No hay pedidos con ese filtro.</td></tr><?php endif; ?>
    </table>
</div>

<?php admin_footer(); ?>
