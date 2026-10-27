<?php
// Ayuda del panel: cómo funciona la tienda, para el operador. Recorrido de un pedido, lo que ve el cliente,
// el menú punto por punto, lo automático, la rutina diaria y preguntas frecuentes. Solo texto: no toca datos.
require __DIR__ . '/_inc.php';

// [icono del menú, título, para qué sirve, qué hacés ahí]
$MENU = [
    ['inicio', 'Inicio', 'El resumen del día.', [
        'Arriba, los cuatro recuadros: <b>Por cobrar</b> (pedidos esperando el pago), <b>Por preparar</b> (pagos confirmados), <b>Para retirar</b> (ya están en el kiosco) y <b>Me faltan</b> (búsquedas nuevas). Tocá uno y vas directo a esa lista.',
        'Abajo, cuánto se vendió en el período y lo último que pasó.',
    ]],
    ['pedidos', 'Pedidos', 'Todas las compras de la tienda.', [
        'El número al lado de “Pedidos” en el menú son los que esperan el pago.',
        'Entrá a un pedido para ver qué compró, dónde retira y su historial. Desde <b>Actualizar</b> cambiás el estado; si dejás tildado <b>Avisarle por mail</b>, el cliente recibe el aviso.',
        '<b>Cancelar</b> devuelve el stock y el uso del cupón. <b>Misión cumplida</b> (retirado) suma los números a la colección del cliente como “Lo tengo”.',
        'Si un pedido tiene unidades <b>por encargue</b>, aparece el recuadro “Encargue”: marcá cada línea como <i>Pedido</i> cuando lo pedís y <i>Recibido en depósito</i> cuando llega. Cuando llega todo, se manda la orden a WePoint.',
        'Los <b>maestros</b> ven al pie “Borrar pedido”, solo para pedidos de prueba.',
    ]],
    ['faltantes', 'Me faltan', 'Los números que los clientes nos pidieron conseguir (“Conseguímelo”).', [
        'Cada pedido de búsqueda arranca en <i>Misión recibida</i>. Pasalo a <i>En búsqueda</i> cuando salgas a buscarlo, y respondé al cliente desde ahí.',
        'Si el número aparece en stock, el sistema se lo <b>reserva solo por ' . RESERVA_HORAS . ' h</b> y le avisa. También podés reservar a mano, extender o liberar desde el pedido.',
        'Los que dicen <span class="badge badge-azul">Por salir</span> son números que todavía no salieron: no hay que buscarlos, se reservan solos cuando llegan.',
    ]],
    ['colecciones', 'Productos y stock', 'Las colecciones, sus números, precios y stock.', [
        'Pestañas <b>Todas / En la calle / Ya salieron</b> y un buscador. Tocá una colección para editarla.',
        'En la colección: nombre, descripción, portada, categoría y el total de números. <b>Publicada</b> la muestra en la tienda; <b>Próximamente</b> la muestra sin venderla.',
        '<b>Salida en kioscos</b>: tildá <i>En la calle</i> si está saliendo ahora, cargá la fecha del N° 1 y la periodicidad. El sistema calcula cuándo sale cada número; si uno sale distinto, corregí su fecha en la tabla y los siguientes se corren solos.',
        'En la tabla de números: foto, título, precio y promo. El stock lo trae WePoint; vos podés <b>congelar</b> un número (deja de venderse) o ponerle un <b>tope</b> (vender como máximo esa cantidad, o un máximo por cliente).',
        'Para publicar un número hace falta <b>foto, título y precio</b>. Los productos nuevos que carga el depósito aparecen en <b>Por publicar</b> (número en el menú) hasta que les ponés foto.',
    ]],
    ['clientes', 'Clientes', 'Quiénes compran y qué coleccionan.', [
        'Cuántas colecciones sigue cada uno, cuántos números tiene, sus pedidos y lo que gastó, y si tiene búsquedas abiertas.',
    ]],
    ['cupones', 'Descuentos', 'Códigos de descuento que el cliente escribe en el carrito.', [
        'Por porcentaje o por importe fijo, con vencimiento, compra mínima, cantidad de usos, uno por cliente o solo para un mail.',
        'Se aplican sobre el precio de los productos, no sobre el recargo interior.',
    ]],
    ['marketing', 'Marketing', 'Mails de campaña a los clientes.', [
        '<b>Llegadas recientes</b>: tildá los números que llegaron, armá el mail a la derecha y mirá la vista previa antes de enviar.',
        '<b>Campaña de una colección</b>: para anunciar una colección (por ejemplo una Próximamente).',
        '<b>1ª ola</b> va a los interesados (suscriptos a esa colección, quienes la tienen en Mis colecciones y quienes buscaban esos números); la <b>2ª ola</b> a toda la tienda. Se manda por tandas para no saturar el correo.',
        'A los abonados que ya recibieron el mail de “te lo guardamos” de esos números no se les repite. Nunca se manda a quien pidió la baja.',
    ]],
    ['ventana', 'Ventana de inicio', 'La presentación grande que se abre al entrar a la tienda.', [
        'Arriba, las miniaturas de cada presentación en el orden en que se muestran. Tocá una para editarla abajo; “+ Agregar presentación” para sumar otra.',
        'Cada una lleva un video de YouTube (sin sonido, en loop) o una imagen, una etiqueta, título, texto y un botón que lleva a una colección. Con <b>Mostrar hasta</b> deja de aparecer sola.',
        'Con varias, el cliente las pasa con flechas y cada visita arranca por la siguiente. Revisala con “Ver cómo queda”.',
    ]],
    ['suscripciones', 'Suscripciones', 'Quiénes pidieron recibir novedades por mail.', [
        'Generales y por colección. Son la audiencia de las campañas de Marketing.',
    ]],
    ['puntos', 'Puntos de retiro', 'Los kioscos donde los clientes retiran.', [
        'Agregá un kiosco con su dirección y ubicalo en el mapa (con “Ubicar” o tocando el lugar). Solo se ofrecen los activos y ubicados.',
        'Podés <b>pausar</b> un kiosco (vacaciones, cierre) sin borrarlo. La tabla muestra cuántos pedidos tiene y cuántos esperan retiro.',
    ]],
    ['wepoint', 'WePoint', 'La conexión con el depósito.', [
        'Estado de la conexión, la última sincronización del stock y de los pedidos, y el transportista con el que se crean las órdenes.',
        'Normalmente no hay que tocar nada: todo corre solo (ver “Lo que hace el sistema solo”). Usalo para sincronizar a mano si querés ver un cambio ya.',
    ]],
    ['clientes', 'Equipo', 'Quiénes entran al panel. Solo lo ven los maestros.', [
        'Invitar a un operador por mail (elige su contraseña con el link), o quitarle el acceso.',
        'Los <b>maestros</b> se definen en el config.php del servidor y no se pueden quitar desde acá.',
    ]],
    ['ajustes', 'Configuración', 'Datos generales de la tienda.', [
        'Datos para transferencia bancaria (los ve el cliente al pagar así), la ventana de novedades, los datos legales de la empresa y el recargo interior.',
    ]],
];

