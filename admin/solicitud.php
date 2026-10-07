<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    try {
        solicitud_cambiar_estado($id, $_POST['estado'] ?? '', $_POST['respuesta'] ?? '', !empty($_POST['avisar']), nombre_cliente($admin));
        flash('ok', 'Solicitud actualizada' . (!empty($_POST['avisar']) ? ' y cliente avisado por mail.' : '.'));
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/solicitud.php?id=' . $id);
}

$s = q("SELECT s.*, c.nombre, c.apellido, c.email, c.telefono FROM solicitudes s JOIN clientes c ON c.id=s.cliente_id WHERE s.id=?", [$id])->fetch();
if (!$s) redirect('admin/faltantes.php');
$its = q("SELECT si.*, i.numero, i.stock, i.imagen, c.nombre AS coleccion, c.id AS coleccion_id
          FROM solicitud_items si LEFT JOIN items i ON i.id=si.item_id LEFT JOIN colecciones c ON c.id=i.coleccion_id
          WHERE si.solicitud_id=?", [$id])->fetchAll();
$hist = historial('solicitud', $id);

admin_header('Faltante #' . $id, 'faltantes');
?>

<p><a href="<?= url('admin/faltantes.php') ?>">← Me faltan</a></p>
<div class="section-head">
    <h1 style="margin:0">Pedido de faltantes #<?= $id ?></h1>
    <?= badge_estado($s['estado'], ESTADOS_SOLICITUD) ?>
</div>

<div class="layout-2">
    <div>
        <div class="card">
            <h3>Qué le falta</h3>
            <?php foreach ($its as $i): ?>
                <div class="linea">
                    <img src="<?= e($i['imagen'] ?? '') ?>" alt="" loading="lazy">
                    <div>
                        <div class="linea-titulo"><?= e($i['descripcion']) ?></div>
                        <div class="linea-sub"><?= $i['item_id'] ? 'Del catálogo' : 'Pedido libre (no está en el catálogo)' ?></div>
                    </div>
                    <div class="linea-der">
                        <?php if ($i['item_id']): ?>
                            <span class="badge <?= $i['stock'] > 0 ? 'badge-verde' : 'badge-gris' ?>">Stock: <?= (int)$i['stock'] ?></span>
                            <a class="small" href="<?= url('admin/coleccion.php?id=' . (int)$i['coleccion_id']) ?>">Editar stock</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($s['mensaje']): ?><div class="aviso" style="margin-top:12px"><b>Comentario del cliente:</b> <?= nl2br(e($s['mensaje'])) ?></div><?php endif; ?>
        </div>
        <div class="card">
            <h3>Cliente</h3>
            <p class="small" style="margin:0"><b><?= e(nombre_cliente($s)) ?></b><br>
                <a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a><?= $s['telefono'] ? ' · ' . e($s['telefono']) : '' ?><br>
                Pedido el <?= fecha($s['created_at']) ?></p>
        </div>
    </div>

    <aside>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <h3>Responder</h3>
            <label class="campo">Estado
                <select name="estado">
                    <?php foreach (ESTADOS_SOLICITUD as $k => [$l]): ?><option value="<?= $k ?>" <?= $s['estado'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="campo">Mensaje para el cliente <small>(lo ve en su cuenta y le llega por mail)</small>
                <textarea name="respuesta" placeholder="Ej: ¡Lo conseguimos! Lo cargamos en la web para que lo compres."><?= e($s['respuesta']) ?></textarea></label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="avisar" value="1" checked> Avisarle por mail</label>
            <button class="btn btn-primario" type="submit">Guardar</button>
        </form>
        <div class="card">
            <h3>Historial</h3>
            <ul class="timeline">
                <?php foreach ($hist as $h): ?>
                    <li><b><?= e(ESTADOS_SOLICITUD[$h['estado']][0] ?? $h['estado']) ?></b>
                        <div class="cuando"><?= fecha($h['created_at']) ?> · <?= e($h['usuario']) ?></div>
                        <?php if ($h['nota']): ?><div class="small"><?= nl2br(e($h['nota'])) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
</div>

<?php admin_footer(); ?>
