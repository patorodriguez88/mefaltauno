<?php
require __DIR__ . '/_inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'activo') {
        q("UPDATE cupones SET activo = 1 - activo WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        redirect('admin/cupones.php');
    }
    try {
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['codigo'] ?? ''));
        if ($codigo === '') $codigo = 'HEROE' . strtoupper(bin2hex(random_bytes(3)));
        $tipo = ($_POST['tipo'] ?? '') === 'importe' ? 'importe' : 'porcentaje';
        $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
        if ($valor <= 0) throw new Exception('Indicá el valor del descuento.');
        if ($tipo === 'porcentaje' && $valor > 100) throw new Exception('El porcentaje no puede pasar de 100.');
        $email = trim($_POST['cliente_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('El email del cliente no es válido.');
        if (q("SELECT 1 FROM cupones WHERE codigo=?", [$codigo])->fetchColumn()) throw new Exception("Ya existe el código $codigo.");
        q("INSERT INTO cupones (codigo, tipo, valor, minimo, usos_max, uno_por_cliente, cliente_email, vence, nota) VALUES (?,?,?,?,?,?,?,?,?)", [
            $codigo, $tipo, $valor,
            trim($_POST['minimo'] ?? '') !== '' ? (float)str_replace(['.', ','], ['', '.'], $_POST['minimo']) : null,
            trim($_POST['usos_max'] ?? '') !== '' ? max(1, (int)$_POST['usos_max']) : null,
            !empty($_POST['uno_por_cliente']) ? 1 : 0,
            $email ?: null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['vence'] ?? '') ? $_POST['vence'] : null,
            trim(mb_substr($_POST['nota'] ?? '', 0, 255)) ?: null,
        ]);
        flash('ok', "Código $codigo creado. Copiá el mensaje y mandáselo al cliente.");
        redirect('admin/cupones.php?nuevo=' . urlencode($codigo));
    } catch (Exception $e) {
        flash('error', $e->getMessage());
        redirect('admin/cupones.php');
    }
}

$cupones = q("SELECT c.*, (SELECT COALESCE(SUM(descuento),0) FROM pedidos p WHERE p.cupon_codigo=c.codigo AND p.estado<>'cancelado') AS descontado
              FROM cupones c ORDER BY c.activo DESC, c.created_at DESC")->fetchAll();

// Mensaje listo para mandar por WhatsApp o mail
function mensaje_cupon(array $c): string {
    $m = '¡Hola! Tenemos un refuerzo para tu colección 🦸 Usá el código ' . $c['codigo'] . ' y obtené ' . cupon_etiqueta($c)
       . ' en tu próxima compra en ' . preg_replace('#^https?://#', '', SITE_URL) . '.';
    if ($c['minimo']) $m .= ' Válido en compras desde ' . precio((float)$c['minimo']) . '.';
    if ($c['vence']) $m .= ' Vence el ' . fecha($c['vence'], false) . '.';
    return $m . ' Ningún héroe queda atrás ⚡';
}

admin_header('Descuentos', 'cupones');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Códigos de descuento</h1>
        <p class="muted" style="margin:0">Por porcentaje o por importe fijo. Se aplican en el carrito.</p>
    </div>
</div>

<div class="layout-cupones">
    <div class="tabla-wrap">
        <table class="tabla">
            <tr><th>Código</th><th>Descuento</th><th>Condiciones</th><th>Usos</th><th>Descontado</th><th>Estado</th><th></th></tr>
            <?php foreach ($cupones as $c):
                $vencido = $c['vence'] && $c['vence'] < date('Y-m-d');
                $agotado = $c['usos_max'] !== null && $c['usos'] >= $c['usos_max']; ?>
                <tr <?= ($_GET['nuevo'] ?? '') === $c['codigo'] ? 'style="background:#fffdea"' : '' ?>>
                    <td><b><?= e($c['codigo']) ?></b><?php if ($c['nota']): ?><div class="muted small"><?= e($c['nota']) ?></div><?php endif; ?></td>
                    <td><?= e(cupon_etiqueta($c)) ?></td>
                    <td class="small">
                        <?= $c['minimo'] ? 'Desde ' . precio((float)$c['minimo']) . '<br>' : '' ?>
                        <?= $c['vence'] ? 'Vence ' . fecha($c['vence'], false) . '<br>' : '' ?>
                        <?= $c['cliente_email'] ? 'Solo ' . e($c['cliente_email']) . '<br>' : '' ?>
                        <?= $c['uno_por_cliente'] ? 'Una vez por cliente' : '' ?>
                    </td>
                    <td><?= (int)$c['usos'] ?><?= $c['usos_max'] !== null ? ' / ' . (int)$c['usos_max'] : '' ?></td>
                    <td><?= precio((float)$c['descontado']) ?></td>
                    <td><?= !$c['activo'] ? '<span class="badge badge-gris">Pausado</span>' : ($vencido ? '<span class="badge badge-rojo">Vencido</span>' : ($agotado ? '<span class="badge badge-gris">Agotado</span>' : '<span class="badge badge-verde">Activo</span>')) ?></td>
                    <td class="acciones-cupon">
                        <button type="button" class="btn-copiar" data-copiar="<?= e(mensaje_cupon($c)) ?>" data-label="Mensaje">Copiar mensaje</button>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn-texto small" type="submit"><?= $c['activo'] ? 'Pausar' : 'Activar' ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cupones): ?><tr><td colspan="7" class="muted">Todavía no hay códigos. Creá el primero →</td></tr><?php endif; ?>
        </table>
    </div>

    <form method="post" class="card form">
        <?= csrf_field() ?>
        <h3>Nuevo código</h3>
        <label class="campo">Código <small>(vacío = se genera solo)</small><input type="text" name="codigo" placeholder="Ej: VUELVEHEROE" style="text-transform:uppercase" maxlength="40"></label>
        <div class="form-row">
            <label class="campo">Tipo
                <select name="tipo"><option value="porcentaje">Porcentaje (%)</option><option value="importe">Importe fijo ($)</option></select></label>
            <label class="campo">Valor<input type="text" name="valor" inputmode="decimal" placeholder="Ej: 15" required></label>
        </div>
        <div class="form-row">
            <label class="campo">Compra mínima <small>(opcional)</small><input type="text" name="minimo" inputmode="decimal" placeholder="$"></label>
            <label class="campo">Vence <small>(opcional)</small><input type="date" name="vence"></label>
        </div>
        <div class="form-row">
            <label class="campo">Usos máximos <small>(total)</small><input type="number" name="usos_max" min="1" placeholder="Sin límite"></label>
            <label class="campo">Solo para <small>(email, opcional)</small><input type="email" name="cliente_email" placeholder="cliente@mail.com"></label>
        </div>
        <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="uno_por_cliente" value="1" checked> Una sola vez por cliente</label>
        <label class="campo">Nota interna <small>(opcional)</small><input type="text" name="nota" placeholder="Ej: compensación pedido #12"></label>
        <button class="btn btn-primario" type="submit">Crear código</button>
    </form>
</div>

<?php admin_footer(); ?>
