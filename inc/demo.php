<?php
// Datos de prueba para que el equipo vea la web funcionando (antes de conectar WePoint).
// Todo lo que crea está marcado "de prueba" y se puede borrar antes del lanzamiento.

const DEMO_CLIENTE  = 'cliente.prueba@mefaltauno.com.ar';
const DEMO_OPERADOR = 'operador.prueba@mefaltauno.com.ar';

function demo_cargar_base(string $pass_cliente, string $pass_operador): array {
    require_once __DIR__ . '/importar.php';
    $n = importar_catalogo_tiendanube();
    $hecho = ["catálogo: {$n['colecciones']} colecciones y {$n['items']} números nuevos"];

    if (!q("SELECT COUNT(*) FROM puntos_retiro")->fetchColumn()) {
        foreach ([
            ['Kiosco de prueba Centro', 'Av. Colón 300', -31.4135, -64.1890, 'Lun a Sáb 8 a 21 h'],
            ['Kiosco de prueba Nueva Córdoba', 'Bv. Illia 450', -31.4250, -64.1860, 'Todos los días 9 a 22 h'],
            ['Kiosco de prueba Cerro de las Rosas', 'Av. Rafael Núñez 4500', -31.3650, -64.2350, 'Lun a Vie 8 a 20 h'],
        ] as $p) {
            q("INSERT INTO puntos_retiro (nombre, direccion, localidad, provincia, lat, lng, horario) VALUES (?,?,'Córdoba','Córdoba',?,?,?)", $p);
        }
        $hecho[] = 'puntos de retiro de prueba: 3';
    }
    if (!datos_bancarios()) {
        foreach (['banco_banco' => 'Banco de Prueba', 'banco_titular' => 'Dinter S.A. (prueba)', 'banco_cuit' => '30-00000000-0',
                  'banco_alias' => 'mefaltauno.prueba', 'banco_cbu' => '0000003100000000000000',
                  'banco_instrucciones' => 'DATOS DE PRUEBA: no transferir. Reservamos los números por 48 h.'] as $k => $v) {
            q("REPLACE INTO ajustes (clave, valor) VALUES (?,?)", [$k, $v]);
        }
        $hecho[] = 'datos bancarios de prueba';
    }
    q("INSERT IGNORE INTO cupones (codigo, tipo, valor, uno_por_cliente, nota) VALUES ('HEROE20','porcentaje',20,1,'Código de prueba')");

    foreach ([[DEMO_CLIENTE, 'Cliente', 'de Prueba', $pass_cliente, 0], [DEMO_OPERADOR, 'Operador', 'de Prueba', $pass_operador, 1]] as [$em, $nom, $ape, $pw, $op]) {
        q("INSERT INTO clientes (nombre, apellido, email, telefono, password_hash, es_operador) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), es_operador=VALUES(es_operador)",
          [$nom, $ape, $em, '3510000000', password_hash($pw, PASSWORD_DEFAULT), $op]);
    }
    // El cliente de prueba ya "tiene" algunos números, para que el panel no arranque vacío
    $cli = (int)q("SELECT id FROM clientes WHERE email=?", [DEMO_CLIENTE])->fetchColumn();
    foreach (['racing-cars' => [1, 2, 3], 'julio-verne-en-miniatura' => [1, 2, 3, 4, 5], 'leyendas-de-la-moda' => [1]] as $slug => $nums) {
        $col = (int)q("SELECT id FROM colecciones WHERE slug=?", [$slug])->fetchColumn();
        if (!$col) continue;
        q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli, $col]);
        foreach ($nums as $n) {
            $iid = q("SELECT id FROM items WHERE coleccion_id=? AND numero=?", [$col, $n])->fetchColumn();
            if ($iid) q("INSERT IGNORE INTO cliente_items (cliente_id, item_id) VALUES (?,?)", [$cli, $iid]);
        }
    }
    $hecho[] = 'usuarios de prueba: ' . DEMO_CLIENTE . ' y ' . DEMO_OPERADOR;
    return $hecho;
}

// Copia a uploads/ las imágenes que todavía apuntan a Tiendanube (de a $lote por llamada).
function demo_copiar_imagenes(int $lote = 12): array {
    require_once __DIR__ . '/subidas.php';
    $pend = array_merge(
        q("SELECT 'colecciones' AS t, id, slug AS nombre, imagen FROM colecciones WHERE imagen LIKE 'http%' LIMIT $lote")->fetchAll(),
        q("SELECT 'items' AS t, i.id, CONCAT(c.slug, '-', i.numero) AS nombre, i.imagen FROM items i JOIN colecciones c ON c.id=i.coleccion_id WHERE i.imagen LIKE 'http%' LIMIT $lote")->fetchAll()
    );
    $ok = 0;
    $errores = [];
    foreach (array_slice($pend, 0, $lote) as $r) {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'mfu');
            $ch = curl_init(preg_replace('#^http://#', 'https://', $r['imagen']));
            $fh = fopen($tmp, 'w');
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_USERAGENT => 'Mozilla/5.0']);
            $bien = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            fclose($fh);
            if (!$bien) throw new Exception('no se pudo descargar');
            $ruta = subir_imagen(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], $r['t'] === 'colecciones' ? 'colecciones' : 'items', $r['nombre']);
            q("UPDATE {$r['t']} SET imagen=? WHERE id=?", [$ruta, $r['id']]);
            $ok++;
        } catch (Exception $e) {
            $errores[] = $r['nombre'] . ': ' . $e->getMessage();
        } finally {
            if (!empty($tmp) && is_file($tmp)) unlink($tmp);
        }
    }
    $quedan = (int)q("SELECT (SELECT COUNT(*) FROM colecciones WHERE imagen LIKE 'http%') + (SELECT COUNT(*) FROM items WHERE imagen LIKE 'http%')")->fetchColumn();
    return ['copiadas' => $ok, 'quedan' => $quedan, 'errores' => $errores];
}

// Vuelve a poner el stock de prueba original (el que tenía cada número en Tiendanube).
function demo_restaurar_stock(): int {
    $json = json_decode(file_get_contents(dirname(__DIR__) . '/data/catalogo-tiendanube.json'), true);
    $n = 0;
    foreach ($json as $c) {
        $col = q("SELECT id FROM colecciones WHERE slug=?", [$c['slug']])->fetchColumn();
        if (!$col) continue;
        foreach ($c['items'] as $i => $it) {
            $n += q("UPDATE items SET stock=?, stock_sync_at=NULL WHERE coleccion_id=? AND numero=?", [max(0, (int)($it['stock'] ?? 0)), $col, $i + 1])->rowCount();
        }
    }
    return $n;
}
