<?php
// Uso (CLI, en una PC de la oficina): php tools/brihet-enviar.php [--servidor=https://www.mefaltauno.com.ar] [--token=…]
// Brihet le contesta 403 a nuestro servidor: este script lee su catálogo desde acá, lo sube a cron/brihet-recibir.php
// (que actualiza stock y precios de lo importado) y después sube las fotos que el servidor pide. Se corre a demanda,
// antes de importar o para refrescar precios. No usa la base local: solo PHP con curl.
// La clave es WEPOINT_CRON_TOKEN de producción: --token, la variable MFU_TOKEN, la guardada en ~/.mefaltauno-brihet-token
// o se pregunta (y si el envío sale bien se guarda ahí, solo legible por el usuario). Para doble clic: tools/Actualizar Brihet.command
if (PHP_SAPI !== 'cli') exit('Solo por línea de comandos.');

const BRIHET_API = 'https://brihet.com.ar/wp-json/wc/store/v1/products';
const UA = 'Mozilla/5.0 (compatible; MeFaltaUno/1.0; +https://www.mefaltauno.com.ar)';
define('TOKEN_ARCHIVO', (getenv('HOME') ?: '.') . '/.mefaltauno-brihet-token');

$opt = getopt('', ['servidor:', 'token:']);
$servidor = rtrim($opt['servidor'] ?? 'https://www.mefaltauno.com.ar', '/');
$token = $opt['token'] ?? (getenv('MFU_TOKEN') ?: '');
$guardar = false;
if ($token === '' && is_file(TOKEN_ARCHIVO)) $token = trim((string)file_get_contents(TOKEN_ARCHIVO));
if ($token === '') {
    $guardar = true;
    echo 'Clave del servidor (WEPOINT_CRON_TOKEN): ';
    system('stty -echo');
    $token = trim((string)fgets(STDIN));
    system('stty echo');
    echo "\n";
}

function http(string $url, array $o = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $o + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => UA]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string)$raw, $err];
}

function brihet(string $query): array {
    [$code, $raw, $err] = http(BRIHET_API . '?' . $query);
    $r = json_decode($raw, true);
    if ($code !== 200 || !is_array($r)) exit("Brihet no respondió bien (HTTP $code" . ($err ? ", $err" : '') . ").\n");
    return $r;
}

function servidor(string $accion, $cuerpo, array $headers = []): array {
    global $servidor, $token;
    [$code, $raw, $err] = http("$servidor/cron/brihet-recibir.php?accion=$accion", [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => array_merge(["X-Token: $token"], $headers),
    ]);
    $r = json_decode($raw, true);
    if ($code === 403 && is_file(TOKEN_ARCHIVO)) @unlink(TOKEN_ARCHIVO);   // clave guardada vieja: la próxima vez se pregunta
    if (!is_array($r) || empty($r['ok'])) exit("El servidor no aceptó el envío (HTTP $code): " . ($r['error'] ?? ($err ?: mb_substr($raw, 0, 200))) . "\n");
    return $r;
}

// 1. Catálogo, de a poco (igual que lo leía el servidor)
echo "Leyendo colecciones de Brihet…\n";
$productos = [];
for ($p = 1; $p <= 20; $p++) {
    $lote = brihet("per_page=100&page=$p");
    foreach ($lote as $x) if ($x['type'] === 'variable') $productos[$x['id']] = $x;
    if (count($lote) < 100) break;
}
$variantes = [];
$n = 0;
for ($p = 1; $p <= 60; $p++) {
    $lote = brihet("type=variation&per_page=100&page=$p");
    foreach ($lote as $v) $variantes[$v['parent']][] = $v;
    $n += count($lote);
    echo "\r  números leídos: $n";
    if (count($lote) < 100) break;
    usleep(400000);   // no cargar el servidor de Brihet
}
echo "\n" . count($productos) . " colecciones, $n números.\n";

// 2. Al servidor
echo "Subiendo catálogo a {$servidor}…\n";
$r = servidor('catalogo', gzencode(json_encode(['colecciones' => $productos, 'variantes' => $variantes]), 6), ['Content-Type: application/octet-stream']);
if ($guardar && $servidor === 'https://www.mefaltauno.com.ar') {
    file_put_contents(TOKEN_ARCHIVO, $token);
    chmod(TOKEN_ARCHIVO, 0600);
    echo "Clave guardada en esta PC: la próxima vez no se pide.\n";
}
echo "Stock y precios actualizados en {$r['actualizados']} números" . ($r['sin_dato'] ? " ({$r['sin_dato']} ya no están en Brihet)" : '') . ".\n";

// 3. Fotos que el servidor todavía no tiene (para las importaciones)
$fotos = $r['fotos'];
if (!$fotos) exit("No hacen falta fotos nuevas. Listo.\n");
echo "Fotos para importar: " . count($fotos) . "\n";
$subidas = $fallidas = 0;
foreach (array_chunk($fotos, 15) as $tanda) {
    $campos = [];
    $tmps = [];
    foreach ($tanda as $i => $url) {
        [$code, $datos] = http($url, [CURLOPT_TIMEOUT => 30]);
        usleep(150000);
        if ($code !== 200 || $datos === '') { $fallidas++; continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'bri');
        file_put_contents($tmp, $datos);
        $tmps[] = $tmp;
        $campos["foto[$i]"] = new CURLFile($tmp, 'application/octet-stream', "foto$i");
        $campos["url[$i]"] = $url;
    }
    if ($campos) $subidas += servidor('fotos', $campos)['guardadas'];
    foreach ($tmps as $t) @unlink($t);
    echo "\r  subidas: $subidas de " . count($fotos);
}
echo "\nListo" . ($fallidas ? " ($fallidas fotos no se pudieron bajar de Brihet)" : '') . ". Ya se puede importar desde el panel.\n";
