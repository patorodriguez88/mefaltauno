<?php
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $carrito = carrito();
    $_SESSION = [];
    session_regenerate_id(true);
    if ($carrito) $_SESSION['carrito'] = $carrito;  // el carrito sobrevive al cerrar sesión
    // "Entrar como operador" desde el aviso del admin: sale y va al login, que después vuelve a esa página
    $volver = (string)($_POST['volver'] ?? '');
    if ($volver !== '' && strpos($volver, BASE_URL) === 0 && strpos($volver, '//') === false) {
        flash('info', 'Ingresá con la cuenta de operador.');
        redirect('ingresar.php?volver=' . urlencode($volver));
    }
    flash('info', 'Cerraste sesión. Tu colección te espera, coleccionista.');
}
redirect('');
