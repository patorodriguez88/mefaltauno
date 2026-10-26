<?php
// Marketing: avisar por mail cuando llegan números (pasaron de 0 a tener stock, en WePoint o en Brihet).
// Una campaña tiene un mailing (los números elegidos) y una audiencia:
//  - 'interesados': suscriptos a esas colecciones, clientes que las tienen en Mis colecciones y quienes buscaban esos números
//  - 'todos': todos los suscriptos y clientes, menos los que ya la recibieron como interesados
// A los abonados a los que el abono ya les guardó esos números no se les manda (ya les llegó el mail de la reserva).
// Se envía por tandas (lote cada intervalo_min) desde la pantalla o con el cron. Nunca a quien pidió la baja.

require_once __DIR__ . '/vistas.php';

const CAMPANA_LOTE_DEFECTO = 100;
const CAMPANA_INTERVALO_DEFECTO = 15;   // minutos entre tandas (los hostings limitan mails por hora)

// Números que llegaron en los últimos $dias (con stock hoy), agrupados por colección
function llegadas_recientes(int $dias = 14): array {
    return q("SELECT i.*, c.nombre AS coleccion, c.slug, c.id AS col_id FROM items i JOIN colecciones c ON c.id=i.coleccion_id
              WHERE i.llegada_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND i.activo=1 AND c.activa=1 AND c.proximamente=0
                AND (i.stock>0 OR COALESCE(i.brihet_stock,0)>0)
              ORDER BY i.llegada_at DESC, c.nombre, i.numero", [$dias])->fetchAll();
}

function campana_items(array $camp): array {
    $ids = array_filter(array_map('intval', explode(',', $camp['item_ids'])));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q("SELECT i.*, c.nombre AS coleccion, c.slug, c.id AS col_id FROM items i JOIN colecciones c ON c.id=i.coleccion_id
              WHERE i.id IN ($in) ORDER BY c.nombre, i.numero", $ids)->fetchAll();
}

// Destinatarios [email => nombre] según la audiencia, sin bajas ni repetidos
function campana_audiencia(array $camp): array {
    $items = campana_items($camp);
    $cols = array_values(array_unique(array_merge(array_column($items, 'col_id'), $camp['coleccion_id'] ? [(int)$camp['coleccion_id']] : [])));
    $item_ids = array_column($items, 'id');
    $dest = [];
    $sumar = function (array $filas) use (&$dest) {
        foreach ($filas as $f) {
            $em = strtolower(trim((string)$f['email']));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $dest[$em] ??= trim((string)($f['nombre'] ?? ''));
        }
    };
    if ($cols) {
        $in = implode(',', array_fill(0, count($cols), '?'));
        $sumar(q("SELECT email, nombre FROM suscripciones WHERE activo=1 AND coleccion_id IN ($in)", $cols)->fetchAll());
        $sumar(q("SELECT cl.email, cl.nombre FROM cliente_colecciones cc JOIN clientes cl ON cl.id=cc.cliente_id WHERE cc.coleccion_id IN ($in)", $cols)->fetchAll());
    }
    if ($item_ids) {
        $in = implode(',', array_fill(0, count($item_ids), '?'));
        $sumar(q("SELECT cl.email, cl.nombre FROM solicitud_items si JOIN solicitudes s ON s.id=si.solicitud_id JOIN clientes cl ON cl.id=s.cliente_id
                  WHERE si.item_id IN ($in) AND s.estado IN ('pendiente','buscando','conseguido')", $item_ids)->fetchAll());
    }
    if ($camp['audiencia'] === 'todos') {
        $interesados = $dest;
        $dest = [];
        $sumar(q("SELECT email, nombre FROM suscripciones WHERE activo=1")->fetchAll());
        $sumar(q("SELECT email, nombre FROM clientes WHERE es_operador=0")->fetchAll());
        // los que ya la recibieron como interesados (en otra campaña de los mismos números) no se repiten
        foreach (q("SELECT DISTINCT d.email FROM campana_destinos d JOIN campanas c ON c.id=d.campana_id
                    WHERE c.item_ids=? AND c.coleccion_id <=> ? AND c.audiencia='interesados' AND d.estado='enviado'", [$camp['item_ids'], $camp['coleccion_id']])->fetchAll(PDO::FETCH_COLUMN) as $ya) unset($dest[$ya]);
    }
    // Llegadas: a quien el abono ya le guardó todos esos números (y le llegó el mail de la reserva, inc/abonos.php)
    // la campaña no se le repite
    if (!$camp['coleccion_id'] && $item_ids) {
        $in = implode(',', array_fill(0, count($item_ids), '?'));
        $cubiertos = [];
        foreach (q("SELECT DISTINCT cl.email, r.item_id FROM reservas r JOIN clientes cl ON cl.id=r.cliente_id
                    WHERE r.abono_id IS NOT NULL AND r.item_id IN ($in)", $item_ids) as $r) {
            $cubiertos[strtolower($r['email'])][$r['item_id']] = true;
        }
        foreach ($cubiertos as $em => $ids) if (count($ids) === count($item_ids)) unset($dest[$em]);
    }
    foreach (q("SELECT email FROM mailing_bajas")->fetchAll(PDO::FETCH_COLUMN) as $baja) unset($dest[strtolower($baja)]);
    foreach (q("SELECT email FROM clientes WHERE es_operador=1")->fetchAll(PDO::FETCH_COLUMN) as $op) unset($dest[strtolower($op)]);
    return $dest;
}

// Link de baja de novedades (firmado, sin necesidad de ingresar)
function mailing_token(string $email): string {
    return substr(hash_hmac('sha256', strtolower($email), (defined('APP_SECRET') ? APP_SECRET : DB_PASS) . 'mailing'), 0, 24);
}
function mailing_link_baja(string $email): string {
    return rtrim(SITE_URL, '/') . url('mailing-baja.php?e=' . urlencode($email) . '&t=' . mailing_token($email));
}

// Campaña de una colección: hasta 6 números de muestra (con stock primero; si es Próximamente, los primeros)
function campana_items_coleccion(int $col_id): array {
    return q("SELECT id FROM items WHERE coleccion_id=? AND activo=1
              ORDER BY (stock>0 OR COALESCE(brihet_stock,0)>0) DESC, numero LIMIT 6", [$col_id])->fetchAll(PDO::FETCH_COLUMN);
}

// Bloque de la colección elegida: foto, nombre, cuántos números, descripción corta y botón
function campana_html_coleccion(array $col): string {
    $base = rtrim(SITE_URL, '/');
    $link = $base . url('coleccion.php?c=' . urlencode($col['slug']));
    $img = $col['imagen'] ? (preg_match('#^https?://#', $col['imagen']) ? $col['imagen'] : $base . url($col['imagen'])) : '';
    [$cargados, $max_n] = q("SELECT COUNT(*), COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=? AND activo=1", [$col['id']])->fetch(PDO::FETCH_NUM);
    $tot = max(coleccion_total($col) ?? (int)$cargados, (int)$max_n);   // igual que la ficha de la colección
    $desc = trim(preg_replace('/\s+/', ' ', (string)$col['descripcion']));
    if (mb_strlen($desc) > 260) $desc = mb_substr($desc, 0, 260) . '…';
    $sub = $col['proximamente'] ? '🚀 Próximamente' . ($col['lanzamiento'] ? ' · ' . $col['lanzamiento'] : '') : '';
    if ($tot) $sub .= ($sub ? ' · ' : '') . $tot . ' números';
    return '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5eaea;border-radius:12px;margin:0 0 18px"><tr>'
        . ($img ? '<td width="170" valign="top" style="padding:12px"><a href="' . e($link) . '"><img src="' . e($img) . '" alt="" width="160" style="max-width:100%;height:auto;border-radius:8px"></a></td>' : '')
        . '<td valign="top" style="padding:14px 14px 14px ' . ($img ? '4' : '14') . 'px">'
        . '<div style="font-size:19px;font-weight:bold;color:#004b4f;margin:0 0 4px">' . e($col['nombre']) . '</div>'
        . ($sub ? '<div style="font-size:12px;color:#006368;font-weight:bold;margin:0 0 8px">' . e($sub) . '</div>' : '')
        . ($desc !== '' ? '<div style="font-size:13px;color:#4b5a5b;line-height:1.45;margin:0 0 12px">' . e($desc) . '</div>' : '')
        . '<a href="' . e($link) . '" style="display:inline-block;background:#f1e52f;color:#004b4f;padding:8px 16px;border-radius:999px;text-decoration:none;font-weight:bold;font-size:14px">'
        . ($col['proximamente'] ? 'Quiero enterarme primero' : 'Ver la colección') . '</a>'
        . '</td></tr></table>';
}

// Cuerpo del mailing (va dentro de mail_layout). $email para el link de baja personal.
function campana_html(array $camp, string $email = '', string $nombre = ''): string {
    $base = rtrim(SITE_URL, '/');
    $h = ($nombre !== '' ? '<p style="margin:0 0 12px">Hola ' . e(strtok($nombre, ' ')) . ',</p>' : '')
       . ($camp['intro'] ? '<p style="margin:0 0 18px;line-height:1.5">' . nl2br(e($camp['intro'])) . '</p>' : '');
    $col = !empty($camp['coleccion_id']) ? q("SELECT * FROM colecciones WHERE id=?", [$camp['coleccion_id']])->fetch() : null;
    if ($col) $h .= campana_html_coleccion($col);
    $items = campana_items($camp);
    if ($col && $items) $h .= '<p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#6b7a7b">Algunos de sus números</p>';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0">';
    foreach (array_chunk($items, 2) as $fila) {
        $h .= '<tr>';
        foreach ($fila as $it) {
            $img = $it['imagen'] ? (preg_match('#^https?://#', $it['imagen']) ? $it['imagen'] : $base . url($it['imagen'])) : '';
            $link = $base . url_numero($it['slug'], (int)$it['numero']);
            $hay_fisico = (int)$it['stock'] > 0;
            $hay = $hay_fisico || (int)($it['brihet_stock'] ?? 0) > 0;
            $prox = $col && $col['proximamente'];
            $h .= '<td width="50%" valign="top" style="padding:6px"><table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5eaea;border-radius:10px">'
                . '<tr><td align="center" style="padding:10px;background:#ffffff">' . ($img ? '<a href="' . e($link) . '"><img src="' . e($img) . '" alt="" width="200" style="max-width:100%;height:auto;border-radius:6px"></a>' : '') . '</td></tr>'
                . '<tr><td style="padding:0 12px 4px;font-size:12px;color:#6b7a7b">' . e($it['coleccion']) . ' · ' . num((int)$it['numero']) . '</td></tr>'
                . '<tr><td style="padding:0 12px 6px;font-size:15px;font-weight:bold;color:#1f2a2b">' . e($it['titulo']) . '</td></tr>'
                . ($prox || !$hay
                    ? '<tr><td style="padding:0 12px 8px;font-size:13px;color:#006368;font-weight:bold">' . ($prox ? 'Próximamente' : 'Te avisamos cuando llegue') . '</td></tr>'
                    : '<tr><td style="padding:0 12px 8px;font-size:15px;color:#006368;font-weight:bold">' . precio(precio_item($it))
                      . '<span style="font-size:11px;font-weight:normal;color:#6b7a7b"> · ' . ($hay_fisico ? 'Entrega en 24 h' : 'Por encargue') . '</span></td></tr>')
                . '<tr><td style="padding:0 12px 12px"><a href="' . e($link) . '" style="display:inline-block;background:#f1e52f;color:#004b4f;padding:8px 16px;border-radius:999px;text-decoration:none;font-weight:bold;font-size:14px">' . ($prox || !$hay ? 'Ver' : 'Lo quiero') . '</a></td></tr>'
                . '</table></td>';
        }
        if (count($fila) === 1) $h .= '<td width="50%"></td>';
        $h .= '</tr>';
    }
    $h .= '</table>';
    $h .= '<p style="margin:20px 0 0;text-align:center"><a href="' . e($base . url('colecciones.php')) . '" style="color:#006368;font-weight:bold">Ver todas las colecciones →</a></p>';
    if ($email !== '') {
        $h .= '<p style="margin:24px 0 0;font-size:11px;color:#8a9798;text-align:center">Recibís este mail porque te suscribiste o tenés cuenta en MeFaltaUno. '
            . '<a href="' . e(mailing_link_baja($email)) . '" style="color:#8a9798">No quiero recibir más novedades</a>.</p>';
    }
    return $h;
}

function campana_enviar_uno(array $camp, string $email, string $nombre): bool {
    return mail_enviar($email, $camp['asunto'], campana_html($camp, $email, $nombre), ['List-Unsubscribe: <' . mailing_link_baja($email) . '>']);
}

// Arma la lista de destinatarios (una vez, al empezar) y pasa la campaña a "enviando"
function campana_iniciar(int $id): array {
    $camp = q("SELECT * FROM campanas WHERE id=?", [$id])->fetch();
    if (!$camp) throw new Exception('Campaña no encontrada.');
    if ($camp['estado'] === 'borrador') {
        foreach (campana_audiencia($camp) as $em => $nom) {
            q("INSERT IGNORE INTO campana_destinos (campana_id, email, nombre) VALUES (?,?,?)", [$id, $em, $nom ?: null]);
        }
        $total = (int)q("SELECT COUNT(*) FROM campana_destinos WHERE campana_id=?", [$id])->fetchColumn();
        q("UPDATE campanas SET estado='enviando', total=? WHERE id=?", [$total, $id]);
    } elseif ($camp['estado'] === 'pausada') {
        q("UPDATE campanas SET estado='enviando' WHERE id=?", [$id]);
    }
    return q("SELECT * FROM campanas WHERE id=?", [$id])->fetch();
}

// Manda la próxima tanda si ya pasó el intervalo. Devuelve el estado de la campaña (y cuántos mandó).
function campana_procesar_lote(int $id, bool $forzar = false): array {
    $camp = q("SELECT * FROM campanas WHERE id=?", [$id])->fetch();
    if (!$camp || $camp['estado'] !== 'enviando') return ['camp' => $camp, 'enviados_ahora' => 0];
    $espera = $camp['ultimo_lote_at'] ? strtotime($camp['ultimo_lote_at']) + $camp['intervalo_min'] * 60 - time() : 0;
    if ($espera > 0 && !$forzar) return ['camp' => $camp, 'enviados_ahora' => 0, 'espera' => $espera];
    @set_time_limit(300);
    $n = 0;
    foreach (q("SELECT * FROM campana_destinos WHERE campana_id=? AND estado='pendiente' ORDER BY id LIMIT " . (int)$camp['lote'], [$id])->fetchAll() as $d) {
        $baja = q("SELECT 1 FROM mailing_bajas WHERE email=?", [$d['email']])->fetchColumn();
        $ok = !$baja && campana_enviar_uno($camp, $d['email'], (string)$d['nombre']);
        q("UPDATE campana_destinos SET estado=?, enviado_at=NOW() WHERE id=?", [$baja ? 'baja' : ($ok ? 'enviado' : 'error'), $d['id']]);
        if ($ok) $n++;
    }
    $pend = (int)q("SELECT COUNT(*) FROM campana_destinos WHERE campana_id=? AND estado='pendiente'", [$id])->fetchColumn();
    q("UPDATE campanas SET enviados=(SELECT COUNT(*) FROM campana_destinos WHERE campana_id=? AND estado='enviado'), ultimo_lote_at=NOW(), estado=? WHERE id=?",
      [$id, $pend ? 'enviando' : 'terminada', $id]);
    return ['camp' => q("SELECT * FROM campanas WHERE id=?", [$id])->fetch(), 'enviados_ahora' => $n];
}

// Para el cron: avanza todas las campañas en curso
function campanas_procesar_todas(): int {
    $n = 0;
    foreach (q("SELECT id FROM campanas WHERE estado='enviando'")->fetchAll(PDO::FETCH_COLUMN) as $id) $n += campana_procesar_lote((int)$id)['enviados_ahora'];
    return $n;
}
