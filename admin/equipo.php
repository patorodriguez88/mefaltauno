<?php
// Equipo: quiénes entran al panel. Solo para maestros (ADMIN_EMAILS en config.php), que agregan y
// quitan operadores (clientes.es_operador). Al operador nuevo le llega un mail para elegir su contraseña.
require __DIR__ . '/_inc.php';

if (!es_maestro($admin)) {
    flash('error', 'El equipo lo manejan los maestros del panel.');
    redirect('admin/');
}
$quien = trim($admin['nombre'] . ' ' . $admin['apellido']) ?: $admin['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'agregar') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $nombre = trim(mb_substr($_POST['nombre'] ?? '', 0, 80));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Revisá el mail.');
            if (in_array($email, maestros_emails(), true)) throw new Exception('Ese mail ya es maestro: tiene acceso a todo.');
            $c = q("SELECT * FROM clientes WHERE email=?", [$email])->fetch();
            if ($c && $c['es_operador']) throw new Exception('Ya es operador.');
            if ($c) {
                q("UPDATE clientes SET es_operador=1 WHERE id=?", [$c['id']]);
                mail_enviar($email, 'Te sumaron al equipo de MeFaltaUno',
                    '<p>Hola ' . e($c['nombre']) . ', ' . e($quien) . ' te dio acceso al panel de MeFaltaUno. Entrás con tu mail y tu contraseña de siempre.</p>'
                    . mail_link('admin/', 'Ir al panel'));
                flash('ok', "$email ya era cliente: ahora también es operador. Le avisamos por mail.");
            } else {
                if ($nombre === '') throw new Exception('Para alguien sin cuenta en la tienda, poné también su nombre.');
                // Contraseña al azar que nadie conoce: la elige con el link de la invitación
                q("INSERT INTO clientes (nombre, apellido, email, password_hash, es_operador) VALUES (?, '', ?, ?, 1)",
                  [$nombre, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                $c = q("SELECT * FROM clientes WHERE id=?", [db()->lastInsertId()])->fetch();
                clave_mail_invitacion($c, $quien);
                flash('ok', "Invitamos a $email: le llegó un mail para elegir su contraseña (vence en " . CLAVES_HORAS['invitacion'] . ' h).');
            }
        } elseif ($accion === 'quitar' || $accion === 'reenviar') {
            $c = q("SELECT * FROM clientes WHERE id=? AND es_operador=1", [(int)($_POST['id'] ?? 0)])->fetch();
            if (!$c) throw new Exception('Ese operador no existe.');
            if ($accion === 'quitar') {
                if ((int)$c['id'] === (int)$admin['id']) throw new Exception('No te podés quitar a vos mismo.');
                q("UPDATE clientes SET es_operador=0 WHERE id=?", [$c['id']]);
                q("UPDATE claves_links SET usado_at=NOW() WHERE cliente_id=? AND tipo='invitacion' AND usado_at IS NULL", [$c['id']]);
                flash('ok', $c['email'] . ' ya no entra al panel. Su cuenta de cliente sigue igual.');
            } else {
                clave_mail_invitacion($c, $quien);
                flash('ok', 'Le reenviamos la invitación a ' . $c['email'] . '.');
            }
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/equipo.php');
}

$maestros = [];
foreach (maestros_emails() as $m) $maestros[$m] = q("SELECT nombre, apellido, ultimo_login FROM clientes WHERE email=?", [$m])->fetch() ?: null;
$operadores = q("SELECT c.id, c.nombre, c.apellido, c.email, c.ultimo_login, c.created_at,
                        (SELECT MAX(l.created_at) FROM claves_links l WHERE l.cliente_id=c.id AND l.tipo='invitacion') AS invitado_at
                 FROM clientes c WHERE c.es_operador=1 ORDER BY c.nombre, c.email")->fetchAll();
$operadores = array_filter($operadores, fn ($o) => !isset($maestros[strtolower($o['email'])]));

admin_header('Equipo', 'equipo');
?>
<h1>Equipo</h1>
<p class="muted" style="max-width:760px;margin-top:-8px">Quiénes entran al panel. Los <b>operadores</b> usan todo el panel; los <b>maestros</b> además manejan el equipo. Los maestros se definen en <code>ADMIN_EMAILS</code> del <code>config.php</code> (cPanel): así nadie puede quitarles el acceso desde acá.</p>

<div class="layout-2">
    <div>
        <h3>Operadores</h3>
        <div class="tabla-wrap" style="margin-bottom:24px">
            <table class="tabla">
                <tr><th>Persona</th><th>Último ingreso</th><th></th></tr>
                <?php foreach ($operadores as $o): ?>
                    <tr>
                        <td><b><?= e(trim($o['nombre'] . ' ' . $o['apellido']) ?: '—') ?></b><div class="muted small"><?= e($o['email']) ?></div></td>
                        <td class="small">
                            <?php if ($o['ultimo_login']): ?><?= fecha($o['ultimo_login']) ?>
                            <?php elseif ($o['invitado_at']): ?><span class="badge badge-amarillo">Invitado</span> <span class="muted">todavía no entró</span>
                            <?php else: ?><span class="muted">Nunca</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (!$o['ultimo_login']): ?>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="accion" value="reenviar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-texto btn-chico" type="submit">Reenviar invitación</button></form>
                            <?php endif; ?>
                            <?php if ((int)$o['id'] !== (int)$admin['id']): ?>
                                <form method="post" style="display:inline" data-confirmar="¿Quitarle el acceso al panel a <?= e($o['email']) ?>? Su cuenta de cliente sigue igual.">
                                    <?= csrf_field() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-texto btn-chico" type="submit" style="color:var(--red)">Quitar</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$operadores): ?><tr><td colspan="3" class="muted">Todavía no hay operadores.</td></tr><?php endif; ?>
            </table>
        </div>

        <h3>Maestros</h3>
        <div class="tabla-wrap">
            <table class="tabla">
                <tr><th>Persona</th><th>Último ingreso</th></tr>
                <?php foreach ($maestros as $mail => $m): ?>
                    <tr>
                        <td><b><?= e($m ? (trim($m['nombre'] . ' ' . $m['apellido']) ?: '—') : '—') ?></b><div class="muted small"><?= e($mail) ?></div></td>
                        <td class="small"><?= $m ? ($m['ultimo_login'] ? fecha($m['ultimo_login']) : '<span class="muted">Nunca</span>') : '<span class="muted">Todavía no se registró en la tienda</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <aside>
        <form method="post" class="card form">
            <?= csrf_field() ?><input type="hidden" name="accion" value="agregar">
            <h3>Agregar operador</h3>
            <p class="small muted" style="margin-top:-6px">Si ya tiene cuenta en la tienda, le damos acceso al panel. Si no, le creamos una y le llega un mail para elegir su contraseña.</p>
            <label class="campo">Email<input type="email" name="email" required autocomplete="off"></label>
            <label class="campo">Nombre <small>(si todavía no tiene cuenta)</small><input type="text" name="nombre" maxlength="80" autocomplete="off"></label>
            <button class="btn btn-primario btn-bloque" type="submit">Agregar al equipo</button>
        </form>
    </aside>
</div>

<?php admin_footer(); ?>
