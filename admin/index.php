<?php
require __DIR__ . '/_inc.php';

// Carga inicial del catálogo (solo si está vacío)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'importar') {
    csrf_exigir();
    require dirname(__DIR__) . '/inc/importar.php';
    $n = importar_catalogo_tiendanube();
    flash('ok', "Importamos {$n['colecciones']} colecciones y {$n['items']} números.");
    redirect('admin/');
}

$k = [
    'sol_pend'  => (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'")->fetchColumn(),
    'sol_busc'  => (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='buscando'")->fetchColumn(),
    'ped_pend'  => (int)q("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn(),
    'ped_curso' => (int)q("SELECT COUNT(*) FROM pedidos WHERE estado IN ('confirmado','preparando','enviado')")->fetchColumn(),
    'clientes'  => (int)q("SELECT COUNT(*) FROM clientes")->fetchColumn(),
    'sin_stock' => (int)q("SELECT COUNT(*) FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.activo=1 AND c.activa=1 AND i.stock<=0")->fetchColumn(),
    'cols'      => (int)q("SELECT COUNT(*) FROM colecciones")->fetchColumn(),
];

// Números más pedidos (para saber qué conseguir primero)
$mas_pedidos = q("SELECT si.descripcion, COUNT(*) AS veces FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id
                  WHERE s.estado IN ('pendiente','buscando') GROUP BY si.descripcion ORDER BY veces DESC, si.descripcion LIMIT 8")->fetchAll();

admin_header('Resumen', 'inicio');
?>

<h1>Resumen</h1>

<?php if (!$k['cols']): ?>
    <div class="card" style="margin-bottom:24px">
        <h3>El catálogo está vacío</h3>
        <p class="muted">Podés cargar las 13 colecciones que hoy están en Tiendanube (nombres, números, precios, stock e imágenes).</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="importar">
            <button class="btn btn-primario" type="submit">Importar catálogo de Tiendanube</button></form>
    </div>
<?php endif; ?>

<div class="kpis">
    <a class="kpi <?= $k['sol_pend'] ? 'alerta' : '' ?>" href="<?= url('admin/faltantes.php?estado=pendiente') ?>"><b><?= $k['sol_pend'] ?></b>Faltantes sin atender</a>
    <a class="kpi" href="<?= url('admin/faltantes.php?estado=buscando') ?>"><b><?= $k['sol_busc'] ?></b>Faltantes en búsqueda</a>
    <a class="kpi <?= $k['ped_pend'] ? 'alerta' : '' ?>" href="<?= url('admin/pedidos.php?estado=pendiente') ?>"><b><?= $k['ped_pend'] ?></b>Pedidos nuevos</a>
    <a class="kpi" href="<?= url('admin/pedidos.php?estado=curso') ?>"><b><?= $k['ped_curso'] ?></b>Pedidos en curso</a>
    <a class="kpi" href="<?= url('admin/colecciones.php?sin_stock=1') ?>"><b><?= $k['sin_stock'] ?></b>Números sin stock</a>
    <a class="kpi" href="<?= url('admin/clientes.php') ?>"><b><?= $k['clientes'] ?></b>Clientes</a>
</div>

<div class="layout-2">
    <div>
        <h3>Últimos faltantes pedidos</h3>
        <?php $sols = q("SELECT s.*, c.nombre, c.apellido, (SELECT GROUP_CONCAT(descripcion SEPARATOR ' · ') FROM solicitud_items WHERE solicitud_id=s.id) AS items
                         FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.estado IN ('pendiente','buscando','conseguido') ORDER BY s.created_at DESC LIMIT 10")->fetchAll(); ?>
        <div class="tabla-wrap" style="margin-bottom:24px">
            <table class="tabla">
                <tr><th>#</th><th>Cliente</th><th>Qué le falta</th><th>Estado</th></tr>
                <?php foreach ($sols as $s): ?>
                    <tr class="clic" onclick="location='<?= url('admin/solicitud.php?id=' . (int)$s['id']) ?>'">
                        <td><?= (int)$s['id'] ?></td><td><?= e(nombre_cliente($s)) ?><div class="muted small"><?= fecha($s['created_at']) ?></div></td>
                        <td class="small"><?= e(mb_strimwidth($s['items'] ?? '', 0, 120, '…')) ?></td><td><?= badge_estado($s['estado'], ESTADOS_SOLICITUD) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sols): ?><tr><td colspan="4" class="muted">No hay faltantes abiertos.</td></tr><?php endif; ?>
            </table>
        </div>

        <h3>Últimos pedidos</h3>
        <?php $peds = q("SELECT p.*, c.nombre, c.apellido FROM pedidos p JOIN clientes c ON c.id=p.cliente_id ORDER BY p.created_at DESC LIMIT 10")->fetchAll(); ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <tr><th>#</th><th>Cliente</th><th>Total</th><th>Estado</th></tr>
                <?php foreach ($peds as $p): ?>
                    <tr class="clic" onclick="location='<?= url('admin/pedido.php?id=' . (int)$p['id']) ?>'">
                        <td><?= (int)$p['id'] ?></td><td><?= e(nombre_cliente($p)) ?><div class="muted small"><?= fecha($p['created_at']) ?></div></td>
                        <td><?= precio((float)$p['total']) ?></td><td><?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$peds): ?><tr><td colspan="4" class="muted">Todavía no hay pedidos.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <aside class="card">
        <h3>Lo más pedido para conseguir</h3>
        <?php if ($mas_pedidos): ?>
            <ol style="padding-left:20px;margin:0">
                <?php foreach ($mas_pedidos as $m): ?><li class="small" style="margin-bottom:6px"><?= e($m['descripcion']) ?> <b>(<?= (int)$m['veces'] ?>)</b></li><?php endforeach; ?>
            </ol>
        <?php else: ?><p class="muted small">Nada pendiente.</p><?php endif; ?>
    </aside>
</div>

<?php admin_footer(); ?>
