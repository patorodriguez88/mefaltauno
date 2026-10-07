<?php
require __DIR__ . '/_inc.php';

$claves = array_merge(array_map(fn($k) => "banco_$k", array_keys(DATOS_BANCARIOS)), ['banco_instrucciones']);

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
    <aside class="card">
        <h3>Así lo ve el cliente</h3>
        <?= html_datos_bancarios(19390) ?>
    </aside>
</div>

<?php admin_footer(); ?>
