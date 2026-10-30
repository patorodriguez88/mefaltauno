#!/bin/zsh
# Doble clic (Mac): manda a www.mefaltauno.com.ar el catálogo, stock, precios y fotos de Brihet (ver tools/brihet-enviar.php).
# Esta ventana en blanco con letra oscura (solo esta, no cambia el perfil de la Terminal)
osascript -e 'tell application "Terminal" to tell selected tab of front window
    set background color to {65535, 65535, 65535}
    set normal text color to {8000, 8000, 8000}
    set bold text color to {0, 0, 0}
    set cursor color to {20000, 20000, 20000}
end tell' >/dev/null 2>&1
cd "${0:A:h}/.."   # :A sigue el acceso directo del Escritorio hasta el original
PHP=$(command -v php || echo /usr/local/bin/php)
"$PHP" tools/brihet-enviar.php
echo
read -s -k '?Apretá una tecla para cerrar.'
