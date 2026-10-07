<?php
// Uso (CLI): php tools/importar.php [--admin email password]
// Carga el catálogo inicial y, opcionalmente, crea un usuario administrador.
if (PHP_SAPI !== 'cli') exit('Solo por línea de comandos.');

$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/importar.php';

$n = importar_catalogo_tiendanube();
echo "Colecciones nuevas: {$n['colecciones']} · Números nuevos: {$n['items']}\n";

$i = array_search('--admin', $argv, true);
if ($i !== false) {
    [$email, $pass] = [$argv[$i + 1] ?? '', $argv[$i + 2] ?? ''];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) exit("Email o contraseña inválidos (mínimo 8 caracteres).\n");
    q("INSERT INTO clientes (nombre, apellido, email, password_hash) VALUES ('Admin','',?,?)
       ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [$email, password_hash($pass, PASSWORD_DEFAULT)]);
    echo "Usuario $email listo" . (es_admin(['email' => $email]) ? ' (admin)' : ' — ojo: no está en ADMIN_EMAILS') . "\n";
}
