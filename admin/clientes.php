<?php
require __DIR__ . '/_inc.php';

$buscar = trim($_GET['q'] ?? '');
$params = [];
$where = '1';
if ($buscar !== '') {
    $where = '(c.nombre LIKE ? OR c.apellido LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)';
    $params = array_fill(0, 4, "%$buscar%");
}
$clis = q("SELECT c.*,
                  (SELECT COUNT(*) FROM cliente_colecciones WHERE cliente_id=c.id) AS colecciones,
                  (SELECT COUNT(*) FROM cliente_items WHERE cliente_id=c.id) AS numeros,
                  (SELECT COUNT(*) FROM pedidos WHERE cliente_id=c.id AND estado<>'cancelado') AS pedidos,
                  (SELECT COALESCE(SUM(total),0) FROM pedidos WHERE cliente_id=c.id AND estado<>'cancelado') AS comprado,
                  (SELECT COUNT(*) FROM solicitudes WHERE cliente_id=c.id AND estado IN ('pendiente','buscando','conseguido')) AS faltantes
           FROM clientes c WHERE $where ORDER BY c.created_at DESC LIMIT 500", $params)->fetchAll();

admin_header('Clientes', 'clientes');
?>

<div class="section-head">
    <h1 style="margin:0">Clientes</h1>
    <form method="get"><input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Nombre, email o teléfono" style="width:260px"></form>
</div>

<div class="tabla-wrap">
    <table class="tabla">
        <tr><th>Cliente</th><th>Teléfono</th><th>Localidad</th><th>Colecciones</th><th>Números que tiene</th><th>Pedidos</th><th>Comprado</th><th>Faltantes abiertos</th><th>Alta</th></tr>
        <?php foreach ($clis as $c): ?>
            <tr>
                <td><b><?= e(nombre_cliente($c)) ?></b><div class="muted small"><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></div></td>
                <td class="small"><?= e($c['telefono']) ?></td>
                <td class="small"><?= e($c['localidad']) ?></td>
                <td><?= (int)$c['colecciones'] ?></td>
                <td><?= (int)$c['numeros'] ?></td>
                <td><?= (int)$c['pedidos'] ?></td>
                <td><?= precio((float)$c['comprado']) ?></td>
                <td><?= $c['faltantes'] ? '<a href="' . url('admin/faltantes.php') . '"><span class="badge badge-amarillo">' . (int)$c['faltantes'] . '</span></a>' : '0' ?></td>
                <td class="small"><?= fecha($c['created_at'], false) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$clis): ?><tr><td colspan="9" class="muted">Sin clientes todavía.</td></tr><?php endif; ?>
    </table>
</div>

<?php admin_footer(); ?>
