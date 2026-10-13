<?php
// Términos y condiciones de compra. BORRADOR: revisar con un abogado antes del lanzamiento.
require __DIR__ . '/inc/bootstrap.php';
$titulo = 'Términos y condiciones';
require __DIR__ . '/inc/header.php';
$empresa = legal('razon_social');
?>
<section class="section pagina-legal">
    <div class="container">
        <h1>Términos y condiciones</h1>
        <p class="muted">Última actualización: <?= date('d/m/Y', filemtime(__FILE__)) ?></p>

        <p>Estos términos regulan el uso de <b>MeFaltaUno</b> y las compras que hagas en la tienda. El sitio es operado por <b><?= e($empresa) ?></b><?= legal('cuit') ? ', CUIT ' . e(legal('cuit')) : '' ?><?= legal('domicilio') ? ', con domicilio en ' . e(legal('domicilio')) : '' ?>. Al registrarte o comprar, aceptás estos términos y nuestra <a href="<?= url('privacidad.php') ?>">Política de privacidad</a>.</p>

        <h2>1. Tu cuenta</h2>
        <p>Para comprar, pedir un número que te falta o llevar la cuenta de tus colecciones necesitás una cuenta. Sos responsable de mantener tu contraseña en reserva y de que los datos que cargues sean correctos (en especial tu mail y tu teléfono, que usamos para avisarte del estado de tus pedidos).</p>

        <h2>2. Productos y precios</h2>
        <ul>
            <li>Los precios están expresados en <b>pesos argentinos</b> e incluyen IVA. Pueden variar sin previo aviso, pero <b>se respeta el precio vigente al momento de confirmar tu pedido</b>.</li>
            <li>Las fotos son ilustrativas: el producto puede tener diferencias menores de color o de presentación.</li>
            <li>Algunos números pueden tener un <b>máximo de unidades por cliente</b>, que se indica en la página del producto.</li>
            <li><b>Recargo interior:</b> a cada unidad se le suma un recargo fijo por gastos de distribución, que se informa por separado en el carrito y en el resumen antes de confirmar la compra.</li>
        </ul>

        <h2>3. Disponibilidad y plazos de entrega</h2>
        <ul>
            <li><b>Stock propio (“Entrega en 24 h”):</b> el producto está en nuestro depósito y queda listo para retirar en el punto de retiro elegido, en general dentro de las 24 horas hábiles de confirmado el pago.</li>
            <li><b>Por encargue:</b> el producto se pide a la editorial o al distribuidor después de tu compra. <b>La entrega queda sujeta a los plazos del editor</b>; te contactamos para coordinarla. Si finalmente no se pudiera conseguir, te devolvemos el importe abonado por esas unidades.</li>
            <li>Cuando un pedido combina unidades en stock y por encargue, el carrito te muestra cuántas van con cada plazo.</li>
            <li><b>Reservas:</b> si pediste un número que te faltaba (“Conseguímelo”) y lo encontramos, te lo reservamos durante <?= RESERVA_HORAS ?> horas para que lo compres. Pasado ese plazo, vuelve a la venta.</li>
        </ul>

        <h2>4. Retiro de los pedidos</h2>
        <p>Los pedidos se retiran en el <b>punto de retiro</b> (kiosco) que elijas al comprar. El envío hasta ese punto está bonificado. Te avisamos por mail cuando tu pedido esté listo; para retirarlo puede pedírsete el número de pedido y un documento de quien retira.</p>

        <h2>5. Pagos</h2>
        <p>Podés pagar con <b>Mercado Pago</b> o por <b>transferencia bancaria</b>. El pedido se prepara una vez acreditado el pago. Los códigos de descuento se aplican sobre el precio de los productos y no sobre el recargo interior.</p>

        <h2 id="arrepentimiento">6. Derecho de arrepentimiento</h2>
        <p>Por tratarse de una venta a distancia, tenés derecho a <b>revocar tu compra dentro de los <?= DIAS_ARREPENTIMIENTO ?> días corridos</b> desde que recibiste el producto o desde la celebración del contrato, lo último que ocurra, sin costo ni responsabilidad alguna (art. 34 de la Ley 24.240). Podés hacerlo desde el <a href="<?= url('arrepentimiento.php') ?>"><b>Botón de arrepentimiento</b></a>: te enviamos un código de trámite y coordinamos la devolución del producto, que debe estar en las mismas condiciones en que lo recibiste. Te reintegramos el importe por el mismo medio de pago.</p>

        <h2>7. Cambios, fallas y garantía</h2>
        <p>Si un producto llega con fallas o no corresponde a lo que compraste, escribinos a <b><?= e(legal('email')) ?></b> dentro de los 30 días de recibido. Rige la garantía legal establecida por la Ley 24.240 de Defensa del Consumidor.</p>

        <h2>8. Cancelaciones</h2>
        <p>Podemos cancelar un pedido si el pago no se acredita, si hay un error evidente de precio o de stock, o si un producto por encargue no se puede conseguir. En esos casos te avisamos y te devolvemos lo abonado.</p>

        <h2>9. Comunicaciones</h2>
        <p>Te enviamos mails sobre tus pedidos y tus búsquedas. Las novedades y lanzamientos solo si te suscribiste; podés darte de baja en cualquier momento desde el link que viene en cada mail.</p>

        <h2>10. Defensa del consumidor y jurisdicción</h2>
        <p>Ante cualquier reclamo, escribinos primero a <b><?= e(legal('email')) ?></b><?= legal('telefono') ? ' o llamanos al ' . e(legal('telefono')) : '' ?>. También podés recurrir a la <a href="<?= e(URL_DEFENSA_CONSUMIDOR) ?>" target="_blank" rel="noopener">Ventanilla Federal de Defensa del Consumidor</a>. Estos términos se rigen por las leyes de la República Argentina; son competentes los tribunales del domicilio del consumidor.</p>
    </div>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
