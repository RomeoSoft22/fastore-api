#!/usr/bin/env bash
# Genera el paquete de despliegue en cPanel de la tienda FAStore:
#
#   https://shop.juarezcampos.net               fastore-web (Angular + seo.php)  → <raíz de shop>/
#   https://shop.juarezcampos.net/fastore-api   entrada pública de la API        → <raíz de shop>/fastore-api/
#                                               código Laravel + .env (privado)  → /home/juarezc/fastore-api-app/
#
# Uso (Git Bash, desde la carpeta que contiene fastore-api y fastore-web):
#   ORIGEN_SQL="C:/ruta/juarezc_sistema_admin.sql" bash fastore-api/despliegue/empaquetar-cpanel.sh   # primer despliegue
#   ACTUALIZACION=1 bash fastore-api/despliegue/empaquetar-cpanel.sh                                  # solo código
#
# ORIGEN_SQL es un respaldo RECIENTE de la base de producción (phpMyAdmin → Exportar). El script le aplica
# las migraciones en una base temporal y genera el .sql de actualización con esos datos (nunca con los de
# pruebas locales). En una actualización no se toca la base ni el .env ni las fotos del servidor.
#
# El resultado queda en paquetes/cpanel-fastore-<fecha>/ JUNTO a los repos (nunca dentro de uno: lleva secretos).
set -euo pipefail

BASE="$(cd "$(dirname "$0")/../.." && pwd)"
API="$BASE/fastore-api"
WEB="$BASE/fastore-web"
FECHA="$(date +%Y%m%d-%H%M)"
SALIDA="$BASE/paquetes/cpanel-fastore-$FECHA"
C="$SALIDA/contenido"

CPANEL_USUARIO="${CPANEL_USUARIO:-juarezc}"
# Carpeta raíz del dominio shop.juarezcampos.net (cPanel → Dominios). Se crea con este nombre en el .zip.
RAIZ_SHOP="${RAIZ_SHOP:-shop.juarezcampos.net}"
URL_WEB="${URL_WEB:-https://shop.juarezcampos.net}"
URL_API="$URL_WEB/fastore-api"
BD_PRODUCCION="${BD_PRODUCCION:-${CPANEL_USUARIO}_sistema_admin}"
ACTUALIZACION="${ACTUALIZACION:-0}"
ORIGEN_SQL="${ORIGEN_SQL:-}"
export PHPRC="${PHPRC:-$BASE/_local}"
PHP="${PHP:-php}"
COMPOSER_CMD="${COMPOSER_CMD:-$PHP /c/laragon/bin/composer/composer.phar}"
MYSQL_BIN="${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin}"
BD_TEMPORAL="fastore_paquete_tmp"
TAR="${TAR:-/c/Windows/System32/tar.exe}"
export NG_CLI_ANALYTICS=false

paso() { printf '\n\033[36m== %s\033[0m\n' "$*"; }
valor_env() { grep -E "^$1=" "$API/.env.example" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true; }
# tar.exe (bsdtar de Windows) genera .zip con rutas "/", compatibles con el extractor de cPanel.
zip_de() { local zip; zip="$(cygpath -w "$1" 2>/dev/null || echo "$1")"; shift; (cd "$C" && "$TAR" -a -c -f "$zip" "$@"); }

if [ "$ACTUALIZACION" != "1" ] && [ ! -f "$ORIGEN_SQL" ]; then
  echo "Falta ORIGEN_SQL: respaldo reciente de la base ${BD_PRODUCCION} (phpMyAdmin → Exportar)." >&2
  exit 1
fi
mkdir -p "$C/$RAIZ_SHOP/fastore-api" "$SALIDA/sql"

# ------------------------------------------------------------------ 1. Tienda (Angular + seo.php)
paso "1/5 fastore-web → $RAIZ_SHOP/ ($URL_WEB)"
(cd "$WEB" && { [ -d node_modules ] || npm ci --no-audit --no-fund; } \
  && MSYS_NO_PATHCONV=1 node node_modules/@angular/cli/bin/ng.js build --configuration production >/dev/null)
