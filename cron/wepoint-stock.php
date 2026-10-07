<?php
// Sincroniza el stock con WePoint. Para el cron de cPanel (cada 15 minutos, por ejemplo):
//   curl -s "https://web.mefaltauno.com.ar/cron/wepoint-stock.php?token=WEPOINT_CRON_TOKEN"
// o por línea de comandos: php cron/wepoint-stock.php
require dirname(__DIR__) . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $t = (string)($_GET['token'] ?? '');
    if (!defined('WEPOINT_CRON_TOKEN') || WEPOINT_CRON_TOKEN === '' || !hash_equals(WEPOINT_CRON_TOKEN, $t)) {
        http_response_code(403);
        exit('No autorizado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
try {
    $r = wepoint_sincronizar_stock();
    echo date('Y-m-d H:i:s') . " OK · actualizados {$r['actualizados']} · sin coincidencia " . count($r['no_encontrados']) . " · sin SKU {$r['sin_sku']}\n";
} catch (Exception $e) {
    http_response_code(500);
    echo date('Y-m-d H:i:s') . ' ERROR · ' . $e->getMessage() . "\n";
}
