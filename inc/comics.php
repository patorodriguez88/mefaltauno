<?php
// Personajes de historieta que aparecen desde un costado con un globo de diálogo y cuentan una mini historia.
// comic_cameo('soldado', [titulo, texto], 'izq', '#selector') → aparece cuando la sección del selector entra en
// pantalla. Se cierran con la ✕ y no vuelven en esa visita (sessionStorage). Sin animación si el usuario la desactivó.

const COMIC_HISTORIAS = [
    'soldado' => [
        ['¡Sargento!', 'Tenemos un hombre caído en el estante. Hay que ir a buscarlo… <b>Ningún soldado queda atrás.</b>'],
        ['¡Reporte de la tropa!', 'Falta uno en la formación. Ya salió la patrulla: <b>nadie vuelve a casa sin él.</b>'],
        ['¡Firmes!', 'Contamos la tropa y no da. <b>Misión de rescate en marcha.</b>'],
    ],
    'mecanico' => [
        ['Jefe, tenemos un problema…', 'Falta un auto en el garage. <b>Trabajo para el equipo:</b> hay que rastrearlo y traerlo de vuelta.'],
        ['¡Atención, taller!', 'Hay un box vacío en la colección. <b>Ese auto vuelve a su lugar.</b>'],
        ['¿Escuchaste ese motor?', 'Es el que te falta, que viene en camino. <b>Nosotros lo traemos.</b>'],
    ],
    'bibliotecaria' => [
        ['¡Silencio… y atención!', 'Falta un tomo en el estante. <b>Nadie descansa</b> hasta que vuelva a su lugar.'],
        ['Querido lector:', 'Toda historia necesita su capítulo perdido. <b>Nosotros lo encontramos por vos.</b>'],
        ['Ficha de préstamo:', 'Un volumen sin devolver desde hace años… <b>Ya salimos a buscarlo.</b>'],
    ],
    'capitan' => [
        ['¡Hombre al agua!', 'Perdimos una pieza en alta mar. <b>Rumbo de rescate, a toda máquina.</b>'],
        ['Bitácora del capitán:', 'El barco no zarpa incompleto. <b>Vamos a buscar la pieza que falta.</b>'],
    ],
    'mago' => [
        ['¡Por mis barbas!', 'Un hechizo hizo desaparecer un número de tu colección. <b>Lo vamos a hacer volver.</b>'],
        ['Profecía antigua:', 'Toda colección se completa… <b>con un poco de magia y nuestra ayuda.</b>'],
    ],
    'astronauta' => [
        ['Base, ¿me copian?', 'Detectamos un número perdido en el hiperespacio. <b>Iniciando misión de rescate.</b>'],
        ['Cuenta regresiva…', '3, 2, 1… ¡despegamos a buscar <b>el que te falta</b>!'],
    ],
    'detective' => [
        ['Elemental…', 'Un número falta en la escena. <b>Ya tengo una pista</b> y no pienso soltarla.'],
        ['Caso abierto:', 'Desaparecido: un número de tu colección. <b>Lo vamos a encontrar.</b>'],
    ],
    'exploradora' => [
        ['¡Mirá esto!', 'Un ejemplar perdido en la selva de las colecciones. <b>Vamos a rastrearlo.</b>'],
        ['Diario de expedición:', 'Al catálogo le falta una pieza. <b>Ninguna se nos escapa.</b>'],
    ],
    'superheroe' => [
        ['¡Alerta en la ciudad!', 'Un número fue secuestrado de tu colección. <b>¡Al rescate!</b>'],
        ['¡No temas, ciudadano!', 'Ningún héroe queda atrás… <b>y ningún número tampoco.</b>'],
    ],
    'dibujante' => [
        ['¡Sugoi!', 'A tu colección le falta un capítulo. <b>Ya estamos dibujando el plan.</b>'],
        ['Próximo episodio:', 'El número perdido vuelve a casa. <b>No te lo pierdas.</b>'],
    ],
    'rockero' => [
        ['¡Uno, dos, tres, cuatro!', 'A la banda le falta un integrante. <b>Sin él no hay show.</b>'],
        ['¡Bis! ¡Bis!', 'Falta un tema en tu colección. <b>Nosotros lo conseguimos.</b>'],
    ],
    'hada' => [
        ['Había una vez…', 'Una colección a la que le faltaba un número. <b>Y lo encontramos.</b> Fin.'],
        ['¡Bibidi, babidi…!', 'Pedí un deseo: <b>el número que te falta.</b> Nosotros hacemos el resto.'],
    ],
];