admin_header('Ayuda', 'ayuda');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Ayuda</h1>
        <p class="muted" style="margin:4px 0 0;max-width:760px">Cómo funciona MeFaltaUno de punta a punta: lo que hace el cliente, lo que hacés vos en el panel y lo que el sistema hace solo.</p>
    </div>
</div>

<nav class="ayuda-indice">
    <a href="#recorrido">El recorrido de un pedido</a>
    <a href="#cliente">Cómo ve la tienda el cliente</a>
    <a href="#menu">El menú, punto por punto</a>
    <a href="#solo">Lo que hace el sistema solo</a>
    <a href="#rutina">Rutina del día</a>
    <a href="#preguntas">Preguntas frecuentes</a>
</nav>

<section class="ayuda-seccion" id="recorrido">
    <h2>El recorrido de un pedido</h2>
    <p class="muted">Desde que el cliente compra hasta que retira. En <b>amarillo</b>, lo que hacés vos; lo demás pasa solo.</p>
    <ol class="ayuda-pasos">
        <li><b>El cliente compra</b><span>Arma el carrito, elige su kiosco y cómo paga. El pedido entra como <span class="badge badge-amarillo">Misión recibida</span> y le llega un mail.</span></li>
        <li><b>Paga</b><span><b>Mercado Pago:</b> se confirma solo al aprobarse el pago. <b class="ayuda-vos">Transferencia:</b> cuando ves la plata en el banco, pasás el pedido a <i>Pago confirmado</i>.</span></li>
        <li><b>Pago confirmado</b><span>La orden se manda sola a WePoint para que la preparen. Si tiene unidades <b>por encargue</b>, <b class="ayuda-vos">las pedís al editor</b> y la orden sale cuando llegan al depósito.</span></li>
        <li><b>El depósito prepara</b><span>WePoint avisa el avance y el pedido pasa a <i>Preparando</i>. Cuando queda listo para enviar, se crea solo el envío de Caddy al kiosco.</span></li>
        <li><b>Llega al kiosco</b><span>El pedido pasa a <span class="badge badge-azul">Listo para retirar</span> y el cliente recibe el mail para ir a buscarlo.</span></li>
        <li><b>El cliente retira</b><span><b class="ayuda-vos">Marcás <i>Misión cumplida</i></b>. Los números se suman solos a su colección como “Lo tengo”.</span></li>
    </ol>
    <p class="small muted">Si algo sale mal, <b>Cancelado</b> devuelve el stock. El cliente puede usar el <b>Botón de arrepentimiento</b> dentro de los <?= DIAS_ARREPENTIMIENTO ?> días: te llega un mail con el código y queda anotado en el historial del pedido.</p>
