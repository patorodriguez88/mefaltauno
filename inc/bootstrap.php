<?php
// Punto de entrada común: config, sesión, base de datos y helpers.
// Cada página hace: require __DIR__ . '/inc/bootstrap.php';

$__cfg = dirname(__DIR__) . '/config.php';
if (!is_file($__cfg)) {
    http_response_code(500);
    exit('Falta config.php (copiar config.sample.php y completarlo).');
}
require $__cfg;
require __DIR__ . '/schema.php';

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
date_default_timezone_set('America/Argentina/Buenos_Aires');

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => BASE_URL,
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('mfu_sess');
session_start();

// ─── Base de datos ──────────────────────────────────────────────────────────

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = DB_SOCKET
        ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
        : 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    db_migrar($pdo);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ─── Salida y navegación ────────────────────────────────────────────────────

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string {
    return BASE_URL . ltrim($path, '/');
}

function asset(string $path): string {
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url($path) . '?v=' . $v;
}

function redirect(string $path): void {
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function flash(string $tipo, string $msg): void {
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function precio(float $n): string {
    return '$' . number_format($n, 0, ',', '.');
}

function fecha(?string $dt, bool $hora = true): string {
    if (!$dt) return '';
    return date($hora ? 'd/m/Y H:i' : 'd/m/Y', strtotime($dt));
}

function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim($s, '-') ?: 'item';
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── CSRF ───────────────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && hash_equals(csrf_token(), $t);
}

function csrf_exigir(): void {
    if (!csrf_ok()) {
        http_response_code(419);
        exit('La sesión expiró. Volvé atrás y recargá la página.');
    }
}

// ─── Clientes y sesión ──────────────────────────────────────────────────────

function cliente(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $id = $_SESSION['cliente_id'] ?? null;
    $c = $id ? (q("SELECT * FROM clientes WHERE id=?", [$id])->fetch() ?: null) : null;
    if ($id && !$c) unset($_SESSION['cliente_id']);
    return $c;
}

function es_admin(?array $c = null): bool {
    $c = $c ?? cliente();
    if (!$c) return false;
    $admins = array_filter(array_map('trim', explode(',', strtolower(ADMIN_EMAILS))));
    return in_array(strtolower($c['email']), $admins, true);
}

function login_cliente(array $c): void {
    session_regenerate_id(true);
    $_SESSION['cliente_id'] = (int)$c['id'];
    q("UPDATE clientes SET ultimo_login=NOW() WHERE id=?", [$c['id']]);
}

function requiere_login(): array {
    $c = cliente();
    if (!$c) {
        $_SESSION['volver_a'] = $_SERVER['REQUEST_URI'] ?? url();
        flash('info', 'Ingresá a tu cuenta para continuar.');
        redirect('ingresar.php');
    }
    return $c;
}

function requiere_admin(): array {
    $c = requiere_login();
    if (!es_admin($c)) {
        http_response_code(403);
        exit('No tenés acceso a esta sección.');
    }
    return $c;
}

function nombre_cliente(array $c): string {
    return trim($c['nombre'] . ' ' . $c['apellido']);
}

// ─── Catálogo ───────────────────────────────────────────────────────────────

function categorias(): array {
    static $cats = null;
    if ($cats === null) $cats = q("SELECT * FROM categorias ORDER BY orden, nombre")->fetchAll();
    return $cats;
}

function precio_item(array $it): float {
    return $it['precio_promo'] !== null && (float)$it['precio_promo'] > 0 ? (float)$it['precio_promo'] : (float)$it['precio'];
}

function num(int $n): string {
    return 'N° ' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

// Estado de cada número de una colección para un cliente:
// tengo | en_camino (en un pedido activo) | buscando (en una solicitud activa) | falta
function estados_items(int $cliente_id, ?int $coleccion_id = null): array {
    $filtro = $coleccion_id ? ' AND i.coleccion_id = ' . (int)$coleccion_id : '';
    $out = [];
    foreach (q("SELECT ci.item_id FROM cliente_items ci JOIN items i ON i.id=ci.item_id WHERE ci.cliente_id=? $filtro", [$cliente_id]) as $r) {
        $out[$r['item_id']] = 'tengo';
    }
    foreach (q("SELECT DISTINCT pi.item_id FROM pedido_items pi JOIN pedidos p ON p.id=pi.pedido_id JOIN items i ON i.id=pi.item_id
                WHERE p.cliente_id=? AND p.estado NOT IN ('entregado','cancelado') $filtro", [$cliente_id]) as $r) {
        $out[$r['item_id']] = $out[$r['item_id']] ?? 'en_camino';
    }
    foreach (q("SELECT DISTINCT si.item_id FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id JOIN items i ON i.id=si.item_id
                WHERE s.cliente_id=? AND s.estado IN ('pendiente','buscando','conseguido') $filtro", [$cliente_id]) as $r) {
        $out[$r['item_id']] = $out[$r['item_id']] ?? 'buscando';
    }
    return $out;
}

// ─── Estados de pedidos y solicitudes ───────────────────────────────────────

const ESTADOS_PEDIDO = [
    'pendiente'  => ['Recibido',          'Recibimos tu pedido. En breve te contactamos para confirmar el pago.', 'amarillo'],
    'confirmado' => ['Confirmado',        'El pago está confirmado.', 'azul'],
    'preparando' => ['En preparación',    'Estamos preparando tu pedido.', 'azul'],
    'enviado'    => ['Listo para retirar', 'Tu pedido ya está en el punto de retiro. ¡Pasá a buscarlo!', 'azul'],
    'entregado'  => ['Retirado',          '¡Listo! Ya los sumamos a tu colección.', 'verde'],
    'cancelado'  => ['Cancelado',         'El pedido fue cancelado.', 'gris'],
];

const ESTADOS_SOLICITUD = [
    'pendiente'     => ['Pendiente',          'Recibimos tu pedido. Lo vamos a buscar y te avisamos.', 'amarillo'],
    'buscando'      => ['Lo estamos buscando', 'Ya estamos buscándolo para vos.', 'azul'],
    'conseguido'    => ['¡Lo conseguimos!',    'Lo conseguimos. Te contactamos para coordinar la entrega.', 'verde'],
    'no_disponible' => ['No disponible',      'Por ahora no lo pudimos conseguir.', 'gris'],
    'cancelada'     => ['Cancelada',          'La solicitud fue cancelada.', 'gris'],
];

const ENVIO_METODOS = [
    'retiro'    => 'Retiro en punto de retiro',
    'domicilio' => 'Envío a domicilio',   // histórico: ya no se ofrece
];

const PAGO_METODOS = [
    'mercadopago'    => 'Mercado Pago',
    'transferencia'  => 'Transferencia bancaria',
    'contra_entrega' => 'Pago contra entrega',   // histórico: ya no se ofrece
];
const PAGO_METODOS_ACTIVOS = ['mercadopago', 'transferencia'];

// ─── Ajustes (tabla `ajustes`, se editan en admin/ajustes.php) ──────────────

const DATOS_BANCARIOS = ['banco' => 'Banco', 'titular' => 'Titular', 'cuit' => 'CUIT', 'cbu' => 'CBU', 'alias' => 'Alias'];

function ajustes(): array {
    static $a = null;
    if ($a === null) $a = q("SELECT clave, valor FROM ajustes")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $a;
}

function ajuste(string $clave, string $def = ''): string {
    return (string)(ajustes()[$clave] ?? $def);
}

// Datos para transferir que estén cargados: ['Banco' => 'Galicia', ...]
function datos_bancarios(): array {
    $out = [];
    foreach (DATOS_BANCARIOS as $k => $label) {
        if (ajuste("banco_$k") !== '') $out[$k] = ['label' => $label, 'valor' => ajuste("banco_$k")];
    }
    return $out;
}

// Bloque HTML con los datos bancarios y botones para copiar CBU y alias
function html_datos_bancarios(?float $total = null): string {
    $datos = datos_bancarios();
    if (!$datos) return '<p class="small muted" style="margin:0">Te enviamos los datos para transferir por mail.</p>';
    $h = '<dl class="banco">';
    if ($total !== null) $h .= '<div><dt>Monto</dt><dd><b>' . precio($total) . '</b></dd></div>';
    foreach ($datos as $k => $d) {
        $copiar = in_array($k, ['cbu', 'alias', 'cuit'], true)
            ? ' <button type="button" class="btn-copiar" data-copiar="' . e($d['valor']) . '" data-label="' . e($d['label']) . '">Copiar</button>' : '';
        $h .= '<div><dt>' . e($d['label']) . '</dt><dd><span>' . e($d['valor']) . '</span>' . $copiar . '</dd></div>';
    }
    $h .= '</dl>';
    if (ajuste('banco_instrucciones') !== '') $h .= '<p class="small muted" style="margin:8px 0 0">' . nl2br(e(ajuste('banco_instrucciones'))) . '</p>';
    return $h;
}

function puntos_retiro_activos(): array {
    return q("SELECT id, nombre, direccion, localidad, provincia, telefono, horario, notas, lat+0 AS lat, lng+0 AS lng
              FROM puntos_retiro WHERE activo=1 AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY localidad, nombre")->fetchAll();
}

function badge_estado(string $estado, array $mapa): string {
    [$label, , $color] = $mapa[$estado] ?? [$estado, '', 'gris'];
    return '<span class="badge badge-' . e($color) . '">' . e($label) . '</span>';
}

function registrar_historial(string $entidad, int $id, string $estado, ?string $nota = null, ?string $usuario = null): void {
    q("INSERT INTO historial (entidad, entidad_id, estado, nota, usuario) VALUES (?,?,?,?,?)",
      [$entidad, $id, $estado, $nota ?: null, $usuario]);
}

function historial(string $entidad, int $id): array {
    return q("SELECT * FROM historial WHERE entidad=? AND entidad_id=? ORDER BY created_at, id", [$entidad, $id])->fetchAll();
}

require __DIR__ . '/carrito.php';
require __DIR__ . '/mail.php';
