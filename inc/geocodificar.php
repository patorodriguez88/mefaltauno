<?php
// Dirección → coordenadas con Google Geocoding, del lado del servidor (la clave no llega al navegador).
// Clave: GOOGLE_API_KEY_SERVER en config.php. Solo acepta un resultado dentro de la localidad pedida:
// nada de "centro de la ciudad" ni de una calle con el mismo nombre en otra provincia.

function google_geocoding_listo(): bool {
    return defined('GOOGLE_API_KEY_SERVER') && GOOGLE_API_KEY_SERVER !== '';
}

function geo_normalizar(string $s): string {
    $s = mb_strtolower($s);
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

// Devuelve ['lat', 'lng', 'direccion', 'tipo'] o null si Google no encuentra la dirección en esa localidad.
function google_geocodificar(string $direccion, string $localidad, string $provincia = 'Córdoba'): ?array {
    // "Sarmiento esq Maipú" → "Sarmiento & Maipú": Google lo entiende como esquina
    $dir = preg_replace('/\s+(esq\.?|esquina|y)\s+/iu', ' & ', $direccion);
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => "$dir, $localidad, $provincia, Argentina",
        'components' => 'country:AR|administrative_area:' . $provincia,
        'language'   => 'es',
        'key'        => GOOGLE_API_KEY_SERVER,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $r = json_decode((string)$raw, true);
    if (!is_array($r)) throw new Exception('No pudimos consultar Google Maps. Probá de nuevo.');
    if (!in_array($r['status'] ?? '', ['OK', 'ZERO_RESULTS'], true)) {
        throw new Exception('Google Maps respondió ' . ($r['status'] ?? '?') . (isset($r['error_message']) ? ': ' . $r['error_message'] : ''));
    }
    $loc_pedida = geo_normalizar($localidad);
    foreach ($r['results'] ?? [] as $x) {
        $tipo = $x['geometry']['location_type'] ?? '';
        // APPROXIMATE = solo la ciudad o el barrio; una calle sola (sin altura ni esquina) cae en el medio de la calle
        if ($tipo === 'APPROXIMATE' || ($x['types'] ?? []) === ['route']) continue;
        $lugar = '';
        foreach ($x['address_components'] ?? [] as $c) {
            if (array_intersect(['locality', 'administrative_area_level_2'], $c['types'])) $lugar .= ' ' . geo_normalizar($c['long_name']);
        }
        if ($loc_pedida !== '' && strpos($lugar, $loc_pedida) === false && strpos($loc_pedida, trim($lugar)) === false) continue;
        return ['lat' => $x['geometry']['location']['lat'], 'lng' => $x['geometry']['location']['lng'], 'direccion' => $x['formatted_address'], 'tipo' => $tipo];
    }
    return null;
}