</section>

<section class="ayuda-seccion" id="cliente">
    <h2>Cómo ve la tienda el cliente</h2>
    <div class="ayuda-grilla">
        <div class="card"><h3>Sus colecciones</h3><p>Suma colecciones a <b>Mis colecciones</b> y marca <b>“Lo tengo”</b> en los números que ya tiene, los haya comprado acá o no. Es su libreta: ve su progreso (“1 de 119 · te faltan 118”).</p></div>
        <div class="card"><h3>Disponibilidad</h3><p><b>Entrega en 24 h:</b> está en nuestro depósito. <b>Por encargue:</b> lo tiene el editor y se pide después de la compra, con sus plazos. <b>En camino:</b> ese número ya está en un pedido suyo sin retirar.</p></div>
        <div class="card"><h3>“Conseguímelo”</h3><p>Tilda “Lo quiero” en números sin stock y nos pide que los busquemos. Te llega a <b>Me faltan</b>. Cuando aparece stock, se le reserva <?= RESERVA_HORAS ?> h y le avisamos.</p></div>
        <div class="card"><h3>“La colecciono” (abono)</h3><p>En colecciones <b>En la calle</b> o <b>Próximamente</b>. Cada número nuevo que llega al depósito se le reserva <?= RESERVA_HORAS ?> h con un mail; no se le cobra nada solo. Puede destildar números. Tiene prioridad sobre los pedidos sueltos. Si deja vencer <?= ABONO_VENCIDAS_PAUSA ?> reservas seguidas, se pausa y le avisamos. No pasa por Me faltan.</p></div>
        <div class="card"><h3>Próximamente y fechas</h3><p>Las colecciones Próximamente se ven pero no se venden. Cada número muestra “Sale aprox. el…”, calculado con las fechas que cargás en la colección.</p></div>
        <div class="card"><h3>Comprar</h3><p>Elige su <b>kiosco</b> en el mapa; la próxima vez ya le aparece el mismo y el mapa solo si quiere cambiarlo. Paga con Mercado Pago (en una ventana dentro de la tienda) o por transferencia.</p></div>
        <div class="card"><h3>Mails que recibe</h3><p>Al comprar, en cada cambio de estado de su pedido, cuando le reservamos un número y cuando contestás una búsqueda. Las campañas de Marketing, con su link de baja.</p></div>
        <div class="card"><h3>Su cuenta</h3><p>Ve sus pedidos, sus búsquedas y sus colecciones. Si olvida la contraseña, la recupera por mail desde “¿Olvidaste tu contraseña?”.</p></div>
    </div>
</section>

