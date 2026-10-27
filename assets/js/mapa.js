// Mapas de puntos de retiro. Lo usan el checkout y el admin.
// Con GOOGLE_API_KEY_BROWSER en config.php se usa Google Maps (mapa y búsqueda de direcciones);
// sin clave, Leaflet + OpenStreetMap. Las dos variantes se usan igual a través de crearMapa().

const CENTRO_AR = [-31.4201, -64.1888];  // Córdoba, si no hay otra referencia
const usaGoogle = () => !!(window.google && google.maps && google.maps.Map);

// Pines: turquesa (kiosco), amarillo (elegido) y rojo (la dirección del cliente)
const PINES = {
    '':     { color: '#006368', tam: 30 },
    activo: { color: '#d9cc12', tam: 36 },
    yo:     { color: '#c53030', tam: 20, circulo: true },
    pausado: { color: '#9aa5a6', tam: 26 },
};

function pinGoogle(clase) {
    const p = PINES[clase || ''];
    const svg = p.circulo
        ? `<svg xmlns="http://www.w3.org/2000/svg" width="${p.tam}" height="${p.tam}"><circle cx="10" cy="10" r="7" fill="${p.color}" stroke="#fff" stroke-width="3"/></svg>`
        : `<svg xmlns="http://www.w3.org/2000/svg" width="${p.tam}" height="${p.tam}" viewBox="0 0 30 30"><path d="M15 29s11-10 11-17a11 11 0 0 0-22 0c0 7 11 17 11 17z" fill="${p.color}" stroke="#fff" stroke-width="3"/></svg>`;
    return {
        url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
        scaledSize: new google.maps.Size(p.tam, p.tam),
        anchor: p.circulo ? new google.maps.Point(p.tam / 2, p.tam / 2) : new google.maps.Point(p.tam / 2, p.tam),
    };
}

function pinIcono(clase) {
    return L.divIcon({ className: 'pin ' + (clase || ''), html: '<span></span>', iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -28] });
}

// Mapa con la misma interfaz en Google y en Leaflet:
// marcador(), encuadrar(), centrar(), alTocar(), cerrarPopups()
function crearMapa(el, centro, zoom, opciones = {}) {
    el = typeof el === 'string' ? document.getElementById(el) : el;
    centro = centro || CENTRO_AR;
    zoom = zoom || 12;
    return usaGoogle() ? mapaGoogle(el, centro, zoom, opciones) : mapaLeaflet(el, centro, zoom, opciones);
}

function mapaGoogle(el, centro, zoom, opciones) {
    const map = new google.maps.Map(el, {
        center: { lat: centro[0], lng: centro[1] }, zoom,
        gestureHandling: opciones.rueda ? 'greedy' : 'cooperative',
        mapTypeControl: false, streetViewControl: false, fullscreenControl: false, clickableIcons: false,
    });
    const info = new google.maps.InfoWindow();
    return {
        marcador(lat, lng, o = {}) {
            const m = new google.maps.Marker({ map, position: { lat: +lat, lng: +lng }, icon: pinGoogle(o.clase), draggable: !!o.arrastrable, title: o.titulo || '', zIndex: o.clase ? 10 : 1 });
            if (o.html || o.onClick) m.addListener('click', () => {
                if (o.html) { info.setContent(o.html); info.open({ map, anchor: m }); }
                if (o.onClick) o.onClick();
            });
            if (o.onMover) m.addListener('dragend', ev => o.onMover(ev.latLng.lat(), ev.latLng.lng()));
            return {
                mover: (la, ln) => m.setPosition({ lat: +la, lng: +ln }),
                clase: c => { m.setIcon(pinGoogle(c)); m.setZIndex(c ? 10 : 1); },
                quitar: () => m.setMap(null),
            };
        },
        encuadrar(pts, maxZoom) {
            if (!pts.length) return;
            if (pts.length === 1) { map.setCenter({ lat: +pts[0][0], lng: +pts[0][1] }); map.setZoom(maxZoom); return; }
            const b = new google.maps.LatLngBounds();
            pts.forEach(p => b.extend({ lat: +p[0], lng: +p[1] }));
            map.fitBounds(b, 40);
            google.maps.event.addListenerOnce(map, 'idle', () => { if (map.getZoom() > maxZoom) map.setZoom(maxZoom); });
        },
        centrar(lat, lng, z) { map.panTo({ lat: +lat, lng: +lng }); if (z) map.setZoom(z); },
        alTocar(cb) { map.addListener('click', ev => cb(ev.latLng.lat(), ev.latLng.lng())); },
        cerrarPopups() { info.close(); },
    };
}

