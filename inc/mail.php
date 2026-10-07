<?php
// Envío de mails. En local (MAIL_MODO = 'log') se escriben en storage/mail.log.

function mail_enviar(string $para, string $asunto, string $cuerpo_html): bool {
    $html = mail_layout($asunto, $cuerpo_html);
    if (MAIL_MODO === 'log') {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $txt = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</tr>|</h\d>#i', "\n", $cuerpo_html)), ENT_QUOTES, 'UTF-8'));
        file_put_contents("$dir/mail.log", sprintf("=== %s\nPara: %s\nAsunto: %s\n\n%s\n\n", date('Y-m-d H:i:s'), $para, $asunto, preg_replace("/\n\s*\n+/", "\n", $txt)), FILE_APPEND);
        return true;
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: MeFaltaUno <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_OPERADOR,
    ];
    $asunto_enc = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    return @mail($para, $asunto_enc, $html, implode("\r\n", $headers), '-f' . MAIL_FROM);
}

function mail_layout(string $titulo, string $cuerpo): string {
    $logo = rtrim(SITE_URL, '/') . url('assets/img/logo.jpg');
    return '<!doctype html><html><body style="margin:0;background:#f4f6f6;font-family:Arial,Helvetica,sans-serif;color:#1f2a2b">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#006368;padding:16px 24px"><img src="' . e($logo) . '" alt="MeFaltaUno" height="44"></td></tr>'
        . '<tr><td style="padding:24px"><h2 style="margin:0 0 16px;color:#006368">' . e($titulo) . '</h2>' . $cuerpo . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f4f6f6;font-size:12px;color:#6b7a7b"><b style="color:#006368">Ningún héroe queda atrás.</b><br>MeFaltaUno · Dinter S.A.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_tabla(array $filas): string {
    $h = '<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px">';
    foreach ($filas as $f) {
        $h .= '<tr>' . implode('', array_map(fn($c) => '<td style="border-bottom:1px solid #e5eaea">' . $c . '</td>', $f)) . '</tr>';
    }
    return $h . '</table>';
}

function mail_link(string $path, string $texto): string {
    $u = rtrim(SITE_URL, '/') . url($path);
    return '<p style="margin-top:20px"><a href="' . e($u) . '" style="background:#f1e52f;color:#004b4f;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:bold">' . e($texto) . '</a></p>';
}
