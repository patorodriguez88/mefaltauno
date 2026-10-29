#!/bin/zsh
# Doble clic (Mac): manda a www.mefaltauno.com.ar el catálogo, stock, precios y fotos de Brihet (ver tools/brihet-enviar.php).
cd "${0:A:h}/.."   # :A sigue el acceso directo del Escritorio hasta el original
PHP=$(command -v php || echo /usr/local/bin/php)
"$PHP" tools/brihet-enviar.php
echo
read -s -k '?Apretá una tecla para cerrar.'
