<?php
// Botón de arrepentimiento (Res. 424/2020 SCI): el cliente revoca su compra dentro de los 10 días y recibe
// al instante un código de trámite. No hace falta iniciar sesión (la norma pide que sea simple y directo).
require __DIR__ . '/inc/bootstrap.php';

$cli = cliente();
$pedidos = $cli ? q("SELECT id, created_at, total FROM pedidos WHERE cliente_id=? AND estado<>'cancelado' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY id DESC", [$cli['id']])->fetchAll() : [];
$d = ['nombre' => $cli ? nombre_cliente($cli) : '', 'email' => $cli['email'] ?? '', 'telefono' => $cli['telefono'] ?? '', 'pedido' => (string)($_GET['pedido'] ?? ''), 'motivo' => ''];
$errores = [];
$codigo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    if (trim($_POST['web'] ?? '') !== '') redirect('');   // trampa para bots: campo invisible que una persona no completa
    foreach ($d as $k => $_) $d[$k] = trim(mb_substr((string)($_POST[$k] ?? ''), 0, $k === 'motivo' ? 2000 : 160));
    if ($d['nombre'] === '') $errores[] = 'Indicá tu nombre.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'Indicá un mail válido: ahí te mandamos el código de trámite.';
    $pid = (int)preg_replace('/\D/', '', $d['pedido']);
    if (!$pid) $errores[] = 'Indicá el número de pedido (lo encontrás en el mail de confirmación o en Mi cuenta).';
    // Freno simple: 3 pedidos por sesión cada 30 minutos
    $intentos = array_filter($_SESSION['arrep_intentos'] ?? [], fn($t) => $t > time() - 1800);
    if (!$errores && count($intentos) >= 3) $errores[] = 'Ya enviaste varios pedidos. Esperá unos minutos o escribinos a ' . MAIL_OPERADOR . '.';
    // El pedido tiene que ser de ese mail (o del cliente con sesión): así el formulario no sirve para
    // mandarle mails a cualquiera, que es lo que buscan los bots que lo completan con publicidad.
    $p = null;
    if (!$errores) {
        $_SESSION['arrep_intentos'] = array_merge($intentos, [time()]);
        $p = q("SELECT p.id, p.estado, p.cliente_id, c.email, c.nombre, c.apellido FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$pid])->fetch();
        if (!$p || (($cli['id'] ?? 0) != $p['cliente_id'] && strcasecmp($p['email'], $d['email']) !== 0)) {
            $p = null;
            $errores[] = 'No encontramos ese número de pedido con ese mail. Usá el mail con el que hiciste la compra.';
        }
    }
    if (!$errores) {
        $codigo = 'ARR-' . strtoupper(bin2hex(random_bytes(3)));
        q("INSERT INTO arrepentimientos (codigo, cliente_id, pedido_id, nombre, email, telefono, motivo) VALUES (?,?,?,?,?,?,?)",
          [$codigo, $p['cliente_id'], $pid, $d['nombre'], $p['email'], $d['telefono'] ?: null, $d['motivo'] ?: null]);
        registrar_historial('pedido', $pid, $p['estado'], "Botón de arrepentimiento: el cliente pidió revocar la compra (código $codigo).", nombre_cliente($p));
        // El mail va siempre a la cuenta del pedido y con el nombre de la cuenta, no con lo que se escribió en el formulario
        mail_enviar($p['email'], "Recibimos tu arrepentimiento · código $codigo",
            '<p>Hola ' . e($p['nombre']) . ',</p><p>Recibimos tu pedido de revocación de la compra <b>#' . $pid . '</b>. Tu <b>código de trámite</b> es:</p>'
            . '<p style="font-size:22px;font-weight:bold;letter-spacing:1px">' . e($codigo) . '</p>'
            . '<p>Te vamos a contactar para coordinar la devolución del producto y el reintegro del importe por el mismo medio de pago.</p>');
        mail_enviar(MAIL_OPERADOR, "Arrepentimiento $codigo · pedido #$pid",
            '<p><b>' . e($d['nombre']) . '</b> (' . e($p['email']) . ($d['telefono'] ? ' · ' . e($d['telefono']) : '') . ') usó el botón de arrepentimiento para el pedido <b>#' . $pid . '</b>.</p>'
            . ($d['motivo'] ? '<p>Motivo: ' . nl2br(e($d['motivo'])) . '</p>' : '') . mail_link('admin/pedido.php?id=' . $pid, 'Ver el pedido'));
    }
}

$titulo = 'Botón de arrepentimiento';
require __DIR__ . '/inc/header.php';
?>
<section class="section pagina-legal">
    <div class="container" style="max-width:640px">
        <h1>Botón de arrepentimiento</h1>
        <?php if ($codigo): ?>
            <div class="card" style="text-align:center">
                <div class="modal-icono" style="margin:0 auto">✅</div>
                <h3>Recibimos tu pedido de revocación</h3>
                <p>Tu código de trámite es</p>
                <p style="font-size:1.8rem;font-weight:900;letter-spacing:.05em;margin:0"><?= e($codigo) ?></p>
                <p class="muted">Te lo mandamos también a <b><?= e($d['email']) ?></b>. Te vamos a contactar para coordinar la devolución y el reintegro.</p>
                <a class="btn btn-primario" href="<?= url('') ?>">Volver a la tienda</a>
            </div>
        <?php else: ?>
            <p>Si compraste en MeFaltaUno, podés <b>revocar tu compra dentro de los <?= DIAS_ARREPENTIMIENTO ?> días corridos</b> desde que recibiste el producto, sin costo (art. 34 de la Ley 24.240). Completá el formulario y te mandamos al instante un <b>código de trámite</b>. Más detalles en los <a href="<?= url('terminos.php#arrepentimiento') ?>">Términos y condiciones</a>.</p>
            <?php foreach ($errores as $er): ?><div class="flash flash-error" style="margin-bottom:8px"><?= e($er) ?></div><?php endforeach; ?>
            <form method="post" class="card form">
                <?= csrf_field() ?>
                <input type="text" name="web" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
                <div class="form-row">
                    <label class="campo">Nombre y apellido<input type="text" name="nombre" value="<?= e($d['nombre']) ?>" required autocomplete="name"></label>
                    <label class="campo">Teléfono <small>(opcional)</small><input type="tel" name="telefono" value="<?= e($d['telefono']) ?>" autocomplete="tel"></label>
                </div>
                <label class="campo">Mail <small>(el de tu compra: te mandamos el código acá)</small><input type="email" name="email" value="<?= e($d['email']) ?>" required autocomplete="email"></label>
                <label class="campo">Número de pedido
                    <?php if ($pedidos): ?>
                        <select name="pedido" required>
                            <option value="">Elegí…</option>
                            <?php foreach ($pedidos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === $d['pedido'] ? 'selected' : '' ?>>#<?= (int)$p['id'] ?> · <?= e(fecha($p['created_at'])) ?> · <?= precio((float)$p['total']) ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="pedido" value="<?= e($d['pedido']) ?>" inputmode="numeric" placeholder="Ej: 1234" required>
                    <?php endif; ?>
                </label>
                <label class="campo">Motivo <small>(opcional, nos ayuda a mejorar)</small><textarea name="motivo"><?= e($d['motivo']) ?></textarea></label>
                <button class="btn btn-primario" type="submit">Revocar mi compra</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
