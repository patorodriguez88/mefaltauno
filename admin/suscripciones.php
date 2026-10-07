<?php
require __DIR__ . '/_inc.php';

$col = $_GET['col'] ?? '';            // '' = todas · 'general' = novedades generales · id
$estado = $_GET['estado'] ?? 'activas';
$where = [];
$params = [];
if ($col === 'general') {
    $where[] = 's.coleccion_id IS NULL';
} elseif ($col !== '') {
    $where[] = 's.coleccion_id = ?';
    $params[] = (int)$col;
}
if ($estado === 'activas') $where[] = 's.activo = 1';
if ($estado === 'bajas') $where[] = 's.activo = 0';
$buscar = trim($_GET['q'] ?? '');
if ($buscar !== '') {
    $where[] = '(s.email LIKE ? OR s.nombre LIKE ? OR s.telefono LIKE ?)';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%");
}
$sql = "SELECT s.*, c.nombre AS coleccion, cl.id AS es_cliente, CONCAT(cl.nombre, ' ', cl.apellido) AS cliente_nombre
        FROM suscripciones s LEFT JOIN colecciones c ON c.id=s.coleccion_id LEFT JOIN clientes cl ON cl.email=s.email
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY s.created_at DESC";
$filas = q($sql . ' LIMIT 2000', $params)->fetchAll();

// Descarga para Excel (CSV con ; y BOM para que abra bien los acentos)
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suscripciones-mefaltauno-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Email', 'Nombre', 'Teléfono', 'Colección', 'Cliente registrado', 'Origen', 'Estado', 'Fecha alta', 'Fecha baja'], ';');
    foreach ($filas as $f) {
        fputcsv($out, [$f['email'], $f['nombre'] ?: trim((string)$f['cliente_nombre']), $f['telefono'], $f['coleccion'] ?: 'Novedades generales',
                       $f['es_cliente'] ? 'Sí' : 'No', $f['origen'], $f['activo'] ? 'Activa' : 'Baja', $f['created_at'], $f['baja_at']], ';');
    }
    exit;
}

$por_col = q("SELECT COALESCE(c.nombre, 'Novedades generales') AS nombre, s.coleccion_id, COUNT(*) AS n
              FROM suscripciones s LEFT JOIN colecciones c ON c.id=s.coleccion_id WHERE s.activo=1
              GROUP BY s.coleccion_id, c.nombre ORDER BY n DESC")->fetchAll();
$total = (int)q("SELECT COUNT(DISTINCT email) FROM suscripciones WHERE activo=1")->fetchColumn();
$colecciones = q("SELECT id, nombre FROM colecciones ORDER BY nombre")->fetchAll();

admin_header('Suscripciones', 'suscripciones');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Suscripciones</h1>
        <p class="muted" style="margin:0"><b><?= $total ?></b> personas suscriptas. Se suman desde cada colección y desde “Sumate a la liga” en el pie de la web.</p>
    </div>
    <a class="btn btn-teal btn-chico" href="?<?= e(http_build_query(array_merge($_GET, ['formato' => 'csv']))) ?>">⬇ Descargar para Excel</a>
</div>

<div class="layout-2">
    <div>
        <form method="get" class="filtros" style="align-items:center">
            <select name="col" onchange="this.form.submit()" style="width:auto">
                <option value="">Todas las colecciones</option>
                <option value="general" <?= $col === 'general' ? 'selected' : '' ?>>Novedades generales</option>
                <?php foreach ($colecciones as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $col === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select>
            <select name="estado" onchange="this.form.submit()" style="width:auto">
                <option value="activas" <?= $estado === 'activas' ? 'selected' : '' ?>>Activas</option>
                <option value="bajas" <?= $estado === 'bajas' ? 'selected' : '' ?>>Bajas</option>
                <option value="todas" <?= $estado === 'todas' ? 'selected' : '' ?>>Todas</option>
            </select>
            <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Email, nombre o teléfono" style="width:220px">
        </form>
        <div class="tabla-wrap">
            <table class="tabla">
                <tr><th>Email</th><th>Nombre</th><th>Teléfono</th><th>Colección</th><th>Cliente</th><th>Origen</th><th>Alta</th></tr>
                <?php foreach ($filas as $f): ?>
                    <tr <?= !$f['activo'] ? 'class="fila-oculta"' : '' ?>>
                        <td><a href="mailto:<?= e($f['email']) ?>"><?= e($f['email']) ?></a><?= !$f['activo'] ? ' <span class="badge badge-gris">Baja</span>' : '' ?></td>
                        <td><?= e($f['nombre'] ?: trim((string)$f['cliente_nombre'])) ?></td>
                        <td class="small"><?php if ($f['telefono']): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $f['telefono'])) ?>" target="_blank" rel="noopener"><?= e($f['telefono']) ?></a><?php endif; ?></td>
                        <td class="small"><?= e($f['coleccion'] ?: 'Novedades generales') ?></td>
                        <td><?= $f['es_cliente'] ? '<span class="badge badge-verde">Sí</span>' : '<span class="badge badge-gris">No</span>' ?></td>
                        <td class="small"><?= e($f['origen']) ?></td>
                        <td class="small"><?= fecha($f['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filas): ?><tr><td colspan="7" class="muted">Todavía no hay suscripciones con ese filtro.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <aside class="card">
        <h3>Por colección</h3>
        <?php foreach ($por_col as $p): ?>
            <a class="resumen-fila small" style="text-decoration:none;color:inherit" href="?col=<?= $p['coleccion_id'] ? (int)$p['coleccion_id'] : 'general' ?>">
                <span><?= e($p['nombre']) ?></span><b><?= (int)$p['n'] ?></b></a>
        <?php endforeach; ?>
        <?php if (!$por_col): ?><p class="muted small">Sin suscriptos todavía.</p><?php endif; ?>
    </aside>
</div>

<?php admin_footer(); ?>