<section class="ayuda-seccion" id="menu">
    <h2>El menú, punto por punto</h2>
    <div class="ayuda-menu">
        <?php foreach ($MENU as [$ico, $titulo, $para, $puntos]): ?>
            <details class="card ayuda-item" <?= $titulo === 'Inicio' ? 'open' : '' ?>>
                <summary><?= admin_icono($ico) ?><span><b><?= e($titulo) ?></b><small><?= e($para) ?></small></span></summary>
                <ul><?php foreach ($puntos as $pt): ?><li><?= $pt ?></li><?php endforeach; ?></ul>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="ayuda-seccion" id="solo">
    <h2>Lo que hace el sistema solo</h2>
    <p class="muted">Cada pocos minutos el servidor corre una tarea que:</p>
    <ul class="ayuda-lista">
        <li>Trae de <b>WePoint el stock</b> de cada número y marca las <b>llegadas</b> (los que pasaron de no tener a tener).</li>
        <li>Con lo que llegó, <b>reserva</b> primero a los abonados y después a quienes lo buscaban, y les manda el mail. Libera las reservas vencidas y se las pasa al siguiente.</li>
        <li>Actualiza el <b>estado de los pedidos</b> según WePoint y crea el envío de <b>Caddy</b> cuando el depósito lo deja listo.</li>
        <li>Envía las <b>tandas</b> pendientes de las campañas de Marketing.</li>
        <li>Revisa los pagos de <b>Mercado Pago</b> que hayan quedado sin confirmar (además, Mercado Pago avisa al instante cada pago).</li>
    </ul>
</section>

<section class="ayuda-seccion" id="rutina">
    <h2>Rutina del día</h2>
    <ol class="ayuda-lista">
        <li><b>Inicio:</b> mirá los cuatro recuadros.</li>
        <li><b>Por cobrar:</b> confirmá las transferencias que ya entraron al banco.</li>
        <li><b>Encargues:</b> en los pedidos con unidades por encargue, pedíselas al editor y marcá cada paso.</li>
        <li><b>Para retirar:</b> cuando el kiosco te avise que retiraron, marcá <i>Misión cumplida</i>.</li>
        <li><b>Me faltan:</b> respondé las búsquedas nuevas (las “Por salir” no hace falta buscarlas).</li>
        <li><b>Por publicar:</b> ponele foto a los productos nuevos del depósito.</li>
        <li>Una vez por semana: <b>Marketing → Llegadas recientes</b> para avisar lo que llegó, y revisá las fechas de las colecciones <b>En la calle</b>.</li>
    </ol>
</section>

<section class="ayuda-seccion" id="preguntas">
    <h2>Preguntas frecuentes</h2>
    <div class="ayuda-faq">
        <details class="card"><summary>Un cliente dice que no le llegó el mail</summary><p>Que revise el correo no deseado (spam). Si no aparece, el pedido igual está en su cuenta con todo el detalle y el estado al día.</p></details>
        <details class="card"><summary>¿Qué pasa con el stock si cancelo un pedido?</summary><p>Vuelve solo al depósito (lo que era por encargue nunca había salido) y se descuenta el uso del cupón.</p></details>
        <details class="card"><summary>¿Por qué un número dice “Se vende 0” si WePoint tiene stock?</summary><p>Puede estar <b>congelado</b>, tener un <b>tope</b>, o estar <b>reservado</b> para alguien que lo buscaba o lo tiene en su abono (lo ves como “reservado” en la tabla de la colección).</p></details>
        <details class="card"><summary>¿Cómo publico un producto nuevo que cargó el depósito?</summary><p>Aparece en <b>Productos y stock → Por publicar</b>. Ponele foto (y revisá título y precio) y se publica en su colección.</p></details>
        <details class="card"><summary>¿Cómo hago que una colección muestre “por qué número va”?</summary><p>En la colección, recuadro <b>Salida en kioscos</b>: fecha del N° 1 y periodicidad. Con eso se calcula todo; publicar un número con foto no lo adelanta.</p></details>
        <details class="card"><summary>“En la calle” y “En camino”, ¿no es lo mismo?</summary><p>No. <b>En la calle</b> es de la colección: está saliendo en kioscos ahora. <b>En camino</b> es del cliente: ese número está en un pedido suyo que todavía no retiró.</p></details>
        <details class="card"><summary>Hice compras de prueba, ¿cómo las saco?</summary><p>Un <b>maestro</b> puede borrarlas desde el pie del pedido (“Borrar pedido”) o de la búsqueda. Si no estaba cancelado, primero lo cancela sin avisarle al cliente y el stock vuelve.</p></details>
        <details class="card"><summary>Un cliente olvidó su contraseña</summary><p>Que use “¿Olvidaste tu contraseña?” en la pantalla de ingreso: le llega un link por mail que vence en <?= CLAVES_HORAS['recuperar'] ?> horas.</p></details>
    </div>
</section>

<?php admin_footer(); ?>
