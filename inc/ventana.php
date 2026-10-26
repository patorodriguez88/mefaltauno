<?php
// Ventana de inicio: la presentación grande que se abre al entrar a la home (un lanzamiento, una campaña),
// con un video de YouTube de fondo (sin sonido, en loop) o una imagen. Se edita en admin/ventana.php y se
// guarda en `ajustes` (claves ventana_*). Mientras está activa reemplaza a la ventana de novedades.

const VENTANA_CAMPOS = ['activa', 'video', 'imagen', 'kicker', 'titulo', 'texto', 'boton_texto', 'boton_coleccion', 'boton_url', 'frecuencia', 'hasta'];

function ventana_ajustes(): array {
    $v = [];
    foreach (VENTANA_CAMPOS as $k) $v[$k] = ajuste('ventana_' . $k);
    return $v;
}

/** La ventana a mostrar hoy, o null si está apagada, vencida o sin título. */
function ventana_activa(): ?array {
    $v = ventana_ajustes();
    if ($v['activa'] !== '1' || trim($v['titulo']) === '') return null;
    if ($v['hasta'] !== '' && $v['hasta'] < date('Y-m-d')) return null;
    return $v + ['youtube' => youtube_id($v['video']), 'destino' => ventana_destino($v)];
}

/** Adónde lleva el botón: la colección elegida o un link. */
function ventana_destino(array $v): string {
    if ($v['boton_coleccion'] !== '') return url('coleccion.php?c=' . urlencode($v['boton_coleccion']));
    return $v['boton_url'];
}

/** Id de un video de YouTube a partir de cualquiera de sus links (watch, youtu.be, shorts, embed) o del id solo. */
function youtube_id(string $link): string {
    $link = trim($link);
    if (preg_match('/^[\w-]{11}$/', $link)) return $link;
    if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})#', $link, $m)) return $m[1];
    return '';
}

/** Cambia cuando cambia el contenido: así quien ya la vio la vuelve a ver si es otra campaña. */
function ventana_firma(array $v): string {
    return substr(md5(json_encode(array_intersect_key($v, array_flip(['video', 'imagen', 'kicker', 'titulo', 'texto', 'boton_texto', 'boton_coleccion', 'boton_url'])))), 0, 12);
}
