<?php
// Mails enviados: cada mail que mandó la web (pedidos, reservas, abono, búsquedas, claves…), con a quién, cuándo y
// si el servidor de correo lo aceptó. Las campañas de marketing tienen su propio registro en Marketing.
require __DIR__ . '/_inc.php';

$buscar = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';   // '' = todos · 'error' = los que el servidor no aceptó
$ver = (int)($_GET['ver'] ?? 0);

$where = [];
$params = [];
if ($buscar !== '') {
    $where[] = '(m.para LIKE ? OR m.asunto LIKE ? OR CONCAT(c.nombre, " ", c.apellido) LIKE ?)';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%");
}
if ($estado === 'error') $where[] = 'm.ok = 0';
$filas = q("SELECT m.id, m.para, m.asunto, m.ok, m.created_at, c.id AS cliente_id, c.nombre, c.apellido
            FROM mails_enviados m LEFT JOIN clientes c ON c.email = m.para
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY m.id DESC LIMIT 500", $params)->fetchAll();
$abierto = $ver ? q("SELECT * FROM mails_enviados WHERE id=?", [$ver])->fetch() : null;
$hoy = (int)q("SELECT COUNT(*) FROM mails_enviados WHERE created_at >= CURDATE()")->fetchColumn();
$errores = (int)q("SELECT COUNT(*) FROM mails_enviados WHERE ok = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

admin_header('Mails enviados', 'mails');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Mails enviados</h1>
        <p class="muted" style="margin:0"><b><?= $hoy ?></b> hoy<?= $errores ? ' · <b style="color:var(--red)">' . $errores . '</b> no salieron en los últimos 7 días' : '' ?>.
            Todos los mails de la web menos las campañas, que se ven en Marketing.</p>
    </div>
</div>

<div class="layout-2"<?= $abierto ? ' style="grid-template-columns:minmax(0,1fr) clamp(360px, 40vw, 640px)"' : '' ?>>
    <div>
        <form method="get" class="filtros" style="align-items:center">
            <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Email, nombre o asunto (ej. Iron Man)" style="width:260px">
            <select name="estado" onchange="this.form.submit()" style="width:auto">
                <option value="">Todos</option>
                <option value="error" <?= $estado === 'error' ? 'selected' : '' ?>>No salieron</option>
            </select>
        </form>
        <div class="tabla-wrap">
            <table class="tabla">
                <tr><th>Fecha</th><th>Para</th><th>Asunto</th><th>Estado</th></tr>
                <?php foreach ($filas as $f): ?>
                    <tr<?= $abierto && (int)$abierto['id'] === (int)$f['id'] ? ' style="background:var(--teal-50, #eef6f6)"' : '' ?>>
                        <td class="small" style="white-space:nowrap"><?= fecha($f['created_at']) ?></td>
                        <td><?= $f['cliente_id'] ? '<b>' . e(nombre_cliente($f)) . '</b><br>' : '' ?><span class="small"><?= e($f['para']) ?></span></td>
                        <td><a href="?<?= e(http_build_query(array_merge($_GET, ['ver' => (int)$f['id']]))) ?>"><?= e($f['asunto']) ?></a></td>
                        <td><?= $f['ok'] ? '<span class="badge badge-verde">Enviado</span>' : '<span class="badge badge-rojo">No salió</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filas): ?><tr><td colspan="4" class="muted"><?= $where ? 'No hay mails con ese filtro.' : 'Todavía no se registró ningún mail.' ?></td></tr><?php endif; ?>
            </table>
        </div>
        <?php if (count($filas) === 500): ?><p class="muted small">Se muestran los últimos 500. Buscá por email o asunto para ver más atrás.</p><?php endif; ?>
    </div>
    <aside class="card">
        <?php if ($abierto): ?>
            <h3 style="margin-top:0"><?= e($abierto['asunto']) ?></h3>
            <p class="small muted">Para <b><?= e($abierto['para']) ?></b> · <?= fecha($abierto['created_at']) ?></p>
            <?php if (!empty($abierto['html'])): ?>
                <?php // Tal cual le llegó al cliente. sandbox: sin scripts ni acceso al panel; los links abren en otra pestaña
                $vista = preg_replace('#<head>#i', '<head><base target="_blank">', $abierto['html'], 1); ?>
                <iframe srcdoc="<?= e($vista) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" title="Vista del mail"
                        style="width:100%;height:75vh;border:1px solid var(--line);border-radius:8px;background:#f4f6f6"></iframe>
            <?php else: ?>
                <p class="small muted">Este mail se registró antes de guardar el diseño: se ve solo el texto.</p>
                <div class="small" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($abierto['cuerpo'] ?? '') ?></div>
            <?php endif; ?>
        <?php else: ?>
            <h3 style="margin-top:0">Cómo leerlo</h3>
            <p class="small"><span class="badge badge-verde">Enviado</span> el servidor de correo aceptó el mail. Casi siempre llega, pero puede rebotar (mail mal escrito) o caer en spam: si el cliente dice que no le llegó, pedile que mire ahí.</p>
            <p class="small"><span class="badge badge-rojo">No salió</span> el servidor lo rechazó y el cliente no lo recibió.</p>
            <p class="small">Tocá un asunto para ver el mail tal como le llegó al cliente.</p>
        <?php endif; ?>
    </aside>
</div>

<?php admin_footer(); ?>