function mapaLeaflet(el, centro, zoom, opciones) {
    const map = L.map(el, { scrollWheelZoom: !!opciones.rueda }).setView(centro, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    return {
        marcador(lat, lng, o = {}) {
            const m = L.marker([lat, lng], { icon: pinIcono(o.clase), draggable: !!o.arrastrable }).addTo(map);
            if (o.html) m.bindPopup(o.html);
            if (o.titulo) m.bindTooltip(o.titulo);
            if (o.onClick) m.on('click', o.onClick);
            if (o.onMover) m.on('dragend', () => { const p = m.getLatLng(); o.onMover(p.lat, p.lng); });
            return {
                mover: (la, ln) => m.setLatLng([la, ln]),
                clase: c => m.setIcon(pinIcono(c)),
                quitar: () => map.removeLayer(m),
            };
        },
        encuadrar(pts, maxZoom) { if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.2), { maxZoom }); },
        centrar(lat, lng, z) { z ? map.setView([lat, lng], z) : map.panTo([lat, lng]); },
        alTocar(cb) { map.on('click', ev => cb(ev.latlng.lat, ev.latlng.lng)); },
        cerrarPopups() { map.closePopup(); },
    };
}

// Dirección → {lat, lng, nombre}: con Google si está cargado, si no con Nominatim (OpenStreetMap). Limitado a Argentina.
async function geocodificar(texto) {
    if (usaGoogle()) {
        try {
            const { results } = await new google.maps.Geocoder().geocode({
                address: texto, componentRestrictions: { country: 'AR' }, region: 'ar',
                bounds: { south: -35.1, west: -65.8, north: -29.4, east: -61.7 },   // prioriza la provincia de Córdoba
            });
            if (!results.length) return null;
            const l = results[0].geometry.location;
            return { lat: l.lat(), lng: l.lng(), nombre: results[0].formatted_address };
        } catch (e) {
            if (e && e.code === 'ZERO_RESULTS') return null;
            throw new Error('No pudimos buscar la dirección. Probá de nuevo en unos segundos.');
        }
    }
    const u = 'https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ar&limit=1&accept-language=es&q=' + encodeURIComponent(texto);
    const r = await fetch(u, { headers: { 'Accept': 'application/json' } });
    if (!r.ok) throw new Error('No pudimos buscar la dirección. Probá de nuevo en unos segundos.');
    const d = await r.json();
    if (!d.length) return null;
    return { lat: +d[0].lat, lng: +d[0].lon, nombre: d[0].display_name };
}