cp -r "$WEB/dist/fastore-web/browser/." "$C/$RAIZ_SHOP/"

# ------------------------------------------------------------------ 2. API: entrada pública
paso "2/5 Entrada pública de la API → $RAIZ_SHOP/fastore-api ($URL_API)"
cp "$API/despliegue/cpanel/index.php" "$C/$RAIZ_SHOP/fastore-api/index.php"
cp "$API/despliegue/cpanel/api-publico.htaccess" "$C/$RAIZ_SHOP/fastore-api/.htaccess"

# ------------------------------------------------------------------ 3. API: código (privado)
paso "3/5 Código de la API → /home/$CPANEL_USUARIO/fastore-api-app"
D="$C/fastore-api-app"
mkdir -p "$D"
(cd "$API" && git ls-files -z --cached --others --exclude-standard \
  | grep -zv -E '^(tests/|public/|despliegue/|\.github/|phpunit\.xml|\.editorconfig|\.gitattributes|README\.md)' \
  | xargs -0 -I{} cp --parents "{}" "$D/")
find "$D/storage" -type f ! -name .gitignore -delete 2>/dev/null || true
find "$D/bootstrap/cache" -type f ! -name .gitignore -delete 2>/dev/null || true
cp "$API/despliegue/cpanel/app.htaccess" "$D/.htaccess"
(cd "$D" && $COMPOSER_CMD install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts >/dev/null)

if [ "$ACTUALIZACION" = "1" ]; then
  # En una actualización se conservan el .env y storage/ del servidor (fotos subidas, logs, caché).
  rm -rf "$D/storage"
else
  paso "    .env de producción y fotos iniciales"
  APP_KEY_NUEVA="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  sed -e "s|^APP_ENV=.*|APP_ENV=production|" \
      -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY_NUEVA|" \
      -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
      -e "s|^APP_URL=.*|APP_URL=$URL_API|" \
      -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
      -e "s|^DB_DATABASE=.*|DB_DATABASE=$BD_PRODUCCION|" \
      -e "s|^DB_USERNAME=.*|DB_USERNAME=${CPANEL_USUARIO}_CAMBIAR|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=CAMBIAR|" \
      -e "s|^TIENDA_URL=.*|TIENDA_URL=$URL_WEB|" \
      -e "s|^TIENDA_API_URL=.*|TIENDA_API_URL=$URL_API|" \
      -e "s|^IDENTIDAD_URL=.*|IDENTIDAD_URL=https://identidad.juarezcampos.net|" \
      -e "s|^IDENTIDAD_API_URL=.*|IDENTIDAD_API_URL=https://identidad.juarezcampos.net/api|" \
      -e "s|^IDENTIDAD_CLIENT_SECRET=.*|IDENTIDAD_CLIENT_SECRET=PEGAR_SECRETO_DEL_PANEL|" \
      -e "s|^IDENTIDAD_REDIRECT_URI=.*|IDENTIDAD_REDIRECT_URI=$URL_WEB/callback|" \
      -e "s|^IDENTIDAD_POST_LOGOUT_URI=.*|IDENTIDAD_POST_LOGOUT_URI=$URL_WEB/|" \
      -e "s|^IDENTIDAD_COOKIE_PATH=.*|IDENTIDAD_COOKIE_PATH=|" \
      "$API/.env.example" > "$D/.env"
  {
    echo ''
    echo '# Datos del titular: aparecen en las políticas, los términos y el Libro de Reclamaciones.'
    echo "TIENDA_TITULAR=\"${TIENDA_TITULAR:-Jean Piere Juarez Campos}\""
    echo "TIENDA_RUC=${TIENDA_RUC:-}"
    echo "TIENDA_DOMICILIO=\"${TIENDA_DOMICILIO:-Lima, Perú}\""
    echo "TIENDA_CORREO=${TIENDA_CORREO:-contacto@juarezcampos.net}"
  } >> "$D/.env"
  # Fotos de los productos y banners iniciales (generadas en local desde las fotos del sitio anterior).
  mkdir -p "$D/storage/app/public"
  cp -r "$API/storage/app/public/productos" "$API/storage/app/public/banners" "$D/storage/app/public/"
  # Las imágenes insertadas en descripciones durante las pruebas locales no se publican.
  find "$D/storage/app/public/productos" -type d -name descripcion -prune -exec rm -rf {} +
  find "$D/storage/app/public" -name '*.jpg' -delete
