<?php
require __DIR__ . '/_inc.php';

$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'wepoint') {
    csrf_exigir();
    try {
        $oid = wepoint_crear_orden($id);
        flash('ok', "Orden enviada a WePoint ($oid).");
    } catch (Exception $e) {
        flash('error', 'WePoint: ' . $e->getMessage());
    }
    redirect('admin/pedido.php?id=' . $id);
}

// Encargue a Brihet: el operador marca en qué quedó cada línea (falta pedir → pedido → recibido en depósito)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'encargue') {
    csrf_exigir();
    $nuevo = $_POST['encargue_estado'] ?? '';
    $l = q("SELECT * FROM pedido_items WHERE id=? AND pedido_id=? AND encargue>0", [(int)($_POST['linea_id'] ?? 0), $id])->fetch();
    if ($l && isset(ESTADOS_ENCARGUE[$nuevo]) && $nuevo !== $l['encargue_estado']) {
        q("UPDATE pedido_items SET encargue_estado=? WHERE id=?", [$nuevo, $l['id']]);
        $estado = q("SELECT estado FROM pedidos WHERE id=?", [$id])->fetchColumn();
        registrar_historial('pedido', $id, $estado, 'Encargue ' . $l['coleccion'] . ' ' . num((int)$l['numero']) . ' ×' . (int)$l['encargue'] . ': ' . ESTADOS_ENCARGUE[$nuevo][0] . '.', nombre_cliente($admin));
        $faltan = (int)q("SELECT COUNT(*) FROM pedido_items WHERE pedido_id=? AND encargue>0 AND encargue_estado<>'recibido'", [$id])->fetchColumn();
        flash('ok', ESTADOS_ENCARGUE[$nuevo][0] . '.' . (!$faltan ? ' Ya llegó todo lo de Brihet: podés mandar el pedido a WePoint.' : ''));
    }
    redirect('admin/pedido.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'caddy') {
    csrf_exigir();
    try {
        $seg = caddy_crear_envio($id);
        flash('ok', "Envío creado en Caddy ($seg).");
    } catch (Exception $e) {
        flash('error', 'Caddy: ' . $e->getMessage());
    }
    redirect('admin/pedido.php?id=' . $id);
}

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
$lineas = q("SELECT pi.*, i.imagen, i.stock, i.brihet_stock, i.brihet_stock_at FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$id])->fetchAll();
$encargues = array_values(array_filter($lineas, fn($l) => (int)$l['encargue'] > 0));
$enc_pend = array_sum(array_map(fn($l) => $l['encargue_estado'] !== 'recibido' ? (int)$l['encargue'] : 0, $encargues));
$hist = historial('pedido', $id);

admin_header('Pedido #' . $id, 'pedidos');
?>

<p><a href="<?= url('admin/pedidos.php') ?>">← Pedidos</a></p>
<div class="section-head">
    <h1 style="margin:0">Pedido #<?= $id ?></h1>
    <?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?>
</div>

<?php if ($encargues): ?>
    <div class="card encargue-panel <?= $enc_pend ? 'pendiente' : 'listo' ?>">
        <div class="encargue-head">
            <h3 style="margin:0">📦 Encargue a Brihet</h3>
            <?php if ($enc_pend): ?><span class="badge badge-rojo"><?= $enc_pend ?> unidades por conseguir</span><?php else: ?><span class="badge badge-verde">Todo recibido en depósito</span><?php endif; ?>
        </div>
        <p class="muted small" style="margin:6px 0 12px">Estas unidades se vendieron con el stock de Brihet (entrega sujeta a los plazos del editor). Pedíselas a Brihet y marcá cada paso.
            <?= $enc_pend ? 'La orden a WePoint no sale hasta que esté todo <b>recibido en depósito</b>.' : 'Ya podés mandar el pedido a WePoint.' ?></p>
        <div class="tabla-wrap"><table class="tabla">
            <tr><th>Número</th><th>Por encargue</th><th>Brihet tiene</th><th>Estado</th></tr>
            <?php foreach ($encargues as $l): ?>
                <tr>
                    <td><b><?= e($l['coleccion']) ?> <?= num((int)$l['numero']) ?></b><div class="small muted"><?= e($l['titulo']) ?></div></td>
                    <td><b><?= (int)$l['encargue'] ?></b> <span class="muted small">de <?= (int)$l['cantidad'] ?></span></td>
                    <td class="small"><?= $l['brihet_stock'] !== null ? (int)$l['brihet_stock'] . '<div class="muted">' . e(fecha($l['brihet_stock_at'])) . '</div>' : '—' ?></td>
                    <td>
                        <form method="post" class="encargue-form"><?= csrf_field() ?><input type="hidden" name="accion" value="encargue"><input type="hidden" name="linea_id" value="<?= (int)$l['id'] ?>">
                            <select name="encargue_estado" onchange="this.form.submit()">
                                <?php foreach (ESTADOS_ENCARGUE as $k => [$lbl]): ?><option value="<?= $k ?>" <?= $l['encargue_estado'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
<?php endif; ?>

<div class="layout-2">
    <div>
        <div class="card">
            <?php foreach ($lineas as $l): ?>
                <div class="linea">
                    <?= foto($l['imagen'] ?? '') ?>
                    <div>
                        <div class="linea-sub"><?= e($l['coleccion']) ?> · <?= num((int)$l['numero']) ?></div>
                        <div class="linea-titulo"><?= e($l['titulo']) ?></div>
                        <div class="linea-sub"><?= (int)$l['cantidad'] ?> × <?= precio((float)$l['precio']) ?>
                            <?php if ((int)$l['encargue'] > 0): ?> · <?= (int)$l['fisico'] ? (int)$l['fisico'] . ' en depósito · ' : '' ?><b class="txt-encargue"><?= (int)$l['encargue'] ?> por encargue</b><?php endif; ?></div>
                    </div>
                    <div class="linea-der"><strong><?= precio($l['precio'] * $l['cantidad']) ?></strong></div>
                </div>
            <?php endforeach; ?>
            <?php if ((float)$p['descuento'] > 0): ?>
                <div class="resumen-fila descuento"><span>Código <b><?= e($p['cupon_codigo']) ?></b></span><span>−<?= precio((float)$p['descuento']) ?></span></div>
            <?php endif; ?>
            <?= fila_recargo((float)$p['recargo'], (int)array_sum(array_column($lineas, 'cantidad'))) ?>
            <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio((float)$p['total']) ?></span></div>
        </div>

        <div class="card">
            <h3>Cliente y retiro</h3>
            <p class="small" style="margin:0">
                <b><?= e(nombre_cliente($p)) ?></b> · <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a> · <?= e($p['telefono'] ?: '') ?><br><br>
                <b><?= e($p['envio_punto'] ? 'Retira en ' . $p['envio_punto'] : (ENVIO_METODOS[$p['envio_metodo']] ?? '')) ?></b><br>
                <?= e($p['envio_direccion']) ?>, <?= e($p['envio_localidad']) ?> <?= e($p['envio_provincia']) ?><br>
                Retira: <?= e($p['envio_nombre']) ?> (<?= e($p['envio_telefono']) ?>)<br><br>
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
            <p class="muted small" style="margin:0">“Listo para retirar” le avisa que pase a buscarlo. Cancelar devuelve el stock. Retirado suma los números a la colección del cliente.</p>
            <button class="btn btn-primario" type="submit">Guardar</button>
        </form>
        <div class="card">
            <h3>WePoint</h3>
            <?php if (!$p['wepoint_orden_id'] && $enc_pend): ?>
                <p class="small" style="margin:0"><span class="badge badge-amarillo">Esperando encargue</span><br><span class="muted">Faltan <?= $enc_pend ?> unidades de Brihet. Cuando estén todas recibidas en depósito, mandá la orden desde acá.</span></p>
            <?php elseif ($p['wepoint_orden_id']): ?>
                <p class="small" style="margin:0"><span class="badge badge-verde">Orden enviada</span> <b>id <?= e($p['wepoint_orden_id']) ?></b> · ref MFU-<?= (int)$p['id'] ?><br>
                    Estado en WePoint: <b><?= e($p['wepoint_estado'] ?: 'Emitida') ?></b><br>
                    <span class="muted">Se actualiza solo con el cron (cada 5 min) o desde Admin → WePoint.</span></p>
            <?php elseif (!wepoint_listo()): ?>
                <p class="muted small" style="margin:0">WePoint todavía no está conectado. Cuando lo esté, la orden se envía sola al marcar “Pago confirmado”.</p>
            <?php elseif (!wepoint_ordenes_activas()): ?>
                <p class="muted small" style="margin:0"><span class="badge badge-gris">Envío de órdenes apagado</span><br>Modo prueba: los pedidos no se mandan al depósito. Se activa con <code>WEPOINT_CREAR_ORDENES</code> en config.php.</p>
            <?php else: ?>
                <?php if ($p['wepoint_error']): ?><div class="aviso" style="background:var(--red-100);color:var(--red);margin-bottom:10px"><?= e($p['wepoint_error']) ?></div><?php endif; ?>
                <p class="muted small">La orden se envía sola al marcar “Pago confirmado”. Si falló o querés mandarla ahora:</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="wepoint"><button class="btn btn-teal btn-chico" type="submit">Enviar a WePoint</button></form>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Caddy</h3>
            <?php if ($p['caddy_seguimiento']): ?>
                <p class="small" style="margin:0"><span class="badge badge-verde">Envío creado</span> Seguimiento <b><?= e($p['caddy_seguimiento']) ?></b><br>
                    <span class="muted">Creado el <?= e(fecha($p['caddy_creado_at'])) ?> hacia el kiosco <?= e($p['envio_punto']) ?>.</span></p>
            <?php elseif (!caddy_listo()): ?>
                <p class="muted small" style="margin:0">Caddy todavía no está conectado (CADDY_URL / CADDY_USUARIO en config.php).</p>
            <?php elseif (!caddy_envios_activos()): ?>
                <p class="muted small" style="margin:0"><span class="badge badge-gris">Creación de envíos apagada</span><br>Se activa con <code>CADDY_CREAR_ENVIOS</code> en config.php<?= stripos(CADDY_URL, 'sandbox') !== false ? ' (apunta a sandbox)' : '' ?>.</p>
            <?php else: ?>
                <?php if ($p['caddy_error']): ?><div class="aviso" style="background:var(--red-100);color:var(--red);margin-bottom:10px"><?= e($p['caddy_error']) ?></div><?php endif; ?>
                <p class="muted small">El envío se crea solo cuando WePoint deja el pedido “Listo para enviar”<?= stripos(CADDY_URL, 'sandbox') !== false ? ' (sandbox)' : '' ?>. Si falló o querés crearlo ahora:</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="caddy"><button class="btn btn-teal btn-chico" type="submit">Crear envío en Caddy</button></form>
            <?php endif; ?>
        </div>
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
