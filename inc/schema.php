<?php
// Esquema de la base. Se aplica solo: db_migrar() corre los pasos que falten
// según la versión guardada en la tabla `meta`. Para cambiar el esquema,
// agregar un paso nuevo al final (nunca editar uno ya publicado).

function schema_pasos(): array {
    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS categorias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) NOT NULL UNIQUE,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                orden INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS colecciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                categoria_id INT NULL,
                slug VARCHAR(120) NOT NULL UNIQUE,
                nombre VARCHAR(160) NOT NULL,
                bajada VARCHAR(255) NULL,
                descripcion TEXT NULL,
                imagen VARCHAR(500) NULL,
                activa TINYINT(1) NOT NULL DEFAULT 1,
                destacada TINYINT(1) NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cat (categoria_id),
                CONSTRAINT fk_col_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Cada número / entrega de una colección
            "CREATE TABLE IF NOT EXISTS items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                coleccion_id INT NOT NULL,
                numero INT NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                imagen VARCHAR(500) NULL,
                precio DECIMAL(12,2) NOT NULL DEFAULT 0,
                precio_promo DECIMAL(12,2) NULL,
                stock INT NOT NULL DEFAULT 0,
                sku VARCHAR(64) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_col_num (coleccion_id, numero),
                KEY idx_sku (sku),
                CONSTRAINT fk_item_col FOREIGN KEY (coleccion_id) REFERENCES colecciones(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS clientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(80) NOT NULL,
                apellido VARCHAR(80) NOT NULL DEFAULT '',
                email VARCHAR(160) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                telefono VARCHAR(40) NULL,
                direccion VARCHAR(200) NULL,
                localidad VARCHAR(100) NULL,
                provincia VARCHAR(100) NULL,
                cp VARCHAR(12) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                ultimo_login DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Colecciones que el cliente sigue (aparecen en su panel)
            "CREATE TABLE IF NOT EXISTS cliente_colecciones (
                cliente_id INT NOT NULL,
                coleccion_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (cliente_id, coleccion_id),
                CONSTRAINT fk_cc_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_cc_col FOREIGN KEY (coleccion_id) REFERENCES colecciones(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Números que el cliente ya tiene
            "CREATE TABLE IF NOT EXISTS cliente_items (
                cliente_id INT NOT NULL,
                item_id INT NOT NULL,
                origen VARCHAR(20) NOT NULL DEFAULT 'manual',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (cliente_id, item_id),
                CONSTRAINT fk_ci_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_ci_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS pedidos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                envio_costo DECIMAL(12,2) NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                envio_metodo VARCHAR(20) NOT NULL,
                envio_nombre VARCHAR(160) NULL,
                envio_direccion VARCHAR(200) NULL,
                envio_localidad VARCHAR(100) NULL,
                envio_provincia VARCHAR(100) NULL,
                envio_cp VARCHAR(12) NULL,
                envio_telefono VARCHAR(40) NULL,
                pago_metodo VARCHAR(20) NOT NULL,
                notas TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cli (cliente_id),
                KEY idx_estado (estado),
                CONSTRAINT fk_ped_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS pedido_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pedido_id INT NOT NULL,
                item_id INT NULL,
                coleccion VARCHAR(160) NOT NULL,
                numero INT NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                precio DECIMAL(12,2) NOT NULL,
                cantidad INT NOT NULL DEFAULT 1,
                CONSTRAINT fk_pi_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
                CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // "Me falta uno": pedidos de números que no hay en stock (o que no están en la web)
            "CREATE TABLE IF NOT EXISTS solicitudes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                mensaje TEXT NULL,
                respuesta TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cli (cliente_id),
                KEY idx_estado (estado),
                CONSTRAINT fk_sol_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS solicitud_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                solicitud_id INT NOT NULL,
                item_id INT NULL,
                descripcion VARCHAR(255) NOT NULL,
                CONSTRAINT fk_si_sol FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE,
                CONSTRAINT fk_si_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Línea de tiempo de pedidos y solicitudes (la ve el cliente)
            "CREATE TABLE IF NOT EXISTS historial (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entidad VARCHAR(20) NOT NULL,
                entidad_id INT NOT NULL,
                estado VARCHAR(20) NOT NULL,
                nota TEXT NULL,
                usuario VARCHAR(160) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ent (entidad, entidad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Puntos de retiro (kioscos) en lugar de envío a domicilio
        2 => [
            "CREATE TABLE IF NOT EXISTS puntos_retiro (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(160) NOT NULL,
                direccion VARCHAR(200) NOT NULL,
                localidad VARCHAR(100) NOT NULL,
                provincia VARCHAR(100) NULL,
                cp VARCHAR(12) NULL,
                telefono VARCHAR(40) NULL,
                horario VARCHAR(200) NULL,
                notas VARCHAR(255) NULL,
                lat DECIMAL(10,7) NULL,
                lng DECIMAL(10,7) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE pedidos ADD COLUMN punto_id INT NULL AFTER envio_metodo",
            "ALTER TABLE pedidos ADD COLUMN envio_punto VARCHAR(160) NULL AFTER punto_id",
            "ALTER TABLE pedidos ADD CONSTRAINT fk_ped_punto FOREIGN KEY (punto_id) REFERENCES puntos_retiro(id) ON DELETE SET NULL",
        ],

        // Ajustes editables desde el admin (datos bancarios, etc.)
        3 => [
            "CREATE TABLE IF NOT EXISTS ajustes (
                clave VARCHAR(60) PRIMARY KEY,
                valor TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Stock de WePoint (el operador solo congela o limita) y códigos de descuento
        4 => [
            "ALTER TABLE items ADD COLUMN congelado TINYINT(1) NOT NULL DEFAULT 0 AFTER stock",
            "ALTER TABLE items ADD COLUMN limite INT NULL AFTER congelado",
            "ALTER TABLE items ADD COLUMN stock_sync_at DATETIME NULL AFTER limite",
            "CREATE TABLE IF NOT EXISTS cupones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(40) NOT NULL UNIQUE,
                tipo VARCHAR(12) NOT NULL,
                valor DECIMAL(12,2) NOT NULL,
                minimo DECIMAL(12,2) NULL,
                usos_max INT NULL,
                usos INT NOT NULL DEFAULT 0,
                uno_por_cliente TINYINT(1) NOT NULL DEFAULT 1,
                cliente_email VARCHAR(160) NULL,
                vence DATE NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                nota VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE pedidos ADD COLUMN cupon_codigo VARCHAR(40) NULL AFTER subtotal",
            "ALTER TABLE pedidos ADD COLUMN descuento DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cupon_codigo",
        ],

        // Vínculo con WePoint: id de producto y orden de venta generada
        5 => [
            "ALTER TABLE items ADD COLUMN wepoint_id INT NULL AFTER sku",
            "ALTER TABLE pedidos ADD COLUMN wepoint_orden_id VARCHAR(40) NULL",
            "ALTER TABLE pedidos ADD COLUMN wepoint_error TEXT NULL",
        ],

        // Operadores marcados en la base (además de ADMIN_EMAILS en config.php)
        6 => [
            "ALTER TABLE clientes ADD COLUMN es_operador TINYINT(1) NOT NULL DEFAULT 0",
        ],

        // Suscripciones: interesados en una colección (o en novedades generales si coleccion_id es NULL)
        7 => [
            "CREATE TABLE IF NOT EXISTS suscripciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(160) NOT NULL,
                nombre VARCHAR(120) NULL,
                telefono VARCHAR(40) NULL,
                coleccion_id INT NULL,
                coleccion_clave INT NOT NULL DEFAULT 0,
                cliente_id INT NULL,
                origen VARCHAR(30) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                token CHAR(32) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                baja_at DATETIME NULL,
                UNIQUE KEY uniq_email_col (email, coleccion_clave),
                KEY idx_col (coleccion_id),
                CONSTRAINT fk_sus_col FOREIGN KEY (coleccion_id) REFERENCES colecciones(id) ON DELETE CASCADE,
                CONSTRAINT fk_sus_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Último estado de la orden en WePoint (para registrar cada cambio en el historial)
        8 => [
            "ALTER TABLE pedidos ADD COLUMN wepoint_estado VARCHAR(40) NULL AFTER wepoint_orden_id",
        ],

        // Productos de la cuenta de WePoint que la web todavía no tiene: bandeja "Por publicar"
        9 => [
            "CREATE TABLE IF NOT EXISTS wepoint_nuevos (
                wepoint_id VARCHAR(40) PRIMARY KEY,
                sku VARCHAR(64) NULL,
                nombre VARCHAR(255) NULL,
                precio DECIMAL(12,2) NULL,
                stock INT NOT NULL DEFAULT 0,
                visto_at DATETIME NOT NULL,
                KEY idx_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Envío en Caddy (se crea cuando WePoint deja el pedido "Listo para enviar")
        10 => [
            "ALTER TABLE pedidos ADD COLUMN caddy_seguimiento VARCHAR(40) NULL, ADD COLUMN caddy_error TEXT NULL, ADD COLUMN caddy_creado_at DATETIME NULL",
        ],

        // Reservas: el número encontrado se le guarda 72 h a quien lo buscaba (inc/reservas.php)
        11 => [
            "CREATE TABLE IF NOT EXISTS reservas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                cliente_id INT NOT NULL,
                solicitud_item_id INT NULL,
                cantidad INT NOT NULL DEFAULT 1,
                estado VARCHAR(12) NOT NULL DEFAULT 'activa',
                origen VARCHAR(10) NOT NULL DEFAULT 'auto',
                vence_at DATETIME NOT NULL,
                pedido_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_item_estado (item_id, estado),
                KEY idx_cli (cliente_id),
                KEY idx_si (solicitud_item_id),
                CONSTRAINT fk_res_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
                CONSTRAINT fk_res_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Colecciones "Próximamente" (visibles, sin venta) y máximo de compra por cliente
        12 => [
            "ALTER TABLE colecciones ADD COLUMN proximamente TINYINT(1) NOT NULL DEFAULT 0 AFTER destacada, ADD COLUMN lanzamiento VARCHAR(60) NULL AFTER proximamente",
            "ALTER TABLE items ADD COLUMN max_por_cliente INT NULL AFTER limite",
        ],

        // Galería (fotos extra de cada número) y video (link de YouTube / Instagram)
        13 => [
            "CREATE TABLE IF NOT EXISTS item_fotos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                imagen VARCHAR(500) NOT NULL,
                orden INT NOT NULL DEFAULT 0,
                KEY idx_item (item_id),
                CONSTRAINT fk_foto_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE items ADD COLUMN video_url VARCHAR(300) NULL AFTER imagen",
        ],

        // Importación desde brihet.com.ar: de qué producto/variante viene cada cosa y el último precio traído
        14 => [
            "ALTER TABLE colecciones ADD COLUMN brihet_id INT NULL, ADD UNIQUE KEY uniq_brihet (brihet_id)",
            "ALTER TABLE items ADD COLUMN brihet_id INT NULL, ADD COLUMN brihet_precio DECIMAL(12,2) NULL, ADD KEY idx_brihet (brihet_id)",
        ],

        // Recargo interior: monto fijo por unidad que se suma en el carrito (general en Ajustes; se puede
        // cambiar por colección o por número). Queda guardado en el pedido.
        15 => [
            "ALTER TABLE colecciones ADD COLUMN recargo DECIMAL(12,2) NULL",
            "ALTER TABLE items ADD COLUMN recargo DECIMAL(12,2) NULL",
            "ALTER TABLE pedidos ADD COLUMN recargo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento",
            "ALTER TABLE pedido_items ADD COLUMN recargo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio",
        ],

        // Cantidad que Brihet tiene de cada número: dato de referencia para el operador, no se usa para vender
        16 => [
            "ALTER TABLE items ADD COLUMN brihet_stock INT NULL AFTER brihet_precio, ADD COLUMN brihet_stock_at DATETIME NULL AFTER brihet_stock",
        ],

        // Venta por encargue (stock virtual de Brihet): cada línea del pedido dice cuántas salen del depósito
        // (WePoint, 24 h) y cuántas hay que pedirle a Brihet, y en qué quedó ese encargue.
        17 => [
            "ALTER TABLE pedido_items ADD COLUMN fisico INT NOT NULL DEFAULT 0 AFTER cantidad, ADD COLUMN encargue INT NOT NULL DEFAULT 0 AFTER fisico, ADD COLUMN encargue_estado VARCHAR(12) NULL AFTER encargue",
            "UPDATE pedido_items SET fisico = cantidad",
        ],

        // De dónde salió la foto principal (brihet / salvat / manual): las de Brihet (chicas) se pueden
        // reemplazar por las de Salvat; las subidas a mano no se tocan.
        18 => [
            "ALTER TABLE items ADD COLUMN foto_origen VARCHAR(10) NULL AFTER imagen, ADD COLUMN salvat_url VARCHAR(300) NULL AFTER foto_origen",
            "UPDATE items SET foto_origen='brihet' WHERE brihet_id IS NOT NULL AND imagen LIKE 'uploads/%'",
        ],

        // Categorías administrables: cuáles van en el menú de arriba
        19 => [
            "ALTER TABLE categorias ADD COLUMN en_menu TINYINT(1) NOT NULL DEFAULT 1",
        ],

        // Botón de arrepentimiento (Res. 424/2020): cada pedido de revocación con su código de trámite
        20 => [
            "CREATE TABLE IF NOT EXISTS arrepentimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(16) NOT NULL UNIQUE,
                cliente_id INT NULL,
                pedido_id INT NULL,
                nombre VARCHAR(160) NOT NULL,
                email VARCHAR(160) NOT NULL,
                telefono VARCHAR(60) NULL,
                motivo TEXT NULL,
                estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Reservas también sobre el stock de Brihet (por encargue), no solo sobre el del depósito
        21 => [
            "ALTER TABLE reservas ADD COLUMN stock VARCHAR(10) NOT NULL DEFAULT 'fisico' AFTER cantidad",
        ],

        // Marketing: llegadas (un número pasó de 0 a tener stock) y campañas de mail enviadas por tandas
        22 => [
            "ALTER TABLE items ADD COLUMN llegada_at DATETIME NULL, ADD KEY idx_llegada (llegada_at)",
            "CREATE TABLE IF NOT EXISTS campanas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asunto VARCHAR(160) NOT NULL,
                titulo VARCHAR(160) NOT NULL,
                intro TEXT NULL,
                item_ids TEXT NOT NULL,
                audiencia VARCHAR(12) NOT NULL DEFAULT 'interesados',
                estado VARCHAR(12) NOT NULL DEFAULT 'borrador',
                lote INT NOT NULL DEFAULT 100,
                intervalo_min INT NOT NULL DEFAULT 15,
                total INT NOT NULL DEFAULT 0,
                enviados INT NOT NULL DEFAULT 0,
                ultimo_lote_at DATETIME NULL,
                creada_por VARCHAR(160) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS campana_destinos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campana_id INT NOT NULL,
                email VARCHAR(160) NOT NULL,
                nombre VARCHAR(160) NULL,
                estado VARCHAR(10) NOT NULL DEFAULT 'pendiente',
                enviado_at DATETIME NULL,
                UNIQUE KEY uniq_camp_email (campana_id, email),
                KEY idx_estado (campana_id, estado),
                CONSTRAINT fk_cd_camp FOREIGN KEY (campana_id) REFERENCES campanas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS mailing_bajas (
                email VARCHAR(160) PRIMARY KEY,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Total de números de la colección completa (no los cargados en la web): ficha de la colección
        23 => [
            "ALTER TABLE colecciones ADD COLUMN total_entregas INT NULL AFTER lanzamiento",
        ],

        // Campañas de una colección elegida a mano (ej. una Próximamente), además de las de llegadas
        24 => [
            "ALTER TABLE campanas ADD COLUMN coleccion_id INT NULL AFTER item_ids",
        ],

        // WePoint por ediciones: cada número es una edición (variante) de un título. El número queda vinculado
        // a la edición y la bandeja "Por publicar" tiene una fila por edición (clave v:<id_variante>, o p:<id_producto>
        // para un producto simple). La bandeja se vacía: la próxima sincronización la vuelve a llenar.
        25 => [
            "ALTER TABLE items ADD COLUMN wepoint_variante_id INT NULL AFTER wepoint_id, ADD KEY idx_wp_variante (wepoint_variante_id)",
            "DELETE FROM wepoint_nuevos",
            "ALTER TABLE wepoint_nuevos DROP PRIMARY KEY, ADD COLUMN clave VARCHAR(40) NOT NULL FIRST, ADD PRIMARY KEY (clave),
                ADD COLUMN wepoint_variante_id INT NULL AFTER wepoint_id, ADD COLUMN numero_edicion INT NULL AFTER nombre",
        ],

        // Puntos de retiro: los kioscos de la red de Caddy (data/kioscos-caddy.json, del archivo REC_CADY) reemplazan
        // a los de prueba. Los pedidos viejos conservan el nombre y la dirección del punto en el pedido.
        26 => [
            "DELETE FROM puntos_retiro",
            function (PDO $db) {
                $ins = $db->prepare("INSERT INTO puntos_retiro (nombre, direccion, localidad, provincia, cp, telefono, notas, lat, lng, activo) VALUES (?,?,?,?,?,?,?,?,?,1)");
                foreach (json_decode(file_get_contents(dirname(__DIR__) . '/data/kioscos-caddy.json'), true) as $k) {
                    $ins->execute([$k['nombre'], $k['direccion'], $k['localidad'], $k['provincia'], $k['cp'], $k['telefono'], $k['notas'], $k['lat'], $k['lng']]);
                }
            },
        ],

        // Los kioscos se muestran como "Kiosco 0005" (el código de vendedor solo no se entiende)
        27 => [
            "UPDATE puntos_retiro SET nombre = CONCAT('Kiosco ', nombre) WHERE nombre REGEXP '^[0-9]+$'",
        ],

        // Dos kioscos quedaron ubicados en Buenos Aires (coordenadas equivocadas en la base de Caddy):
        // un punto de Córdoba fuera de la provincia se queda sin ubicación hasta que el operador lo ubique.
        28 => [
            "UPDATE puntos_retiro SET lat=NULL, lng=NULL WHERE provincia='Córdoba' AND (lat < -35.1 OR lat > -29.4 OR lng > -61.7 OR lng < -65.8)",
        ],

        // Ubicación con Google para 46 kioscos que no la tenían (validada contra su localidad).
        // Solo completa: si el operador ya lo ubicó a mano, no se toca.
        29 => [
            function (PDO $db) {
                $upd = $db->prepare("UPDATE puntos_retiro SET lat=?, lng=? WHERE nombre=? AND lat IS NULL");
                foreach ([
                ['Kiosco 0007', -31.3637943, -64.218039],
                ['Kiosco 0008', -31.3804087, -64.2151658],
                ['Kiosco 0016', -31.4280241, -64.1854195],
                ['Kiosco 0045', -31.4150767, -64.1907562],
                ['Kiosco 0064', -31.4204827, -64.1812069],
                ['Kiosco 0095', -31.403466, -64.216903],
                ['Kiosco 0112', -31.3637943, -64.218039],
                ['Kiosco 0132', -31.4369171, -64.2381831],
                ['Kiosco 0148', -31.4116882, -64.1627451],
                ['Kiosco 0179', -31.3854092, -64.2199223],
                ['Kiosco 0209', -31.3698343, -64.2322532],
                ['Kiosco 0218', -31.4218994, -64.1877992],
                ['Kiosco 0220', -31.4166079, -64.1907159],
                ['Kiosco 0226', -31.3793353, -64.2235407],
                ['Kiosco 0241', -31.3891213, -64.1517154],
                ['Kiosco 0248', -31.431132, -64.2263971],
                ['Kiosco 0267', -31.4111355, -64.1907453],
                ['Kiosco 0271', -31.3793936, -64.2444895],
                ['Kiosco 0298', -31.3636711, -64.2073713],
                ['Kiosco 0359', -31.3727805, -64.2265129],
                ['Kiosco 0413', -31.3637943, -64.218039],
                ['Kiosco 0425', -31.4228881, -64.1770999],
                ['Kiosco 0455', -31.3798653, -64.1463893],
                ['Kiosco 0460', -31.4243895, -64.1831395],
                ['Kiosco 0540', -31.3852106, -64.1588568],
                ['Kiosco 0613', -30.9816772, -64.4912127],
                ['Kiosco 0632', -31.0316975, -64.505427],
                ['Kiosco 0663', -31.0751718, -64.4921423],
                ['Kiosco 0700', -31.6681472, -63.2011447],
                ['Kiosco 0712', -31.658399, -64.4289282],
                ['Kiosco 0725', -30.7272307, -64.8002372],
                ['Kiosco 0762', -31.2650283, -64.304794],
                ['Kiosco 0770', -30.8662498, -64.5268755],
                ['Kiosco 0805', -31.4195906, -64.4972432],
                ['Kiosco 0833', -31.4213718, -63.0508317],
                ['Kiosco 1077', -30.987035, -64.0824795],
                ['Kiosco 1620', -30.894533, -62.9983073],
                ['Kiosco 1622', -31.2376907, -63.0601189],
                ['Kiosco 1653', -31.4257725, -62.0843981],
                ['Kiosco 1677', -31.0113937, -62.6686369],
                ['Kiosco 1706', -31.3474495, -64.2695643],
                ['Kiosco 1708', -31.4113433, -64.177644],
                ['Kiosco 4061', -32.3682597, -62.3103619],
                ['Kiosco 4063', -32.4187848, -63.2389516],
                ['Kiosco 4084', -32.6298845, -62.6927503],
                ['Kiosco 4096', -32.408969, -63.2567431],
                ] as [$nombre, $lat, $lng]) $upd->execute([$lat, $lng, $nombre]);
            },
        ],

        // Mercado Pago (inc/mercadopago.php): link de pago del pedido y estado del pago
        30 => [
            "ALTER TABLE pedidos ADD COLUMN mp_preferencia_id VARCHAR(80) NULL, ADD COLUMN mp_init_point VARCHAR(300) NULL,
                ADD COLUMN mp_monto DECIMAL(12,2) NULL, ADD COLUMN mp_pago_id VARCHAR(40) NULL, ADD COLUMN mp_estado VARCHAR(60) NULL,
                ADD COLUMN mp_aprobado_at DATETIME NULL",
        ],

        // Links para elegir contraseña (inc/claves.php): invitación de operadores y "olvidé mi contraseña".
        // Se guarda el hash del token, nunca el token.
        31 => [
            "CREATE TABLE IF NOT EXISTS claves_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                tipo VARCHAR(12) NOT NULL,
                vence_at DATETIME NOT NULL,
                usado_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cliente (cliente_id),
                CONSTRAINT fk_clave_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // Salidas en kioscos (inc/salidas.php): si la colección está "en la calle", cuándo salió el Nº 1 y cada
        // cuánto sale. Cada número puede tener su fecha a mano, y los siguientes se calculan desde esa.
        32 => [
            "ALTER TABLE colecciones ADD COLUMN en_calle TINYINT(1) NOT NULL DEFAULT 0 AFTER proximamente,
                ADD COLUMN salida_inicio DATE NULL AFTER lanzamiento, ADD COLUMN periodicidad VARCHAR(12) NULL AFTER salida_inicio",
            "ALTER TABLE items ADD COLUMN salida DATE NULL",
        ],
    ];
}

function db_migrar(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS meta (clave VARCHAR(50) PRIMARY KEY, valor VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $actual = (int)$db->query("SELECT valor FROM meta WHERE clave='schema_version'")->fetchColumn();
    $pasos = schema_pasos();
    if ($actual >= max(array_keys($pasos))) return;
    foreach ($pasos as $v => $sqls) {
        if ($v <= $actual) continue;
        foreach ($sqls as $sql) is_callable($sql) ? $sql($db) : $db->exec($sql);
        $db->prepare("REPLACE INTO meta (clave, valor) VALUES ('schema_version', ?)")->execute([$v]);
    }
}
