# MeFaltaUno

E-commerce de MeFaltaUno (Dinter S.A.): colecciones para grandes. El cliente marca los números
que tiene, compra los que hay en stock y pide los que le faltan ("Conseguímelo"); el operador
recibe un mail y gestiona cada pedido desde el panel.

- **Local:** http://localhost:8000/mefaltauno/
- **Producción:** https://www.mefaltauno.com.ar (deploy automático por FTP al hacer push a `main`).
  `mefaltauno.com.ar` y `web.mefaltauno.com.ar` redirigen ahí (`.htaccess`), igual que las direcciones
  de la tienda anterior en Tiendanube (`/productos/<colección>/`, `/libros/`, etc.).

## Estructura
- `index.php`, `colecciones.php`, `coleccion.php`: tienda pública
- `carrito.php`, `checkout.php`, `pedido.php`: compra
- `cuenta.php`: panel del cliente (mis colecciones, me faltan, pedidos, datos)
- `me-falta.php`: pedido libre de un número que no está en la web
- `api.php`: acciones AJAX (carrito, "lo tengo", "conseguímelo")
- `admin/`: panel del operador (pedidos, faltantes, colecciones y stock, clientes)
- `inc/`: bootstrap, esquema de base (migraciones automáticas), mails, vistas
- `data/catalogo-tiendanube.json`: catálogo inicial extraído de Tiendanube

## Puesta en marcha
1. Crear la base MySQL y copiar `config.sample.php` como `config.php` (no se sube al repo).
2. Las tablas se crean solas en la primera visita.
3. Cargar el catálogo: `php tools/importar.php` o, en el servidor, desde el panel admin.
4. Los emails listados en `ADMIN_EMAILS` tienen acceso a `/admin/` (registrarse primero).

## Deploy
`.github/workflows/main.yml` sincroniza el repo por FTP con `user@web.mefaltauno.com.ar`
(la carpeta que sirve www, mefaltauno.com.ar y web.). La contraseña va en el secret `FTP_PASSWORD` del repo.
