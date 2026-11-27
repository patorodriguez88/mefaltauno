<?php
// Comprobantes de transferencia que sube el cliente desde su pedido. Tienen datos bancarios: se guardan en
// storage/comprobantes (que Apache no sirve) y solo se ven por comprobante.php, que pide ser el dueño o un operador.
// Las fotos se re-codifican con GD (nunca se guarda otra cosa que una imagen); los PDF se aceptan tal cual.

const COMPROBANTE_MAX_MB = 10;

function comprobante_dir(): string {
    return dirname(__DIR__) . '/storage/comprobantes';
}

/** Guarda el comprobante del pedido (reemplaza el anterior) y devuelve el nombre del archivo. */
function comprobante_guardar(int $pedido_id, ?array $f): string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new Exception('Elegí el archivo del comprobante.');
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > COMPROBANTE_MAX_MB * 1024 * 1024)
        throw new Exception('El archivo pesa más de ' . COMPROBANTE_MAX_MB . ' MB. Probá con una captura de pantalla.');
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception('No se pudo subir el archivo. Probá de nuevo.');

    $dir = comprobante_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('No se pudo guardar el comprobante.');
    $base = 'pedido-' . $pedido_id . '-' . bin2hex(random_bytes(6));

    $inicio = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($inicio === '%PDF-') {
        $nombre = "$base.pdf";
        if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre")) throw new Exception('No se pudo guardar el comprobante.');
    } else {
        $info = @getimagesize($f['tmp_name']);
        $tipos = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!$info || !isset($tipos[$info[2]])) throw new Exception('El comprobante tiene que ser una foto (JPG, PNG o WEBP) o un PDF.');
        $src = @$tipos[$info[2]]($f['tmp_name']);
        if (!$src) throw new Exception('No se pudo leer la imagen.');
        [$w, $h] = [$info[0], $info[1]];
        $escala = min(1, 2200 / max($w, $h));   // que se sigan leyendo los números chicos del comprobante
        $nw = (int)round($w * $escala); $nh = (int)round($h * $escala);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $nombre = "$base.jpg";
        $ok = imagejpeg($dst, "$dir/$nombre", 88);
        imagedestroy($src); imagedestroy($dst);
        if (!$ok) throw new Exception('No se pudo guardar el comprobante.');
    }

    $anterior = q("SELECT comprobante FROM pedidos WHERE id=?", [$pedido_id])->fetchColumn();
    q("UPDATE pedidos SET comprobante=?, comprobante_at=NOW() WHERE id=?", [$nombre, $pedido_id]);
    if ($anterior) comprobante_borrar_archivo($anterior);
    return $nombre;
}

function comprobante_borrar_archivo(?string $nombre): void {
    if ($nombre && preg_match('/^[a-z0-9-]+\.(jpg|pdf)$/', $nombre) && is_file(comprobante_dir() . "/$nombre")) {
        @unlink(comprobante_dir() . "/$nombre");
    }
}

/** Link para ver el comprobante (lo abre el cliente dueño o un operador). */
function comprobante_url(int $pedido_id): string {
    return url('comprobante.php?id=' . $pedido_id);
}
