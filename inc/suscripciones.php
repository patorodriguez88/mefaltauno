<?php
// Suscripciones a colecciones (y a novedades generales cuando $coleccion_id es null).
// `coleccion_clave` = coleccion_id o 0, para que el índice único funcione también con las generales.

function suscribir(string $email, ?int $coleccion_id, string $origen, ?string $nombre = null, ?string $telefono = null): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Revisá el email: no parece válido.');
    if ($coleccion_id && !q("SELECT 1 FROM colecciones WHERE id=? AND activa=1", [$coleccion_id])->fetchColumn()) {
        throw new Exception('Esa colección no está disponible.');
    }
    $cli = cliente();
    $cliente_id = $cli && strcasecmp($cli['email'], $email) === 0 ? (int)$cli['id']
        : ((int)q("SELECT id FROM clientes WHERE email=?", [$email])->fetchColumn() ?: null);
    $ya = q("SELECT id, activo FROM suscripciones WHERE email=? AND coleccion_clave=?", [$email, (int)$coleccion_id])->fetch();
    if ($ya && $ya['activo']) return 'ya';
    if ($ya) {
        q("UPDATE suscripciones SET activo=1, baja_at=NULL, origen=?, nombre=COALESCE(?, nombre), telefono=COALESCE(?, telefono), cliente_id=COALESCE(?, cliente_id) WHERE id=?",
          [$origen, $nombre ?: null, $telefono ?: null, $cliente_id, $ya['id']]);
        return 'reactivada';
    }
    q("INSERT INTO suscripciones (email, nombre, telefono, coleccion_id, coleccion_clave, cliente_id, origen, token) VALUES (?,?,?,?,?,?,?,?)",
      [$email, $nombre ?: null, $telefono ?: null, $coleccion_id ?: null, (int)$coleccion_id, $cliente_id, $origen, bin2hex(random_bytes(16))]);
    return 'nueva';
}

function suscripto(?array $cli, int $coleccion_id): bool {
    return $cli && (bool)q("SELECT 1 FROM suscripciones WHERE email=? AND coleccion_clave=? AND activo=1", [strtolower($cli['email']), $coleccion_id])->fetchColumn();
}

function desuscribir_cliente(array $cli, int $coleccion_id): void {
    q("UPDATE suscripciones SET activo=0, baja_at=NOW() WHERE email=? AND coleccion_clave=?", [strtolower($cli['email']), $coleccion_id]);
}

// Link de baja para incluir en los mails a suscriptores
function link_baja(string $token): string {
    return rtrim(SITE_URL, '/') . url('baja.php?t=' . $token);
}
