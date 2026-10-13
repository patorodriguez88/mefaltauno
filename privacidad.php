<?php
// Política de privacidad y cookies (Ley 25.326). BORRADOR: revisar con un abogado antes del lanzamiento.
require __DIR__ . '/inc/bootstrap.php';
$titulo = 'Política de privacidad y cookies';
require __DIR__ . '/inc/header.php';
$empresa = legal('razon_social');
?>
<section class="section pagina-legal">
    <div class="container">
        <h1>Política de privacidad y cookies</h1>
        <p class="muted">Última actualización: <?= date('d/m/Y', filemtime(__FILE__)) ?></p>

        <p>En <b>MeFaltaUno</b> cuidamos tus datos personales conforme a la <b>Ley 25.326</b> de Protección de los Datos Personales. El responsable de la base de datos es <b><?= e($empresa) ?></b><?= legal('cuit') ? ', CUIT ' . e(legal('cuit')) : '' ?><?= legal('domicilio') ? ', ' . e(legal('domicilio')) : '' ?>.</p>

        <h2>Qué datos pedimos y para qué</h2>
        <ul>
            <li><b>Cuenta:</b> nombre, apellido, mail, teléfono y contraseña (guardada cifrada). Para identificarte y avisarte del estado de tus pedidos y búsquedas.</li>
            <li><b>Compras:</b> quién retira, teléfono, punto de retiro elegido y medio de pago. Para preparar, entregar y facturar tus pedidos. No guardamos datos de tarjetas: los pagos con tarjeta los procesa Mercado Pago.</li>
            <li><b>Tus colecciones:</b> qué números tenés y cuáles te faltan, para mostrarte tu progreso y salir a buscar lo que te falta.</li>
            <li><b>Suscripciones:</b> tu mail (y opcionalmente nombre y teléfono) si pediste recibir novedades.</li>
        </ul>
        <p>Compartimos solo los datos necesarios con quienes nos ayudan a cumplir tu pedido: el depósito que lo prepara, la empresa de logística que lo lleva al punto de retiro y el procesador de pagos. <b>No vendemos ni cedemos tus datos</b> para publicidad de terceros.</p>

        <h2>Tus derechos</h2>
        <p>Podés pedir en cualquier momento <b>acceder, rectificar, actualizar o suprimir</b> tus datos escribiendo a <b><?= e(legal('email')) ?></b>. Parte de tus datos también los podés editar desde <a href="<?= url('cuenta.php?tab=datos') ?>">Mi cuenta</a>.</p>
        <p class="aviso small">El titular de los datos personales tiene la facultad de ejercer el derecho de acceso a los mismos en forma gratuita a intervalos no inferiores a seis meses, salvo que se acredite un interés legítimo al efecto conforme lo establecido en el artículo 14, inciso 3 de la Ley Nº 25.326. La AGENCIA DE ACCESO A LA INFORMACIÓN PÚBLICA, en su carácter de Órgano de Control de la Ley Nº 25.326, tiene la atribución de atender las denuncias y reclamos que interpongan quienes resulten afectados en sus derechos por incumplimiento de las normas vigentes en materia de protección de datos personales.</p>

        <h2 id="cookies">Cookies</h2>
        <p>Usamos <b>solo cookies y almacenamiento necesarios</b> para que la tienda funcione:</p>
        <ul>
            <li><b>Sesión</b> (<code>mfu_sess</code>): mantiene tu cuenta iniciada y tu carrito mientras navegás.</li>
            <li><b>Preferencias del navegador</b> (almacenamiento local): recuerda, por ejemplo, que ya viste el aviso de cookies o que cerraste una historieta.</li>
        </ul>
        <p>No usamos cookies de publicidad ni de seguimiento de terceros. Si en el futuro sumamos herramientas de medición, lo vamos a informar acá y a pedir tu consentimiento. Podés borrar las cookies desde la configuración de tu navegador; si lo hacés, se cierra tu sesión.</p>

        <h2>Seguridad y conservación</h2>
        <p>Aplicamos medidas técnicas razonables para proteger tus datos (conexión cifrada, contraseñas cifradas, acceso restringido). Conservamos los datos mientras tengas tu cuenta o lo exijan las normas fiscales y comerciales.</p>
    </div>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