function distanciaKm(a, b) {
    const R = 6371, rad = x => x * Math.PI / 180;
    const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

function fmtKm(km) {
    return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(km < 10 ? 1 : 0).replace('.', ',') + ' km';
}

// ─── Checkout: elegir punto de retiro ──────────────────────────────────────
// Si el cliente ya tiene su kiosco de siempre, se muestra ese y el mapa se arma recién al tocar "Cambiar"
// (un mapa armado oculto queda sin tamaño).
function selectorPunto() {
    const cont = document.getElementById('selector-punto');
    if (!cont) return;
    if (cont.hidden) {
        const btn = document.getElementById('btn-cambiar-punto');
        if (btn) btn.addEventListener('click', () => {
            document.getElementById('punto-guardado').hidden = true;
            cont.hidden = false;
            armarSelectorPunto(cont);
        }, { once: true });
        return;
    }
    armarSelectorPunto(cont);
}

function armarSelectorPunto(cont) {
    const puntos = JSON.parse(cont.dataset.puntos || '[]');
    const input = document.getElementById('punto-id');
    const lista = document.getElementById('puntos-lista');
    const elegido = document.getElementById('punto-elegido');
    const buscar = document.getElementById('buscar-dir');
    const msg = document.getElementById('buscar-msg');
    if (!puntos.length) return;

    const mapa = crearMapa('mapa-puntos');
    const marcadores = {};
    let yoMarcador = null;

    puntos.forEach(p => {
        marcadores[p.id] = mapa.marcador(p.lat, p.lng, {
            html: `<b>${esc(p.nombre)}</b><br>${esc(p.direccion)}, ${esc(p.localidad)}${p.horario ? '<br><small>' + esc(p.horario) + '</small>' : ''}<br><button type="button" class="btn btn-primario btn-chico" style="margin-top:8px" onclick="elegirPunto(${p.id})">Retirar acá</button>`,
            onClick: () => resaltar(p.id),
        });
    });
    mapa.encuadrar(puntos.map(p => [p.lat, p.lng]), 14);

    function render(desde) {
        const orden = puntos.map(p => ({ ...p, km: desde ? distanciaKm(desde, p) : null }))
            .sort((a, b) => desde ? a.km - b.km : a.localidad.localeCompare(b.localidad));
        lista.innerHTML = orden.slice(0, desde ? 6 : 50).map(p => `
            <label class="punto ${+input.value === p.id ? 'activo' : ''}" data-id="${p.id}">
                <input type="radio" name="punto_radio" value="${p.id}" ${+input.value === p.id ? 'checked' : ''}>
                <div>
                    <strong>${esc(p.nombre)}</strong>
                    <span>${esc(p.direccion)}, ${esc(p.localidad)}</span>
                    ${p.horario ? `<span class="muted">${esc(p.horario)}</span>` : ''}
                </div>
                ${p.km !== null ? `<em>${fmtKm(p.km)}</em>` : ''}
            </label>`).join('');
        lista.querySelectorAll('input').forEach(r => r.addEventListener('change', () => elegirPunto(+r.value)));
        lista.hidden = false;
        if (desde) {
            const cerca = orden.slice(0, 3).map(p => [p.lat, p.lng]);
            mapa.encuadrar([[desde.lat, desde.lng], ...cerca], 16);
        }
    }

    function resaltar(id) {
        Object.entries(marcadores).forEach(([k, m]) => m.clase(+k === id ? 'activo' : ''));
        lista.querySelectorAll('.punto').forEach(el => el.classList.toggle('activo', +el.dataset.id === id));
    }

    window.elegirPunto = function (id) {
        const p = puntos.find(x => x.id === id);
        if (!p) return;
        input.value = id;
        resaltar(id);
        const r = lista.querySelector(`input[value="${id}"]`);
        if (r) r.checked = true;
        elegido.innerHTML = `<b>Retirás en:</b> ${esc(p.nombre)} — ${esc(p.direccion)}, ${esc(p.localidad)}${p.horario ? ' · ' + esc(p.horario) : ''}`;
        elegido.hidden = false;
        mapa.cerrarPopups();
        mapa.centrar(p.lat, p.lng);
    };

    // guardar: la dirección escrita se recuerda para la próxima compra (la ubicación del GPS no)
    function ubicarme(pos, etiqueta, guardar = true) {
        document.getElementById('dir-texto').value = guardar ? etiqueta || '' : '';
        document.getElementById('dir-lat').value = guardar ? pos.lat : '';
        document.getElementById('dir-lng').value = guardar ? pos.lng : '';
        if (yoMarcador) yoMarcador.quitar();
        yoMarcador = mapa.marcador(pos.lat, pos.lng, { clase: 'yo', titulo: etiqueta || 'Tu dirección' });
        render(pos);
        const masCerca = puntos.map(p => distanciaKm(pos, p)).sort((a, b) => a - b)[0];
        msg.textContent = `El punto más cercano está a ${fmtKm(masCerca)}.`;
    }

    async function buscarDireccion() {
        const q = buscar.value.trim();
        if (q.length < 4) { msg.textContent = 'Escribí una dirección, por ejemplo: Av. Colón 1200, Córdoba.'; return; }
        msg.textContent = 'Buscando…';
        try {
            const r = await geocodificar(q);
            if (!r) { msg.textContent = 'No encontramos esa dirección. Probá agregando la ciudad.'; return; }
            ubicarme(r, q);
        } catch (e) { msg.textContent = e.message; }
    }

    document.getElementById('btn-buscar-dir').addEventListener('click', buscarDireccion);
    buscar.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); buscarDireccion(); } });
    document.getElementById('btn-mi-ubicacion').addEventListener('click', () => {
        if (!navigator.geolocation) { msg.textContent = 'Tu navegador no permite usar la ubicación.'; return; }
        msg.textContent = 'Buscando tu ubicación…';
        navigator.geolocation.getCurrentPosition(
            p => ubicarme({ lat: p.coords.latitude, lng: p.coords.longitude }, 'Estás acá', false),
            () => { msg.textContent = 'No pudimos acceder a tu ubicación. Escribí una dirección.'; },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });

    render(null);
    if (input.value) elegirPunto(+input.value);
    // Su dirección de la compra anterior ya ubicada: ordena los kioscos por cercanía sin volver a buscarla
    const ref = cont.dataset.ref ? JSON.parse(cont.dataset.ref) : null;
    if (ref) ubicarme(ref, ref.nombre);
    else if (buscar.value.trim().length >= 4) buscarDireccion();
    if (cont.dataset.places === '1' && usaGoogle()) autocompletarDireccion(buscar, (pos, nombre) => ubicarme(pos, nombre));
}

