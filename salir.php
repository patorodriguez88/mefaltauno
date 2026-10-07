<?php
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $carrito = carrito();
    $_SESSION = [];
    session_regenerate_id(true);
    if ($carrito) $_SESSION['carrito'] = $carrito;  // el carrito sobrevive al cerrar sesión
    flash('info', 'Cerraste sesión. ¡Hasta pronto!');
}
redirect('');
