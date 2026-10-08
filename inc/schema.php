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
    ];
}

function db_migrar(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS meta (clave VARCHAR(50) PRIMARY KEY, valor VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $actual = (int)$db->query("SELECT valor FROM meta WHERE clave='schema_version'")->fetchColumn();
    $pasos = schema_pasos();
    if ($actual >= max(array_keys($pasos))) return;
    foreach ($pasos as $v => $sqls) {
        if ($v <= $actual) continue;
        foreach ($sqls as $sql) $db->exec($sql);
        $db->prepare("REPLACE INTO meta (clave, valor) VALUES ('schema_version', ?)")->execute([$v]);
    }
}
