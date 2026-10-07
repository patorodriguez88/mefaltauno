<?php
// A dónde volver después de ingresar/registrarse. Solo rutas internas del sitio.
function destino_post_login(?array $c = null): string {
    $v = $_POST['volver'] ?? $_GET['volver'] ?? $_SESSION['volver_a'] ?? '';
    unset($_SESSION['volver_a']);
    if (is_string($v) && strpos($v, BASE_URL) === 0 && strpos($v, '//') === false) return $v;
    return url(es_admin($c) ? 'admin/' : 'cuenta.php');
}

function volver_actual(): string {
    $v = $_GET['volver'] ?? $_SESSION['volver_a'] ?? '';
    return is_string($v) ? $v : '';
}
