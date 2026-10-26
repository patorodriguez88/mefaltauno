// Modal de pago con Mercado Pago (Checkout Bricks) en la página del pedido (pedido.php).
// El formulario de la tarjeta es de Mercado Pago (la tienda nunca ve el número); el cobro lo hace
// mp-pagar.php con el total del pedido. "Cuenta de Mercado Pago" sale a pagar a Mercado Pago y vuelve.
(() => {
    const dlg = document.getElementById('modal-pago');
    if (!dlg || typeof MercadoPago === 'undefined') return;
    const cfg = dlg.dataset;
    const zona = { brick: dlg.querySelector('#mp-brick'), banco: dlg.querySelector('.mp-banco'), resultado: dlg.querySelector('.mp-resultado') };
    let brick = null;

    const aviso = dlg.querySelector('.modal-pago-aviso');
    const ver = (cual) => {
        Object.entries(zona).forEach(([k, el]) => { el.hidden = k !== cual; });
        aviso.hidden = cual !== 'brick';
    };

    async function crearFormulario() {
        ver('brick');
        if (brick) return;
        const mp = new MercadoPago(cfg.publicKey, { locale: 'es-AR' });
        const metodos = { creditCard: 'all', debitCard: 'all', maxInstallments: 12 };
        if (cfg.preferencia) metodos.mercadoPago = 'all';
        brick = await mp.bricks().create('payment', 'mp-brick', {
            initialization: { amount: Number(cfg.total), preferenceId: cfg.preferencia || undefined, payer: { email: cfg.email } },
            customization: {
                paymentMethods: metodos,
                // Botón "Pagar" con el amarillo de la tienda (también mientras procesa); bordes y foco en verde
                visual: { style: { customVariables: {
                    baseColor: '#f1e52f', baseColorFirstVariant: '#d9cc12', baseColorSecondVariant: '#c4b80f',
                    buttonTextColor: '#00393c', outlinePrimaryColor: '#006368', borderRadiusLarge: '14px',
                } } },
            },
            callbacks: {
                onReady: () => {},
                onError: (error) => console.error('[Mercado Pago]', error),
                // "Cuenta de Mercado Pago": el Brick lleva a pagar a Mercado Pago y vuelve al pedido (no hay tarjeta que cobrar acá)
                onSubmit: ({ selectedPaymentMethod, formData }) => selectedPaymentMethod === 'wallet_purchase' ? Promise.resolve() : cobrar(formData),
            },
        });
    }

    async function pedir(opciones, query = '') {
        const res = await fetch(MFU.base + 'mp-pagar.php' + query, opciones);
        const datos = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(datos.error || 'No pudimos procesar el pago. Probá de nuevo.');
        return datos;
    }

    async function cobrar(formData) {
        try {
            const r = await pedir({
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF': MFU.csrf },
                body: JSON.stringify({ pedido: Number(cfg.pedido), formData }),
            });
            r.desafio ? validarConBanco(r) : mostrar(r);
        } catch (e) {
            mostrar({ estado: 'error', mensaje: e.message });
        }
    }

    // 3-D Secure: el banco muestra su validación dentro del modal; mientras, se consulta el estado del pago
    function validarConBanco(r) {
        ver('banco');
        const form = document.createElement('form');
        form.method = 'post';
        form.action = r.desafio.url;
        form.target = 'mp-banco-frame';
        form.innerHTML = '<input type="hidden" name="creq">';
        form.querySelector('input').value = r.desafio.creq;
        document.body.appendChild(form);
        form.submit();
        form.remove();
        let vueltas = 0;
        const consultar = async () => {
            vueltas++;
            try {
                const e = await pedir({ method: 'GET' }, '?pedido=' + encodeURIComponent(cfg.pedido) + '&pago=' + encodeURIComponent(r.pago_id));
                if (e.estado !== 'pending' || vueltas > 60) return mostrar(e);
            } catch (err) { if (vueltas > 60) return mostrar({ estado: 'error', mensaje: err.message }); }
            setTimeout(consultar, 3000);
        };
        setTimeout(consultar, 5000);
    }

    function mostrar(r) {
        ver('resultado');
        const ok = r.estado === 'approved';
        const revision = ['pending', 'in_process', 'authorized'].includes(r.estado);
        zona.resultado.className = 'mp-resultado ' + (ok ? 'ok' : revision ? 'revision' : 'rechazo');
        zona.resultado.querySelector('.mp-resultado-icono').textContent = ok ? '⚡' : revision ? '⏳' : '⚠️';
        zona.resultado.querySelector('h3').textContent = ok ? '¡Pago aprobado!' : revision ? 'Pago en revisión' : 'El pago no se completó';
        zona.resultado.querySelector('p').textContent = r.mensaje || '';
        zona.resultado.querySelector('.mp-reintentar').hidden = ok || revision;
        if (ok || revision) setTimeout(() => location.reload(), 2500);
    }

    async function reintentar() {
        if (brick) { await brick.unmount(); brick = null; }
        crearFormulario();
    }

    function abrir() {
        dlg.showModal();
        crearFormulario().catch((e) => mostrar({ estado: 'error', mensaje: 'No pudimos cargar Mercado Pago. ' + (e.message || '') }));
    }

    document.querySelectorAll('[data-abrir-pago]').forEach((b) => b.addEventListener('click', abrir));
    dlg.querySelector('.mp-reintentar').addEventListener('click', reintentar);
    dlg.querySelector('.modal-pago-cerrar').addEventListener('click', () => dlg.close());
    if (cfg.abrir === '1') abrir();
})();
