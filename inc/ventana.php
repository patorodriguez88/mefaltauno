<?php
// Ventana de inicio: la presentación grande que se abre al entrar a la home (un lanzamiento, una campaña),
// con un video de YouTube de fondo (sin sonido, en loop) o una imagen. Puede tener varias presentaciones que
// se pasan con flechas; cada visita arranca por la siguiente. Se edita en admin/ventana.php y se guarda en
// `ajustes`: ventana_activa / ventana_frecuencia valen para todas, y ventana_slides (JSON) tiene cada
// presentación, con su fecha de vencimiento (hasta). Mientras está activa reemplaza a la ventana de novedades.

const VENTANA_SLIDE_CAMPOS = ['video', 'imagen', 'kicker', 'titulo', 'texto', 'boton_texto', 'boton_coleccion', 'boton_url', 'hasta'];
const VENTANA_MAX_SLIDES = 6;

/** Las presentaciones guardadas. Si todavía no hay ninguna en JSON, la única de antes (ventana_titulo, etc.). */
function ventana_slides(): array {
    $json = json_decode(ajuste('ventana_slides'), true);
    if (is_array($json)) {
        return array_values(array_map(fn($s) => array_merge(array_fill_keys(VENTANA_SLIDE_CAMPOS, ''), array_intersect_key((array)$s, array_flip(VENTANA_SLIDE_CAMPOS))), $json));
    }
    $vieja = [];
    foreach (VENTANA_SLIDE_CAMPOS as $k) $vieja[$k] = ajuste('ventana_' . $k);   // ventana_hasta era la fecha de toda la ventana
    return trim($vieja['titulo']) !== '' ? [$vieja] : [];
}

function ventana_ajustes(): array {
    $slides = ventana_slides();
    // Antes había una sola fecha para toda la ventana: pasa a cada presentación que no tenga la suya
    if (($general = ajuste('ventana_hasta')) !== '') foreach ($slides as &$s) if ($s['hasta'] === '') $s['hasta'] = $general;
    return ['activa' => ajuste('ventana_activa'), 'frecuencia' => ajuste('ventana_frecuencia'), 'slides' => $slides];
}

function ventana_vencida(array $s): bool {
    return $s['hasta'] !== '' && $s['hasta'] < date('Y-m-d');
}

/** Las presentaciones listas para mostrar (con título y sin vencer), con su video y destino del botón. */
function ventana_preparar(array $v): array {
    $slides = [];
    foreach ($v['slides'] as $s) {
        if (trim($s['titulo']) === '' || ventana_vencida($s)) continue;
        $slides[] = $s + ['youtube' => youtube_id($s['video']), 'destino' => ventana_destino($s)];
    }
    return ['frecuencia' => $v['frecuencia'], 'slides' => $slides];
}

/** La ventana a mostrar hoy, o null si está apagada o no le queda ninguna presentación vigente. */
function ventana_activa(): ?array {
    $v = ventana_ajustes();
    if ($v['activa'] !== '1') return null;
    $lista = ventana_preparar($v);
    return $lista['slides'] ? $lista : null;
}

/** Adónde lleva el botón: la colección elegida o un link. */
function ventana_destino(array $s): string {
    if ($s['boton_coleccion'] !== '') return url('coleccion.php?c=' . urlencode($s['boton_coleccion']));
    return $s['boton_url'];
}

/** Id de un video de YouTube a partir de cualquiera de sus links (watch, youtu.be, shorts, embed) o del id solo. */
function youtube_id(string $link): string {
    $link = trim($link);
    if (preg_match('/^[\w-]{11}$/', $link)) return $link;
    if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})#', $link, $m)) return $m[1];
    return '';
}

/** Cambia cuando cambia el contenido: así quien ya la vio la vuelve a ver si es otra campaña. */
function ventana_firma(array $ventana): string {
    $cont = array_map(fn($s) => array_intersect_key($s, array_flip(array_diff(VENTANA_SLIDE_CAMPOS, ['hasta']))), $ventana['slides']);
    return substr(md5(json_encode($cont)), 0, 12);
}
