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
    <aside>
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
                <p class="small"><span class="badge badge-verde">Configurado</span> <span class="muted"><?= e(preg_replace('#^https?://#', '', WEPOINT_URL)) ?></span></p>
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
