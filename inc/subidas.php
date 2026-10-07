<?php
// Fotos subidas por el operador. Se re-codifican con GD (así nunca se guarda otra cosa
// que una imagen) y se achican a un máximo de 1400 px. Quedan en uploads/<carpeta>/.

function subir_imagen(?array $f, string $carpeta, string $prefijo): ?string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('No se pudo subir la foto (error ' . $f['error'] . ').');
    if ($f['size'] > 8 * 1024 * 1024) throw new Exception('La foto pesa más de 8 MB.');
    $info = @getimagesize($f['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$info || !isset($tipos[$info[2]])) throw new Exception('La foto tiene que ser JPG, PNG o WEBP.');

    $src = $tipos[$info[2]]($f['tmp_name']);
    if (!$src) throw new Exception('No se pudo leer la foto.');
    [$w, $h] = [$info[0], $info[1]];
    $max = 1400;
    $escala = min(1, $max / max($w, $h));
    $nw = (int)round($w * $escala);
    $nh = (int)round($h * $escala);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // fondo blanco para PNG transparentes
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = dirname(__DIR__) . '/uploads/' . $carpeta;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('No se pudo crear la carpeta de fotos.');
    subidas_proteger(dirname(__DIR__) . '/uploads');
    $nombre = slugify($prefijo) . '-' . bin2hex(random_bytes(4)) . '.jpg';
    if (!imagejpeg($dst, "$dir/$nombre", 85)) throw new Exception('No se pudo guardar la foto.');
    imagedestroy($src);
    imagedestroy($dst);
    return "uploads/$carpeta/$nombre";
}

// Que en uploads/ nunca se ejecute código, aunque alguien lograra dejar un archivo.
function subidas_proteger(string $dir): void {
    $ht = "$dir/.htaccess";
    if (!is_file($ht)) {
        file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php\\d?|phtml|phar|cgi|pl|py|sh)$\">\n    Require all denied\n</FilesMatch>\n");
    }
}
