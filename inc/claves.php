<?php
// Links para elegir contraseña, por mail: la invitación de un operador nuevo (admin/equipo.php) y
// "¿Olvidaste tu contraseña?" (olvide.php). El link lleva un token al azar; en la base queda solo su
// hash, vence y sirve una sola vez. Al usarlo (clave.php) se invalidan los demás links de esa cuenta.

const CLAVES_HORAS = ['invitacion' => 72, 'recuperar' => 2];

/** Arma un link nuevo para la cuenta y devuelve la ruta relativa (clave.php?t=…). */
function clave_link_nuevo(int $cliente_id, string $tipo): string {
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO claves_links (cliente_id, token_hash, tipo, vence_at) VALUES (?,?,?, NOW() + INTERVAL ? HOUR)",
      [$cliente_id, hash('sha256', $token), $tipo, CLAVES_HORAS[$tipo]]);
    return 'clave.php?t=' . $token;
}

/** El link vigente de ese token (con los datos de la cuenta), o null si no existe, venció o ya se usó. */
function clave_link_vigente(string $token): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $r = q("SELECT l.*, c.email, c.nombre FROM claves_links l JOIN clientes c ON c.id=l.cliente_id
            WHERE l.token_hash=? AND l.usado_at IS NULL AND l.vence_at > NOW()", [hash('sha256', $token)])->fetch();
    return $r ?: null;
}

/** Guarda la contraseña nueva y da por usados todos los links pendientes de la cuenta. */
function clave_guardar(int $cliente_id, string $clave): void {
    q("UPDATE clientes SET password_hash=? WHERE id=?", [password_hash($clave, PASSWORD_DEFAULT), $cliente_id]);
    q("UPDATE claves_links SET usado_at=NOW() WHERE cliente_id=? AND usado_at IS NULL", [$cliente_id]);
}

/** Invitación a un operador: link para elegir su contraseña y entrar al panel. */
function clave_mail_invitacion(array $c, string $quien_invita): void {
    $link = clave_link_nuevo((int)$c['id'], 'invitacion');
    mail_enviar($c['email'], 'Te sumaron al equipo de MeFaltaUno',
        '<p>Hola ' . e($c['nombre']) . ', ' . e($quien_invita) . ' te dio acceso al panel de MeFaltaUno.</p>'
        . '<p>Elegí tu contraseña con este botón. El link vence en ' . CLAVES_HORAS['invitacion'] . ' horas y sirve una sola vez.</p>'
        . mail_link($link, 'Elegir mi contraseña')
        . '<p style="color:#5f6f70;font-size:13px">Después entrás siempre en ' . e(rtrim(SITE_URL, '/') . url('ingresar.php')) . ' con tu mail y esa contraseña.</p>');
}

/** "¿Olvidaste tu contraseña?": link para elegir una nueva. */
function clave_mail_recuperar(array $c): void {
    $link = clave_link_nuevo((int)$c['id'], 'recuperar');
    mail_enviar($c['email'], 'Elegí una contraseña nueva para MeFaltaUno',
        '<p>Hola ' . e($c['nombre']) . ', pediste una contraseña nueva. Elegila con este botón: el link vence en '
        . CLAVES_HORAS['recuperar'] . ' horas y sirve una sola vez.</p>'
        . mail_link($link, 'Elegir contraseña nueva')
        . '<p style="color:#5f6f70;font-size:13px">Si no lo pediste vos, ignorá este mail: tu contraseña no cambia.</p>');
}
