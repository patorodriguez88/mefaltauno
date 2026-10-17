<?php
// Para el botón "Ubicar en el mapa" de Puntos de retiro: busca con Google desde el servidor.
// Sin clave de Google responde 501 y el mapa usa OpenStreetMap como antes.
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/inc/geocodificar.php';

if (!google_geocoding_listo()) json_out(['ok' => false, 'sin_google' => true], 501);
$direccion = trim((string)($_GET['direccion'] ?? ''));
$localidad = trim((string)($_GET['localidad'] ?? ''));
if ($direccion === '' || $localidad === '') json_out(['ok' => false, 'error' => 'Completá dirección y localidad.'], 422);
try {
    $r = google_geocodificar($direccion, $localidad, trim((string)($_GET['provincia'] ?? '')) ?: 'Córdoba');
    json_out(['ok' => true, 'resultado' => $r]);
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}
