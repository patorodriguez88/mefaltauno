<?php
require __DIR__ . '/_inc.php';

$claves = array_merge(array_map(fn($k) => "banco_$k", array_keys(DATOS_BANCARIOS)), ['banco_instrucciones']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['wepoint_probar', 'wepoint_sync'], true)) {
    csrf_exigir();
    try {
        if ($_POST['accion'] === 'wepoint_probar') {
            wepoint_token(true);
            [$filas] = wepoint_filas(wepoint_api('GET', 'v2/productos?per_page=1'));
            flash('ok', 'Conexión con WePoint OK ⚡');
        } else {
            $r = wepoint_sincronizar_stock();
            flash('ok', "Stock sincronizado: {$r['actualizados']} números actualizados (WePoint tiene {$r['wepoint']} productos).");
            if ($r['no_encontrados']) flash('info', count($r['no_encontrados']) . ' SKU no existen en WePoint: ' . implode(', ', array_slice($r['no_encontrados'], 0, 5)) . (count($r['no_encontrados']) > 5 ? '…' : ''));
            if ($r['sin_sku']) flash('info', "{$r['sin_sku']} números no tienen SKU cargado: no se pueden vincular.");
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/ajustes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'novedades') {
    csrf_exigir();
    q("REPLACE INTO ajustes (clave, valor) VALUES ('novedades_activo', ?)", [!empty($_POST['activo']) ? '1' : '0']);
    q("REPLACE INTO ajustes (clave, valor) VALUES ('novedades_titulo', ?)", [trim(mb_substr($_POST['titulo'] ?? '', 0, 120))]);
    q("REPLACE INTO ajustes (clave, valor) VALUES ('novedades_texto', ?)", [trim(mb_substr($_POST['texto'] ?? '', 0, 300))]);
    flash('ok', 'Ventana de novedades guardada.');
    redirect('admin/ajustes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'legales') {
    csrf_exigir();
    foreach (array_keys(DATOS_LEGALES) as $k) q("REPLACE INTO ajustes (clave, valor) VALUES (?,?)", ['legal_' . $k, trim(mb_substr($_POST[$k] ?? '', 0, 255))]);
    flash('ok', 'Datos legales guardados: se ven en el pie, los términos y la política de privacidad.');
    redirect('admin/ajustes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'recargo') {
    csrf_exigir();
    $v = max(0, (float)str_replace(['.', ','], ['', '.'], (string)($_POST['recargo_unidad'] ?? '0')));
    q("REPLACE INTO ajustes (clave, valor) VALUES ('recargo_unidad', ?)", [(string)$v]);
    flash('ok', 'Recargo interior actualizado: ' . precio($v) . ' por unidad.');
    redirect('admin/ajustes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $cbu = preg_replace('/\D/', '', $_POST['banco_cbu'] ?? '');
    if ($cbu !== '' && strlen($cbu) !== 22) {
        flash('error', 'El CBU/CVU tiene que tener 22 números (tiene ' . strlen($cbu) . ').');
        redirect('admin/ajustes.php');
    }
    $_POST['banco_cbu'] = $cbu;
    foreach ($claves as $k) {
        q("REPLACE INTO ajustes (clave, valor) VALUES (?,?)", [$k, trim(mb_substr($_POST[$k] ?? '', 0, 1000))]);
    }
    flash('ok', 'Ajustes guardados.');
    redirect('admin/ajustes.php');
}

admin_header('Ajustes', 'ajustes');
?>

<h1>Ajustes</h1>

<div class="layout-2">
    <div>
    <form method="post" class="card form">
        <?= csrf_field() ?>
        <h3>Datos para transferencia bancaria</h3>
        <p class="muted small" style="margin-top:-8px">Se muestran al cliente cuando elige “Transferencia bancaria”, en su pedido y en el mail de confirmación.</p>
        <div class="form-row">
            <label class="campo">Banco<input type="text" name="banco_banco" value="<?= e(ajuste('banco_banco')) ?>" placeholder="Ej: Banco Galicia"></label>
            <label class="campo">Titular de la cuenta<input type="text" name="banco_titular" value="<?= e(ajuste('banco_titular')) ?>" placeholder="Ej: Dinter S.A."></label>
        </div>
        <div class="form-row">
            <label class="campo">CUIT<input type="text" name="banco_cuit" value="<?= e(ajuste('banco_cuit')) ?>" placeholder="30-12345678-9"></label>
            <label class="campo">Alias<input type="text" name="banco_alias" value="<?= e(ajuste('banco_alias')) ?>" placeholder="mefaltauno.pagos"></label>
        </div>
        <label class="campo">CBU / CVU <small>(22 números)</small><input type="text" name="banco_cbu" value="<?= e(ajuste('banco_cbu')) ?>" inputmode="numeric"></label>
        <label class="campo">Instrucciones para el cliente <small>(opcional)</small>
            <textarea name="banco_instrucciones" placeholder="Ej: Enviá el comprobante respondiendo el mail del pedido. Reservamos los números por 48 h."><?= e(ajuste('banco_instrucciones')) ?></textarea></label>
        <button class="btn btn-primario" type="submit">Guardar</button>
    </form>
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="accion" value="novedades">
        <h3>Ventana de novedades</h3>
        <p class="muted small" style="margin-top:-8px">Aparece al entrar a la home con las colecciones <b>Próximamente</b> y las publicadas en los últimos 15 días. Cada visitante la ve una vez; vuelve a aparecer cuando hay novedades nuevas.</p>
        <?php $n_nov = (int)q("SELECT COUNT(*) FROM colecciones WHERE activa=1 AND (proximamente=1 OR created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY))")->fetchColumn(); ?>
        <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activo" value="1" <?= ajuste('novedades_activo', '1') === '1' ? 'checked' : '' ?>> Mostrar la ventana <small class="muted">(hoy hay <?= $n_nov ?> novedades<?= $n_nov ? '' : ': no se muestra' ?>)</small></label>
        <label class="campo">Título<input type="text" name="titulo" maxlength="120" value="<?= e(ajuste('novedades_titulo')) ?>" placeholder="Lo que llega a la base"></label>
        <label class="campo">Texto<input type="text" name="texto" maxlength="300" value="<?= e(ajuste('novedades_texto')) ?>" placeholder="Lanzamientos y colecciones que están por llegar…"></label>
        <button class="btn btn-primario" type="submit">Guardar</button>
    </form>
    <form method="post" class="card form">
        <?= csrf_field() ?><input type="hidden" name="accion" value="legales">
        <h3>Datos legales de la empresa</h3>
        <p class="muted small" style="margin-top:-8px">La ley de Defensa del Consumidor pide mostrarlos: van en el pie de la web, en los Términos y en la Política de privacidad.</p>
        <?php foreach (DATOS_LEGALES as $k => $lbl): ?>
            <label class="campo"><?= e($lbl) ?><input type="text" name="<?= $k ?>" value="<?= e(ajuste('legal_' . $k)) ?>" placeholder="<?= e(legal($k)) ?>"></label>
        <?php endforeach; ?>
        <button class="btn btn-primario" type="submit">Guardar datos legales</button>
    </form>
    </div>
    <aside>
        <form method="post" class="card form">
            <?= csrf_field() ?><input type="hidden" name="accion" value="recargo">
            <h3>Recargo interior</h3>
            <p class="muted small" style="margin-top:-8px">Monto fijo por unidad que se suma en el carrito (no cambia el precio que se ve en la tienda). Se puede cambiar para una colección o un número puntual desde su ficha.</p>
            <label class="campo">Por unidad<input type="text" name="recargo_unidad" inputmode="decimal" value="<?= e((string)recargo_general()) ?>"></label>
            <button class="btn btn-primario btn-chico" type="submit">Guardar recargo</button>
        </form>
        <div class="card">
            <h3>Así lo ve el cliente</h3>
            <?= html_datos_bancarios(19390) ?>
        </div>
        <div class="card">
            <h3>WePoint (stock y preparación)</h3>
            <?php if (!wepoint_listo()): ?>
                <p class="small"><span class="badge badge-gris">No conectado</span></p>
                <p class="muted small">Faltan el email y la contraseña de la API en <code>config.php</code>. Mientras tanto el stock se carga a mano en cada colección.</p>
            <?php else:
                $ult = json_decode(ajuste('wepoint_ultimo_resultado'), true) ?: []; ?>
                <p class="small"><span class="badge badge-verde">Configurado</span> <span class="muted"><?= e(preg_replace('#^https?://#', '', WEPOINT_URL)) ?></span>
                    <?= wepoint_ordenes_activas() ? '<span class="badge badge-azul">Envía órdenes</span>' : '<span class="badge badge-gris">Órdenes apagadas (modo prueba)</span>' ?></p>
                <p class="small muted" style="margin:0 0 12px">
                    Última sincronización: <b><?= ajuste('wepoint_ultima_sync') ? fecha(ajuste('wepoint_ultima_sync')) : 'nunca' ?></b>
                    <?php if ($ult): ?><br><?= (int)($ult['actualizados'] ?? 0) ?> números actualizados · <?= count($ult['no_encontrados'] ?? []) ?> SKU sin coincidencia · <?= (int)($ult['sin_sku'] ?? 0) ?> sin SKU<?php endif; ?>
                </p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="wepoint_sync"><button class="btn btn-teal btn-chico" type="submit">Sincronizar stock ahora</button></form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="wepoint_probar"><button class="btn btn-linea btn-chico" type="submit">Probar conexión</button></form>
                </div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php admin_footer(); ?>
