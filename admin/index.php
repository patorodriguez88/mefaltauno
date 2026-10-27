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

// Lo que hay que atender (tarjetas de arriba): cantidad y monto por estado
$por_estado = [];
foreach (q("SELECT estado, COUNT(*) AS n, COALESCE(SUM(total),0) AS monto FROM pedidos GROUP BY estado")->fetchAll() as $r) $por_estado[$r['estado']] = $r;
$ped = fn (string ...$estados) => array_sum(array_map(fn ($e) => (int)($por_estado[$e]['n'] ?? 0), $estados));
$monto = fn (string ...$estados) => array_sum(array_map(fn ($e) => (float)($por_estado[$e]['monto'] ?? 0), $estados));

// Números del período (pestañas Hoy / Ayer / Esta semana / Este mes)
$hoy = new DateTimeImmutable('today');
$periodos = [
    'hoy'    => ['Hoy', $hoy, $hoy->modify('+1 day')],
    'ayer'   => ['Ayer', $hoy->modify('-1 day'), $hoy],
    'semana' => ['Esta semana', $hoy->modify('monday this week'), $hoy->modify('+1 day')],
    'mes'    => ['Este mes', $hoy->modify('first day of this month'), $hoy->modify('+1 day')],
];
$periodo = isset($periodos[$_GET['periodo'] ?? '']) ? $_GET['periodo'] : 'hoy';
[, $desde, $hasta] = $periodos[$periodo];
$rango = [$desde->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s')];
$ventas = q("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS monto FROM pedidos WHERE estado<>'cancelado' AND created_at>=? AND created_at<?", $rango)->fetch();
$metricas = [
    'Pedidos'          => (int)$ventas['n'],
    'Facturación'      => precio((float)$ventas['monto']),
    'Ticket promedio'  => precio($ventas['n'] ? $ventas['monto'] / $ventas['n'] : 0),
    'Clientes nuevos'  => (int)q("SELECT COUNT(*) FROM clientes WHERE created_at>=? AND created_at<?", $rango)->fetchColumn(),
    'Me faltan nuevos' => (int)q("SELECT COUNT(*) FROM solicitudes WHERE created_at>=? AND created_at<?", $rango)->fetchColumn(),
];

$k = [
    'sol_pend'  => (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'")->fetchColumn(),
    'sol_busc'  => (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='buscando'")->fetchColumn(),
    'clientes'  => (int)q("SELECT COUNT(*) FROM clientes")->fetchColumn(),
    'deposito'  => (int)q("SELECT COUNT(*) FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.activo=1 AND c.activa=1 AND i.stock>0")->fetchColumn(),
    'brihet'    => (int)q("SELECT COUNT(*) FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.activo=1 AND c.activa=1 AND COALESCE(i.brihet_stock,0)>0")->fetchColumn(),
    'cols'      => (int)q("SELECT COUNT(*) FROM colecciones")->fetchColumn(),
];

// Números más pedidos (para saber qué conseguir primero)
$mas_pedidos = q("SELECT si.descripcion, COUNT(*) AS veces FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id
                  WHERE s.estado IN ('pendiente','buscando') GROUP BY si.descripcion ORDER BY veces DESC, si.descripcion LIMIT 8")->fetchAll();

admin_header('Inicio', 'inicio');
$cuantos = fn (int $n, string $uno, string $varios) => $n === 0 ? null : ($n === 1 ? "1 $uno" : "$n $varios");
$tarjetas = [
    ['cobrar', 'Por cobrar', 'pedidos.php?estado=pendiente', $cuantos($ped('pendiente'), 'pedido', 'pedidos'), $monto('pendiente'), 'Sin pedidos'],
    ['colecciones', 'Por preparar', 'pedidos.php?estado=preparar', $cuantos($ped('confirmado', 'preparando'), 'pedido', 'pedidos'), $monto('confirmado', 'preparando'), 'Sin pedidos'],
    ['puntos', 'En el kiosco', 'pedidos.php?estado=enviado', $cuantos($ped('enviado'), 'pedido', 'pedidos'), null, 'Sin pedidos'],
    ['faltantes', 'Me faltan sin atender', 'faltantes.php?estado=pendiente', $cuantos($k['sol_pend'], 'pedido', 'pedidos'), null, 'Nada pendiente'],
];
?>

<h1>Inicio</h1>

<div class="adm-pendientes">
    <?php foreach ($tarjetas as [$icono, $label, $href, $texto, $suma, $vacio]): ?>
        <a class="adm-pend <?= $texto && $icono !== 'puntos' ? 'alerta' : '' ?>" href="<?= url('admin/' . $href) ?>">
            <?= admin_icono($icono) ?>
            <span><b><?= e($texto ?? $vacio) ?><?= $texto && $suma ? ' · ' . precio($suma) : '' ?></b><small><?= e($label) ?></small></span>
        </a>
    <?php endforeach; ?>
</div>

<nav class="adm-tabs" aria-label="Período">
    <?php foreach ($periodos as $clave => [$label]): ?>
        <a href="<?= url('admin/?periodo=' . $clave) ?>" class="<?= $clave === $periodo ? 'activo' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<div class="adm-metricas">
    <?php foreach ($metricas as $label => $valor): ?>
        <div class="adm-metrica"><span><?= e($label) ?></span><b><?= e((string)$valor) ?></b></div>
    <?php endforeach; ?>
</div>

<?php if (!datos_bancarios()): ?>
    <div class="aviso" style="margin-bottom:16px">Faltan los datos bancarios para los clientes que pagan por transferencia. <a href="<?= url('admin/ajustes.php') ?>">Cargarlos en Ajustes</a></div>
<?php endif; ?>
<?php if (!puntos_retiro_activos()): ?>
    <div class="aviso" style="margin-bottom:16px">No hay puntos de retiro disponibles: los clientes no pueden comprar. <a href="<?= url('admin/puntos.php?id=0') ?>">Cargar el primero</a></div>
<?php endif; ?>

<?php if (!$k['cols']): ?>
    <div class="card" style="margin-bottom:24px">
        <h3>El catálogo está vacío</h3>
        <p class="muted">Podés cargar las 13 colecciones que hoy están en Tiendanube (nombres, números, precios, stock e imágenes).</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="importar">
            <button class="btn btn-primario" type="submit">Importar catálogo de Tiendanube</button></form>
    </div>
<?php endif; ?>

<div class="kpis">
    <a class="kpi" href="<?= url('admin/faltantes.php?estado=buscando') ?>"><b><?= $k['sol_busc'] ?></b>Faltantes en búsqueda</a>
    <a class="kpi" href="<?= url('admin/colecciones.php?ver=deposito') ?>"><b><?= $k['deposito'] ?></b>Números en depósito</a>
    <a class="kpi" href="<?= url('admin/colecciones.php?ver=brihet') ?>"><b><?= $k['brihet'] ?></b>Números en Brihet</a>
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
