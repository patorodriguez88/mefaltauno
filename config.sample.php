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

// WePoint (warehouse): el stock y la preparación de pedidos los maneja WePoint.
// Sandbox: https://sandbox.wepoint.ar/api · Producción: https://sistema.wepoint.ar/api
define('WEPOINT_URL',              'https://sandbox.wepoint.ar/api');
define('WEPOINT_EMAIL',            '');
define('WEPOINT_PASSWORD',         '');
define('WEPOINT_ID_TRANSPORTISTA', '');   // transportista para las órdenes de venta (lo da WePoint)
define('WEPOINT_CREAR_ORDENES',    false);  // true = los pedidos confirmados se mandan al depósito (¡solo en producción real!)
define('WEPOINT_CRON_TOKEN',       '');   // clave para que el cron de cPanel sincronice el stock

// Caddy (envíos al kiosco): se crea el envío cuando WePoint deja el pedido "Listo para enviar".
// Sandbox: https://api.caddy.com.ar/sandbox · Producción: https://api.caddy.com.ar/api
define('CADDY_URL',          'https://api.caddy.com.ar/sandbox');
define('CADDY_USUARIO',      '');      // cuenta de MeFaltaUno en plataforma.caddy.com.ar
define('CADDY_PASSWORD',     '');
define('CADDY_BOX',          ['Length' => 20, 'Width' => 15, 'Height' => 10, 'Weight' => 1]);   // medidas por defecto (cm, kg)
define('CADDY_CREAR_ENVIOS', false);   // true = se crean envíos reales en Caddy

// Emails con acceso al panel admin (separados por coma)
define('ADMIN_EMAILS', '');

define('APP_DEBUG', false);
