<?php
// Envío de mails. En local (MAIL_MODO = 'log') se escriben en storage/mail.log.
// Cada mail queda en mails_enviados (Panel → Mails enviados), salvo las campañas de marketing ($registrar = false),
// que ya llevan su propio registro por destinatario en campana_destinatarios.

function mail_enviar(string $para, string $asunto, string $cuerpo_html, array $extra_headers = [], bool $registrar = true): bool {
    $html = mail_layout($asunto, $cuerpo_html);
    if (MAIL_MODO === 'log') {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents("$dir/mail.log", sprintf("=== %s\nPara: %s\nAsunto: %s\n\n%s\n\n", date('Y-m-d H:i:s'), $para, $asunto, mail_texto($cuerpo_html)), FILE_APPEND);
        if ($registrar) mail_registrar($para, $asunto, $cuerpo_html, $html, true);
        return true;
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: MeFaltaUno <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_OPERADOR,
    ];
    $headers = array_merge($headers, $extra_headers);
    $asunto_enc = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    $ok = @mail($para, $asunto_enc, $html, implode("\r\n", $headers), '-f' . MAIL_FROM);
    if ($registrar) mail_registrar($para, $asunto, $cuerpo_html, $html, $ok);
    return $ok;
}

// Texto plano del cuerpo: los botones quedan como "Texto (link)"
function mail_texto(string $cuerpo_html): string {
    $con_links = preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $cuerpo_html);
    $txt = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</tr>|</h\d>#i', "\n", $con_links)), ENT_QUOTES, 'UTF-8'));
    return preg_replace("/\n\s*\n+/", "\n", $txt);
}

// $ok = el servidor de correo aceptó el mail. No garantiza que haya llegado (puede rebotar o ir a spam).
// Si el registro falla, el mail ya salió igual: no se corta el envío por eso.
// $html = el mail completo tal como le llega al cliente (con logo y pie), para verlo igual en el panel.
function mail_registrar(string $para, string $asunto, string $cuerpo_html, string $html, bool $ok): void {
    try {
        q("INSERT INTO mails_enviados (para, asunto, cuerpo, html, ok) VALUES (?,?,?,?,?)",
          [mb_substr(trim($para), 0, 160), mb_substr($asunto, 0, 255), mb_substr(mail_texto($cuerpo_html), 0, 20000), $html, $ok ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('mails_enviados: ' . $e->getMessage());
    }
}

function mail_layout(string $titulo, string $cuerpo): string {
    $logo = rtrim(SITE_URL, '/') . url('assets/img/logo.jpg');
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;background:#f4f6f6;font-family:Arial,Helvetica,sans-serif;color:#1f2a2b">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#006368;padding:16px 24px"><img src="' . e($logo) . '" alt="MeFaltaUno" height="44"></td></tr>'
        . '<tr><td style="padding:24px"><h2 style="margin:0 0 16px;color:#006368">' . e($titulo) . '</h2>' . $cuerpo . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f4f6f6;font-size:12px;color:#6b7a7b"><b style="color:#006368">Ningún héroe queda atrás.</b><br>MeFaltaUno</td></tr>'
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
