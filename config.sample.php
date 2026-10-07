<?php
// Copiar como config.php y completar. config.php NO se sube al repo:
// en el servidor se carga una sola vez a mano (cPanel → Administrador de archivos).

define('DB_HOST',   'localhost');
define('DB_PORT',   3306);
define('DB_NAME',   'dinter6_mefaltauno');
define('DB_USER',   'dinter6_mefaltauno');
define('DB_PASS',   '');
define('DB_SOCKET', '');            // vacío = conecta por host/puerto

define('BASE_URL', '/');            // ruta donde vive el sitio (local: '/mefaltauno/')
define('SITE_URL', 'https://web.mefaltauno.com.ar');

define('MAIL_MODO',     'mail');    // mail = envía con mail() | log = escribe en storage/mail.log
define('MAIL_FROM',     'no-responder@mefaltauno.com.ar');
define('MAIL_OPERADOR', 'pedidos@mefaltauno.com.ar');

// Emails con acceso al panel admin (separados por coma)
define('ADMIN_EMAILS', '');

define('APP_DEBUG', false);
