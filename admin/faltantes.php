<?php
require __DIR__ . '/_inc.php';

$estado = $_GET['estado'] ?? 'abiertas';
$where = "s.estado IN ('pendiente','buscando','conseguido')";
$params = [];
if ($estado === 'todas') {
    $where = '1';
} elseif (isset(ESTADOS_SOLICITUD[$estado])) {
    $where = 's.estado=?';
    $params[] = $estado;
}
$sols = q("SELECT s.*, c.nombre, c.apellido, c.email,
                  (SELECT GROUP_CONCAT(descripcion SEPARATOR '\n') FROM solicitud_items WHERE solicitud_id=s.id) AS items
           FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id
           WHERE $where ORDER BY FIELD(s.estado,'pendiente','buscando','conseguido','no_disponible','cancelada'), s.created_at
           LIMIT 300", $params)->fetchAll();

admin_header('Me faltan', 'faltantes');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Me faltan</h1>
        <p class="muted" style="margin:0">Números que los clientes nos pidieron conseguir. Los más viejos primero.</p>
    </div>
    <form method="get">
        <select name="estado" onchange="this.form.submit()" style="width:auto">
            <option value="abiertas" <?= $estado === 'abiertas' ? 'selected' : '' ?>>Abiertas</option>
            <option value="todas" <?= $estado === 'todas' ? 'selected' : '' ?>>Todas</option>
            <?php foreach (ESTADOS_SOLICITUD as $k => [$l]): ?><option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla">
        <tr><th>#</th><th>Pedido el</th><th>Cliente</th><th>Qué le falta</th><th>Estado</th></tr>
        <?php foreach ($sols as $s): ?>
            <tr class="clic" onclick="location='<?= url('admin/solicitud.php?id=' . (int)$s['id']) ?>'">
                <td><?= (int)$s['id'] ?></td>
                <td class="small"><?= fecha($s['created_at']) ?></td>
                <td><?= e(nombre_cliente($s)) ?><div class="muted small"><?= e($s['email']) ?></div></td>
                <td class="small"><?= nl2br(e($s['items'])) ?><?php if ($s['mensaje']): ?><div class="muted">“<?= e(mb_strimwidth($s['mensaje'], 0, 100, '…')) ?>”</div><?php endif; ?></td>
                <td><?= badge_estado($s['estado'], ESTADOS_SOLICITUD) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sols): ?><tr><td colspan="5" class="muted">No hay pedidos de faltantes con ese filtro. 🙌</td></tr><?php endif; ?>
    </table>
</div>

<?php admin_footer(); ?>