// Qué personaje va con cada colección (palabras del nombre); si no hay coincidencia, según la categoría
const COMIC_COLECCIONES = [
    'soldado'      => 'tanque|guerra|aviones de la ii|osprey|militar|soldad',
    'capitan'      => 'titanic|perlita|barco|buque|velero|naval',
    'mago'         => 'hogwarts|mago|magia|fant[aá]stic|mitolog|mitos',
    'astronauta'   => 'star wars|espacio|galax|astron|ciencia|cohete',
    'detective'    => 'sherlock|detective|misterio|crimen|ingenio|enigma',
    'exploradora'  => 'mineral|piedras|bichos|zoo|animal|pa[ií]ses|cuerpo humano|tiranosaurio|dinosaur|planeta',
    'superheroe'   => 'marvel|asterix|h[eé]roe|superzings|comic',
    'dibujante'    => 'manga|manganime|anime|videojuego',
    'rockero'      => 'rock|metal|m[uú]sica|pop',
    'hada'         => 'cuento|disney|barbie|moda|crochet|princesa|extraordinarias',
    'mecanico'     => 'auto|rally|racing|audi|falcon|mustang|combi|dodge|r[aá]pido|motor|camion|moto',
    'bibliotecaria'=> 'novela|gredos|autores|lorca|psicolog|historia|verne|biblioteca|literatura',
];

function comic_personaje_coleccion(string $nombre, ?string $cat_slug): string {
    $n = mb_strtolower($nombre);
    foreach (COMIC_COLECCIONES as $p => $re) if (preg_match('/' . $re . '/u', $n)) return $p;
    return match ($cat_slug) { 'modelismo' => 'mecanico', 'libros' => 'bibliotecaria', default => 'exploradora' };
}

// Para la home: n personajes distintos al azar (así no aparece siempre el mismo primero)
function comic_al_azar(int $n): array {
    $todos = array_keys(COMIC_HISTORIAS);
    shuffle($todos);
    return array_slice($todos, 0, $n);
}

function comic_cameo(string $personaje, string $lado, string $selector, int $historia = -1): string {
    $hist = COMIC_HISTORIAS[$personaje] ?? COMIC_HISTORIAS['soldado'];
    [$titulo, $texto] = $hist[$historia >= 0 ? $historia % count($hist) : array_rand($hist)];
    $id = 'comic-' . $personaje;
    return '<aside class="comic comic-' . ($lado === 'der' ? 'der' : 'izq') . '" id="' . $id . '" data-aparece="' . e($selector) . '" aria-label="Historieta" hidden>'
        . '<div class="comic-panel">'
        . '<div class="comic-globo"><button class="comic-cerrar" type="button" aria-label="Cerrar">×</button><strong>' . e($titulo) . '</strong><span>' . $texto . '</span></div>'
        . '<div class="comic-personaje">' . comic_svg($personaje) . '</div>'
        . '</div></aside>';
}

// Ilustraciones (vectoriales, estilo historieta: colores planos y contorno grueso)
// Figura base (piernas, cuerpo, brazos, cabeza) con colores propios; $atras va detrás del cuerpo y $adelante encima
function comic_figura(array $c, string $atras = '', string $adelante = '', string $cara = ''): string {
    $o = 'stroke="#1b1b1b" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"';
    $piel = $c['piel'] ?? '#f2c9a0';
    return '<svg viewBox="0 0 120 200" role="img" aria-label="' . e($c['nombre']) . '">
  <ellipse cx="60" cy="192" rx="38" ry="6" fill="#00000022"/>' . $atras . '
  <rect x="42" y="140" width="15" height="46" rx="4" fill="' . $c['piernas'] . '" ' . $o . '/>
  <rect x="63" y="140" width="15" height="46" rx="4" fill="' . $c['piernas'] . '" ' . $o . '/>
  <path d="M38 186h20v8H35zM62 186h20l3 8H62z" fill="' . ($c['zapatos'] ?? '#2b2b2b') . '" ' . $o . '/>
  <path d="M38 92h44l6 52H32z" fill="' . $c['cuerpo'] . '" ' . $o . '/>
  <path d="M38 96 24 132l9 5 12-26M82 96l14 36-9 5-12-26" fill="' . ($c['mangas'] ?? $c['cuerpo']) . '" ' . $o . '/>
  <circle cx="28" cy="136" r="6.5" fill="' . $piel . '" ' . $o . '/><circle cx="92" cy="136" r="6.5" fill="' . $piel . '" ' . $o . '/>
  <rect x="51" y="80" width="18" height="14" fill="' . $piel . '" ' . $o . '/>
  <circle cx="60" cy="60" r="23" fill="' . $piel . '" ' . $o . '/>
  ' . ($cara ?: '<circle cx="52" cy="62" r="2.6" fill="#1b1b1b"/><circle cx="68" cy="62" r="2.6" fill="#1b1b1b"/><path d="M52 72q8 6 16 0" fill="none" ' . $o . '/>') . '
  ' . $adelante . '
</svg>';
}