fi

# ------------------------------------------------------------------ 4. Base de datos
if [ "$ACTUALIZACION" != "1" ]; then
  paso "4/5 Base de datos: respaldo de producción + migraciones (base temporal $BD_TEMPORAL)"
  M=("$MYSQL_BIN/mysql" -uroot --host=127.0.0.1 --default-character-set=utf8mb4)
  "${M[@]}" -e "DROP DATABASE IF EXISTS $BD_TEMPORAL; CREATE DATABASE $BD_TEMPORAL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  "${M[@]}" "$BD_TEMPORAL" < "$ORIGEN_SQL"
  (cd "$API" && DB_DATABASE="$BD_TEMPORAL" "$PHP" artisan migrate --force >/dev/null)
  # Galería y banners: mismas filas que las fotos incluidas en el paquete.
  BD_LOCAL="$(grep -E '^DB_DATABASE=' "$API/.env" | cut -d= -f2)"
  "${M[@]}" -e "INSERT INTO $BD_TEMPORAL.mae_producto_imagen SELECT * FROM $BD_LOCAL.mae_producto_imagen WHERE n_producto IN (SELECT n_producto FROM $BD_TEMPORAL.mae_productos);
                INSERT INTO $BD_TEMPORAL.mae_banner SELECT * FROM $BD_LOCAL.mae_banner;"

  TABLAS=(mae_categoria mae_marca mae_productos mae_producto_imagen mae_banner car_carrito car_carrito_item car_cupon cfg_parametro rcl_reclamo migrations)
  {
    echo "-- FAStore: tablas de la tienda migradas. Importar en $BD_PRODUCCION (phpMyAdmin) DESPUÉS de exportar un respaldo."
    echo "-- Reemplaza mae_categoria, mae_marca y mae_productos con los mismos datos del respaldo de origen más las columnas nuevas;"
    echo "-- no toca mae_usuario, mae_perfil ni mae_nivel_acceso."
    echo "SET FOREIGN_KEY_CHECKS=0;"
    "$MYSQL_BIN/mysqldump" -uroot --host=127.0.0.1 --default-character-set=utf8mb4 --no-tablespaces --set-gtid-purged=OFF --skip-comments "$BD_TEMPORAL" "${TABLAS[@]}"
    echo "SET FOREIGN_KEY_CHECKS=1;"
  } | sed -E -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' -e 's#/\*!80016 DEFAULT ENCRYPTION=.N. \*/##g' \
    > "$SALIDA/sql/${BD_PRODUCCION}-fastore.sql"
  "${M[@]}" -e "DROP DATABASE $BD_TEMPORAL;"
else
  paso "4/5 Base de datos: sin cambios (actualización)"
fi

# ------------------------------------------------------------------ 5. Comprimir
paso '5/5 Comprimiendo'
zip_de "$SALIDA/fastore-shop.juarezcampos.net.zip" "$RAIZ_SHOP" fastore-api-app
cp "$API/despliegue/LEEME-cpanel.md" "$SALIDA/LEEME.md"
rm -rf "$C"

printf '\n\033[32mPaquete listo:\033[0m %s\n' "$SALIDA"
ls -la "$SALIDA" "$SALIDA/sql" 2>/dev/null