// Sugerencias de direcciones de Google mientras se escribe (Places API (New), se enciende con GOOGLE_PLACES en
// config.php). Reemplaza al buscador; si Google no lo carga, queda el buscador de siempre.
async function autocompletarDireccion(input, alElegir) {
    try {
        const { PlaceAutocompleteElement } = await google.maps.importLibrary('places');
        const pac = new PlaceAutocompleteElement({ includedRegionCodes: ['ar'] });
        pac.id = 'buscar-dir-google';
        pac.setAttribute('placeholder', input.placeholder);
        const usar = async (place) => {
            await place.fetchFields({ fields: ['location', 'formattedAddress'] });
            if (place.location) alElegir({ lat: place.location.lat(), lng: place.location.lng() }, place.formattedAddress);
        };
        pac.addEventListener('gmp-select', ev => usar(ev.placePrediction.toPlace()));
        pac.addEventListener('gmp-placeselect', ev => usar(ev.place));   // nombre del evento en versiones anteriores
        const caja = input.closest('.buscador-dir');
        caja.hidden = true;
        caja.insertAdjacentElement('afterend', pac);
    } catch (e) {
        console.warn('[mapa] sin autocompletado de Google:', e);
    }
}

// ─── Admin: ubicar un punto en el mapa ─────────────────────────────────────
function editorPunto() {
    const el = document.getElementById('mapa-editor');
    if (!el) return;
    const lat = document.getElementById('p-lat');
    const lng = document.getElementById('p-lng');
    const msg = document.getElementById('ubicar-msg');
    const tiene = lat.value && lng.value;
    const mapa = crearMapa(el, tiene ? [+lat.value, +lng.value] : CENTRO_AR, tiene ? 16 : 12, { rueda: true });
    let pin = null;

    function poner(la, ln) {
        lat.value = (+la).toFixed(7);
        lng.value = (+ln).toFixed(7);
        if (!pin) pin = mapa.marcador(la, ln, { clase: 'activo', arrastrable: true, onMover: poner });
        else pin.mover(la, ln);
        msg.textContent = 'Ubicado. Si no quedó justo, arrastrá el pin o tocá el lugar exacto en el mapa.';
    }
    if (tiene) poner(lat.value, lng.value);
    mapa.alTocar(poner);

    document.getElementById('btn-ubicar').addEventListener('click', async () => {
        const f = id => document.querySelector(`[name="${id}"]`).value.trim();
        const q = [f('direccion'), f('localidad'), f('provincia')].filter(Boolean).join(', ');
        if (!f('direccion') || !f('localidad')) { msg.textContent = 'Completá dirección y localidad.'; return; }
        msg.textContent = 'Buscando…';
        try {
            // Primero Google desde el servidor (solo acepta resultados en esa localidad); sin clave, OpenStreetMap
            const params = new URLSearchParams({ direccion: f('direccion'), localidad: f('localidad'), provincia: f('provincia') });
            const g = await fetch('geocodificar.php?' + params, { headers: { 'Accept': 'application/json' } });
            let r;
            if (g.status === 501) {
                r = await geocodificar(q);
            } else {
                const d = await g.json();
                if (!d.ok) throw new Error(d.error || 'No pudimos buscar la dirección.');
                r = d.resultado;
            }
            if (!r) { msg.textContent = 'No encontramos la dirección en ' + f('localidad') + ': tocá el lugar en el mapa para ubicarlo a mano.'; return; }
            poner(r.lat, r.lng);
            mapa.centrar(r.lat, r.lng, 17);
            if (r.direccion) msg.textContent = 'Ubicado en ' + r.direccion + '. Si no quedó justo, arrastrá el pin o tocá el lugar exacto.';
        } catch (e) { msg.textContent = e.message; }
    });
}

// Google carga después de este archivo y avisa con mapaGoogleListo; Leaflet ya está cargado antes.
let mapasIniciados = false;
function iniciarMapas() {
    if (mapasIniciados) return;
    mapasIniciados = true;
    try {
        selectorPunto();
        editorPunto();
        // Mapas propios de una página (ej. el listado del admin) que esperan a que cargue Google o Leaflet
        (window.mapasPendientes || []).forEach(f => f());
    } catch (e) {
        // Con Google, cualquier falla vuelve a OpenStreetMap en lugar de dejar la página sin mapa
        if (usaGoogle()) { console.error(e); window.gm_authFailure(); } else throw e;
    }
}
window.mapaGoogleListo = iniciarMapas;
// Google rechazó la clave (dominio no autorizado, API apagada…): se recarga con OpenStreetMap
// para que nadie se quede sin poder elegir el punto. La cookie dura un día y evita el bucle.
window.gm_authFailure = function () {
    document.cookie = 'mapa_osm=1; max-age=86400; path=/; SameSite=Lax';
    location.reload();
};
if (window.L) iniciarMapas();