function comic_svg(string $p): string {
    $o = 'stroke="#1b1b1b" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"';
    switch ($p) {
        case 'capitan':
            return comic_figura(['nombre' => 'Capitán de barco', 'piernas' => '#f4f4f4', 'cuerpo' => '#1f3c6e', 'zapatos' => '#111'],
                '', '<path d="M36 46h48v-6c0-10-48-10-48 0z" fill="#f4f4f4" ' . $o . '/><rect x="34" y="44" width="52" height="7" rx="2" fill="#111" ' . $o . '/>
  <circle cx="60" cy="38" r="4" fill="#f2c94c" stroke="#1b1b1b" stroke-width="2"/>
  <path d="M44 72q16 16 32 0q-4 12-16 12t-16-12z" fill="#e9e9e9" ' . $o . '/>
  <path d="M48 100h24M48 110h24" stroke="#f2c94c" stroke-width="3"/>
  <circle cx="92" cy="128" r="12" fill="none" stroke="#8a5a2b" stroke-width="5"/><path d="M92 112v32M76 128h32" stroke="#8a5a2b" stroke-width="3"/>');
        case 'mago':
            return comic_figura(['nombre' => 'Mago', 'piernas' => '#3b2a5a', 'cuerpo' => '#4b2a7b', 'zapatos' => '#2b1b3b'],
                '', '<path d="M34 44 60 2l26 42z" fill="#4b2a7b" ' . $o . '/><path d="M30 44h60" stroke="#1b1b1b" stroke-width="6" stroke-linecap="round"/>
  <path d="M52 18l3 6 6 1-5 4 1 6-5-3-5 3 1-6-5-4 6-1z" fill="#f2c94c" stroke="#1b1b1b" stroke-width="1.5"/>
  <path d="M42 70q18 40 36 0q-2 26-18 30t-18-30z" fill="#f4f4f4" ' . $o . '/>
  <path d="M96 136l14-40" stroke="#8a5a2b" stroke-width="5"/><path d="M110 92l4-6 2 7 7 1-6 4" fill="#f2c94c" stroke="#1b1b1b" stroke-width="1.5"/>');
        case 'astronauta':
            return comic_figura(['nombre' => 'Astronauta', 'piernas' => '#e8e8e8', 'cuerpo' => '#f4f4f4', 'zapatos' => '#9aa4aa'],
                '<rect x="34" y="94" width="52" height="40" rx="6" fill="#b8c1c6" ' . $o . '/>',
                '<circle cx="60" cy="60" r="28" fill="#cfe8ff77" ' . $o . '/><path d="M44 50q6-8 14-8" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
  <rect x="48" y="102" width="24" height="14" rx="2" fill="#e8462f" ' . $o . '/><circle cx="54" cy="109" r="2" fill="#fff"/><circle cx="62" cy="109" r="2" fill="#f2c94c"/>
  <path d="M56 6l4-6 4 6v10h-8z" fill="#c62828" ' . $o . '/>');
        case 'detective':
            return comic_figura(['nombre' => 'Detective con lupa', 'piernas' => '#5a4636', 'cuerpo' => '#8d6e4c', 'zapatos' => '#2b1b10'],
                '', '<path d="M34 48c2-22 50-22 52 0z" fill="#8d6e4c" ' . $o . '/><path d="M30 48h60" stroke="#1b1b1b" stroke-width="5" stroke-linecap="round"/>
  <path d="M44 34q16-10 32 0" fill="none" stroke="#6b5338" stroke-width="3"/>
  <path d="M38 94l22 20 22-20" fill="none" ' . $o . '/><path d="M40 112h40" stroke="#6b5338" stroke-width="3"/>
  <circle cx="104" cy="118" r="11" fill="#cfe8ff88" ' . $o . '/><path d="M96 126l-6 8" stroke="#8a5a2b" stroke-width="5"/>
  <path d="M70 76q8 0 12 6" fill="none" stroke="#5a3a1a" stroke-width="3"/>');
        case 'exploradora':
            return comic_figura(['nombre' => 'Exploradora con lupa y sombrero', 'piernas' => '#8a7a52', 'cuerpo' => '#c9b27a', 'zapatos' => '#5a3a1a', 'piel' => '#e3b088'],
                '<path d="M38 66c-6 20-6 34 2 40M82 66c6 20 6 34-2 40" fill="none" stroke="#7a3f1f" stroke-width="8" stroke-linecap="round"/>',
                '<path d="M30 46q30-16 60 0q-30 8-60 0z" fill="#d8c08a" ' . $o . '/><path d="M42 44c0-20 36-20 36 0" fill="#d8c08a" ' . $o . '/><path d="M42 40h36" stroke="#7a3f1f" stroke-width="3"/>
  <path d="M48 94v24M72 94v24" stroke="#6b5a3a" stroke-width="3"/>
  <circle cx="18" cy="124" r="10" fill="#cfe8ff88" ' . $o . '/><path d="M24 132l4 6" stroke="#8a5a2b" stroke-width="5"/>');
        case 'superheroe':
            return comic_figura(['nombre' => 'Superhéroe con capa', 'piernas' => '#1f4fa8', 'cuerpo' => '#1f6fd1', 'zapatos' => '#c62828', 'mangas' => '#1f6fd1'],
                '<path d="M38 92 22 176h76L82 92z" fill="#c62828" ' . $o . '/>',
                '<path d="M46 108l14-10 14 10-14 18z" fill="#f2c94c" ' . $o . '/><rect x="36" y="132" width="48" height="7" fill="#f2c94c" ' . $o . '/>
  <path d="M40 56h40v8c-8 4-32 4-40 0z" fill="#c62828" ' . $o . '/>
  <path d="M38 42c4-20 40-20 44 0" fill="#3b2a1e" ' . $o . '/>', '<circle cx="52" cy="60" r="2.6" fill="#fff"/><circle cx="68" cy="60" r="2.6" fill="#fff"/><path d="M52 72q8 6 16 0" fill="none" ' . $o . '/>');
        case 'dibujante':
            return comic_figura(['nombre' => 'Dibujante de manga', 'piernas' => '#2b2b2b', 'cuerpo' => '#e8462f', 'zapatos' => '#f4f4f4'],
                '', '<path d="M36 56c-6-30 50-36 48-2l-6-10-6 8-6-10-6 10-6-8-6 10z" fill="#1b1b1b" ' . $o . '/>
  <rect x="84" y="104" width="26" height="32" rx="2" fill="#f8f3e3" ' . $o . '/><path d="M88 112h18M88 118h14M88 124h16" stroke="#9a8f74" stroke-width="2"/>
  <path d="M28 136l-6-20" stroke="#f2c94c" stroke-width="5"/><path d="M22 116l-2-6" stroke="#1b1b1b" stroke-width="3"/>',
                '<path d="M46 60q6-6 12 0M62 60q6-6 12 0" fill="none" ' . $o . '/><path d="M54 72q6 6 12 0" fill="none" ' . $o . '/>');
        case 'rockero':
            return comic_figura(['nombre' => 'Rockero con guitarra', 'piernas' => '#1b1b1b', 'cuerpo' => '#2b2b2b', 'zapatos' => '#1b1b1b'],
                '', '<path d="M38 50c-10 30-8 40-6 46M82 50c10 30 8 40 6 46" fill="none" stroke="#6b3b1f" stroke-width="10" stroke-linecap="round"/>
  <path d="M38 52c0-26 44-26 44 0" fill="#6b3b1f" ' . $o . '/>
  <path d="M40 104l26 26q8 8 0 14t-14-2l-22-24z" fill="#e8462f" ' . $o . '/><path d="M60 128l40-56" stroke="#8a5a2b" stroke-width="5"/><path d="M98 66l8-6 2 6-6 6" fill="#1b1b1b"/>
  <circle cx="54" cy="124" r="4" fill="#1b1b1b"/>',
                '<path d="M46 60h12M62 60h12" stroke="#1b1b1b" stroke-width="4" stroke-linecap="round"/><path d="M52 72q8 8 16 0" fill="#c62828" ' . $o . '/>');
        case 'hada':
            return comic_figura(['nombre' => 'Hada con varita', 'piernas' => '#f2c9a0', 'cuerpo' => '#e985b5', 'zapatos' => '#e985b5'],
                '<path d="M40 100c-30-30-36 10-10 14M80 100c30-30 36 10 10 14" fill="#cfe8ffaa" ' . $o . '/>',
                '<path d="M36 58c-4-30 52-32 48 0-4-8-8-12-12-12-6 6-18 6-24 0-4 0-8 4-12 12z" fill="#f2c94c" ' . $o . '/>
  <path d="M46 36l4-10 6 8 4-10 4 10 6-8 4 10z" fill="#f2c94c" ' . $o . '/>
  <path d="M96 136l12-34" stroke="#8a5a2b" stroke-width="3"/><path d="M108 94l3 6 6 1-5 4 1 6-5-3-5 3 1-6-5-4 6-1z" fill="#f2c94c" stroke="#1b1b1b" stroke-width="1.5"/>');
        case 'mecanico':
            return '<svg viewBox="0 0 120 200" role="img" aria-label="Mecánico con una llave inglesa">
  <ellipse cx="60" cy="192" rx="40" ry="6" fill="#00000022"/>
  <rect x="38" y="140" width="18" height="46" rx="4" fill="#2f5d8a" ' . $o . '/>
  <rect x="64" y="140" width="18" height="46" rx="4" fill="#2f5d8a" ' . $o . '/>
  <path d="M34 186h24v8H30zM62 186h24l4 8H62z" fill="#3b2a1e" ' . $o . '/>
  <path d="M34 92h52l6 54H28z" fill="#3a6ea5" ' . $o . '/>
  <path d="M44 92v26h32V92" fill="none" ' . $o . '/>
  <rect x="50" y="122" width="20" height="12" rx="2" fill="#e8462f" ' . $o . '/>
  <path d="M34 96 16 132l10 6 16-28" fill="#3a6ea5" ' . $o . '/>
  <path d="M86 96l20 30-9 6-17-24" fill="#3a6ea5" ' . $o . '/>
  <circle cx="21" cy="138" r="7" fill="#f2c9a0" ' . $o . '/>
  <circle cx="102" cy="132" r="7" fill="#f2c9a0" ' . $o . '/>
  <path d="M104 126l10-22m-6-4 10 4-2 6-8-2" fill="#b8c1c6" ' . $o . '/>
  <rect x="50" y="80" width="20" height="14" fill="#f2c9a0" ' . $o . '/>
  <circle cx="60" cy="60" r="24" fill="#f2c9a0" ' . $o . '/>
  <path d="M36 54c0-18 48-18 48 0z" fill="#006368" ' . $o . '/>
  <path d="M80 54h16" fill="none" stroke="#006368" stroke-width="7" stroke-linecap="round"/>
  <circle cx="52" cy="62" r="2.6" fill="#1b1b1b"/><circle cx="68" cy="62" r="2.6" fill="#1b1b1b"/>
  <path d="M52 72q8 6 16 0" fill="none" ' . $o . '/>
  <path d="M44 66q-2 6 2 8" fill="none" stroke="#1b1b1b" stroke-width="2"/>
  <path d="M40 104c6 2 8 8 4 10" fill="none" stroke="#1b1b1b" stroke-width="2"/>
</svg>';
        case 'bibliotecaria':
            return '<svg viewBox="0 0 120 200" role="img" aria-label="Bibliotecaria con un libro">
  <ellipse cx="60" cy="192" rx="38" ry="6" fill="#00000022"/>
  <path d="M40 150h40l8 40H32z" fill="#7b3f5e" ' . $o . '/>
  <rect x="46" y="186" width="10" height="8" fill="#3b2a1e" ' . $o . '/><rect x="64" y="186" width="10" height="8" fill="#3b2a1e" ' . $o . '/>
  <path d="M36 94h48l4 58H32z" fill="#2e8b57" ' . $o . '/>
  <path d="M48 94l12 14 12-14" fill="#f4efe1" ' . $o . '/>
  <path d="M36 100 24 136l9 4 12-26M84 100l12 36-9 4-12-26" fill="#2e8b57" ' . $o . '/>
  <path d="M30 126l30 8 30-8v26l-30 8-30-8z" fill="#f8f3e3" ' . $o . '/>
  <path d="M60 134v26" ' . $o . '/>
  <path d="M38 134h14M38 140h14M68 134h14M68 140h12" stroke="#9a8f74" stroke-width="2"/>
  <rect x="51" y="80" width="18" height="16" fill="#e9b98f" ' . $o . '/>
  <circle cx="60" cy="58" r="24" fill="#e9b98f" ' . $o . '/>
  <path d="M35 60c-4-30 54-34 50 0-6-14-38-16-50 0z" fill="#6b3b1f" ' . $o . '/>
  <circle cx="60" cy="26" r="11" fill="#6b3b1f" ' . $o . '/>
  <circle cx="51" cy="62" r="7" fill="#ffffff66" ' . $o . '/><circle cx="69" cy="62" r="7" fill="#ffffff66" ' . $o . '/>
  <path d="M58 62h4" ' . $o . '/>
  <circle cx="51" cy="62" r="2.2" fill="#1b1b1b"/><circle cx="69" cy="62" r="2.2" fill="#1b1b1b"/>
  <path d="M53 74q7 5 14 0" fill="none" ' . $o . '/>
</svg>';
        default:   // soldadito de plomo, pintado a mano, con su base
            return '<svg viewBox="0 0 120 200" role="img" aria-label="Soldadito de plomo">
  <ellipse cx="60" cy="190" rx="36" ry="8" fill="#3f6b2f" ' . $o . '/>
  <rect x="24" y="180" width="72" height="10" rx="3" fill="#4f8a3a" ' . $o . '/>
  <rect x="42" y="132" width="15" height="48" fill="#1f3c6e" ' . $o . '/>
  <rect x="63" y="132" width="15" height="48" fill="#1f3c6e" ' . $o . '/>
  <path d="M49 134v44M70 134v44" stroke="#d32f2f" stroke-width="3"/>
  <path d="M40 174h19v6H38zM61 174h19l2 6H61z" fill="#111" ' . $o . '/>
  <path d="M38 84h44l4 52H34z" fill="#d32f2f" ' . $o . '/>
  <path d="M40 86l40 46M80 86l-40 46" stroke="#f5f5f5" stroke-width="6"/>
  <path d="M40 86l40 46M80 86l-40 46" fill="none" stroke="#1b1b1b" stroke-width="1"/>
  <circle cx="60" cy="110" r="3" fill="#f2c94c" stroke="#1b1b1b" stroke-width="1.5"/>
  <rect x="34" y="128" width="52" height="7" fill="#f5f5f5" ' . $o . '/>
  <path d="M38 88 28 128l9 3 9-30" fill="#d32f2f" ' . $o . '/>
  <path d="M82 88l10 30-8 4-8-20" fill="#d32f2f" ' . $o . '/>
  <path d="M90 22l6-4 4 120-8 2z" fill="#8a5a2b" ' . $o . '/>
  <path d="M93 6l2 16h-4z" fill="#9aa4aa" ' . $o . '/>
  <circle cx="88" cy="122" r="6" fill="#f2c9a0" ' . $o . '/>
  <circle cx="31" cy="131" r="6" fill="#f2c9a0" ' . $o . '/>
  <circle cx="60" cy="66" r="18" fill="#f2c9a0" ' . $o . '/>
  <path d="M40 66c-4-46 44-46 40 0z" fill="#161616" ' . $o . '/>
  <path d="M42 66q18 6 36 0" fill="none" stroke="#f2c94c" stroke-width="3"/>
  <circle cx="54" cy="70" r="2.4" fill="#1b1b1b"/><circle cx="66" cy="70" r="2.4" fill="#1b1b1b"/>
  <path d="M51 77q9-5 18 0" fill="#5a3a1a" stroke="#1b1b1b" stroke-width="2"/>
  <circle cx="49" cy="74" r="3" fill="#f28b82" opacity=".7"/><circle cx="71" cy="74" r="3" fill="#f28b82" opacity=".7"/>
</svg>';
    }
}
