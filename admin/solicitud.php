<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);

// Reservas y vínculo con el catálogo (acciones por número pedido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['vincular', 'reservar', 'extender', 'liberar'], true)) {
    csrf_exigir();
    $acc = $_POST['accion'];
    try {
        $si = q("SELECT si.*, s.cliente_id FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id WHERE si.id=? AND si.solicitud_id=?", [(int)($_POST['si_id'] ?? 0), $id])->fetch();
        if (!$si) throw new Exception('Ese número ya no está en la búsqueda.');
        if ($acc === 'vincular') {
            $item = q("SELECT i.id FROM items i WHERE i.coleccion_id=? AND i.numero=?", [(int)($_POST['coleccion_id'] ?? 0), (int)($_POST['numero'] ?? 0)])->fetch();
            if (!$item) throw new Exception('No existe ese número en esa colección. Crealo primero (o desde “Por publicar”).');
            q("UPDATE solicitud_items SET item_id=? WHERE id=?", [$item['id'], $si['id']]);
            registrar_historial('solicitud', $id, q("SELECT estado FROM solicitudes WHERE id=?", [$id])->fetchColumn(), 'Vinculado al catálogo.', nombre_cliente($admin));
            $n = reservas_asignar();
            flash('ok', 'Vinculado al catálogo.' . ($n ? ' Había stock: ya quedó reservado y le avisamos.' : ' Cuando entre stock se le reserva solo.'));
        } elseif ($acc === 'reservar') {
            $it = q("SELECT id, stock, congelado, limite FROM items WHERE id=?", [$si['item_id']])->fetch();
            if (!$it) throw new Exception('Primero vinculá el número al catálogo.');
            if (reserva_de((int)$it['id'], (int)$si['cliente_id'])) throw new Exception('Ya tiene una reserva activa de ese número.');
            if (reservas_libres($it) < 1) throw new Exception('No hay unidades libres: todo el stock está reservado o es 0. Liberá otra reserva o esperá stock.');
            reserva_crear((int)$it['id'], (int)$si['cliente_id'], (int)$si['id'], 'manual', nombre_cliente($admin));
            flash('ok', 'Reservado por ' . RESERVA_HORAS . ' horas y avisado por mail.');
        } else {
            $r = q("SELECT * FROM reservas WHERE id=? AND solicitud_item_id=? AND estado='activa'", [(int)($_POST['reserva_id'] ?? 0), $si['id']])->fetch();
            if (!$r) throw new Exception('La reserva ya no está activa.');
            if ($acc === 'extender') {
                q("UPDATE reservas SET vence_at = DATE_ADD(GREATEST(vence_at, NOW()), INTERVAL " . RESERVA_HORAS . " HOUR) WHERE id=?", [$r['id']]);
                registrar_historial('solicitud', $id, 'conseguido', 'Extendimos la reserva ' . RESERVA_HORAS . ' horas más.', nombre_cliente($admin));
                flash('ok', 'Reserva extendida ' . RESERVA_HORAS . ' horas.');
            } else {
                q("UPDATE reservas SET estado='liberada' WHERE id=?", [$r['id']]);
                registrar_historial('solicitud', $id, 'conseguido', 'Se liberó la reserva: el número volvió a la venta.', nombre_cliente($admin));
                reservas_mapa(true);
                $n = reservas_asignar();
                flash('ok', 'Reserva liberada.' . ($n ? ' Pasó a la siguiente búsqueda en espera.' : ''));
            }
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/solicitud.php?id=' . $id);
}

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
$reservas = [];
foreach (q("SELECT r.* FROM reservas r JOIN solicitud_items si ON si.id=r.solicitud_item_id WHERE si.solicitud_id=? ORDER BY r.created_at DESC", [$id]) as $r) {
    $reservas[(int)$r['solicitud_item_id']][] = $r;
}
$cols = q("SELECT id, nombre FROM colecciones ORDER BY nombre")->fetchAll();
$ESTADO_RESERVA = ['activa' => ['Reservado', 'badge-verde'], 'usada' => ['Comprado', 'badge-azul'], 'vencida' => ['Venció', 'badge-gris'], 'liberada' => ['Liberada', 'badge-gris']];

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
                    <?= foto($i['imagen'] ?? '', $i['item_id'] ? '📦' : '🔎') ?>
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
                <div class="reserva-box">
                    <?php if (!$i['item_id']): ?>
                        <form method="post" class="reserva-vincular">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="vincular"><input type="hidden" name="si_id" value="<?= (int)$i['id'] ?>">
                            <span class="small muted">¿Ya está en la tienda? Vinculalo y se le reserva cuando haya stock:</span>
                            <select name="coleccion_id" required><option value="">Colección…</option><?php foreach ($cols as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select>
                            <input type="number" name="numero" min="1" placeholder="N°" required style="width:80px">
                            <button class="btn btn-teal btn-chico" type="submit">Vincular</button>
                        </form>
                    <?php else: ?>
                        <?php $activa = null; foreach ($reservas[(int)$i['id']] ?? [] as $r) { if ($r['estado'] === 'activa' && strtotime($r['vence_at']) > time()) { $activa = $r; break; } } ?>
                        <?php if ($activa): ?>
                            <span class="badge badge-verde">Reservado hasta <?= e(fecha($activa['vence_at'])) ?></span>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="si_id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="reserva_id" value="<?= (int)$activa['id'] ?>">
                                <button class="btn-texto" name="accion" value="extender">+<?= RESERVA_HORAS ?> h</button>
                                <button class="btn-texto" name="accion" value="liberar" onclick="return confirm('¿Liberar la reserva? El número vuelve a la venta o pasa al siguiente que lo busca.')">Liberar</button>
                            </form>
                        <?php else: ?>
                            <span class="small muted"><?= ($reservas[(int)$i['id']] ?? []) ? 'Sin reserva activa.' : 'Se reserva solo cuando haya stock (' . RESERVA_HORAS . ' h).' ?></span>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="reservar"><input type="hidden" name="si_id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-linea btn-chico" type="submit">Reservar ahora</button></form>
                        <?php endif; ?>
                        <?php foreach (array_slice($reservas[(int)$i['id']] ?? [], $activa ? 1 : 0) as $r): [$lbl, $cls] = $ESTADO_RESERVA[$r['estado']] ?? [$r['estado'], 'badge-gris']; ?>
                            <span class="small muted">· <?= e($lbl) ?> (<?= e(fecha($r['created_at'])) ?><?= $r['origen'] === 'manual' ? ', a mano' : '' ?>)</span>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
