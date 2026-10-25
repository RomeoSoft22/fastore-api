# Guía de despliegue en cPanel — FAStore (shop.juarezcampos.net)

Usuario de cPanel: `juarezc` · Base de datos: `juarezc_sistema_admin` (la misma de la tienda actual).

Sigue los pasos **en orden**. La tienda nueva reemplaza a la anterior en el mismo dominio y usa la misma base de datos:
las tablas de productos reciben columnas nuevas y se agregan tablas (carritos, cotizaciones, cupones, banners, reclamos).

El paquete trae:

| Archivo | Qué es |
|---|---|
| `fastore-shop.juarezcampos.net.zip` | `shop.juarezcampos.net/` (tienda + `fastore-api/`) y `fastore-api-app/` (código de la API, con `.env` y fotos) |
| `sql/juarezc_sistema_admin-fastore.sql` | Tablas de la tienda ya migradas (solo en el primer despliegue) |

---

## 0. Antes de empezar

- [ ] En el Administrador de archivos activa **Configuración → Mostrar archivos ocultos** (para ver `.env` y `.htaccess`).
- [ ] **Dominios**: anota la **raíz** de `shop.juarezcampos.net` (por ejemplo `/home/juarezc/shop.juarezcampos.net`).
- [ ] **Respaldos** (obligatorio):
  - [ ] phpMyAdmin → `juarezc_sistema_admin` → **Exportar** (guarda el `.sql`).
  - [ ] Comprime y descarga la carpeta raíz actual de `shop.juarezcampos.net`.
- [ ] El `.sql` del paquete se generó a partir del respaldo del **25/10/2026**. Si después cambiaste productos, precios o
  categorías en la tienda anterior, vuelve a generar el paquete con el respaldo nuevo (ver *Generar el paquete*).

## 1. PHP del dominio (una sola vez)

- [ ] **MultiPHP Manager**: `shop.juarezcampos.net` → **PHP 8.3**.
- [ ] **Seleccionar versión de PHP → Extensiones**: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `intl`, `fileinfo`, **`gd`**.
- [ ] **Opciones**: `upload_max_filesize = 10M`, `post_max_size = 40M` (fotos de productos de hasta 8 MB), `expose_php = Off`.
- [ ] **SSL/TLS Status → Run AutoSSL** si el certificado no está vigente.

## 2. Base de datos

- [ ] phpMyAdmin → selecciona `juarezc_sistema_admin` → **Importar** → `sql/juarezc_sistema_admin-fastore.sql` → Continuar.
- [ ] Deben aparecer las tablas nuevas: `mae_producto_imagen`, `mae_banner`, `car_carrito`, `car_carrito_item`, `car_cupon`,
  `cfg_parametro`, `rcl_reclamo`, `migrations`. `mae_usuario`, `mae_perfil` y `mae_nivel_acceso` no se tocan.
- [ ] Anota (o crea en **Bases de datos MySQL**) un usuario con **todos los privilegios** sobre `juarezc_sistema_admin`.

## 3. Archivos

- [ ] **Administrador de archivos** → `/home/juarezc/` → **Cargar** `fastore-shop.juarezcampos.net.zip` → clic derecho → **Extraer** en `/home/juarezc/`.
- [ ] Quedan `/home/juarezc/shop.juarezcampos.net/` y `/home/juarezc/fastore-api-app/`.
- [ ] Si la raíz de tu dominio **no** es `/home/juarezc/shop.juarezcampos.net` (paso 0): mueve el **contenido** de
  `shop.juarezcampos.net/` (incluidos `.htaccess` y la carpeta `fastore-api/`) a esa raíz, reemplazando la tienda anterior.
- [ ] Borra el `.zip` del servidor.

## 4. Completar `fastore-api-app/.env`

Edita `/home/juarezc/fastore-api-app/.env` y cambia **solo** estas líneas:

| Línea | Valor |
|---|---|
| `DB_USERNAME=` / `DB_PASSWORD=` | el usuario MySQL del paso 2 |
| `IDENTIDAD_CLIENT_SECRET=` | el secreto del sistema **FASTORE** (panel → Sistemas → FASTORE; si no lo tienes, «Regenerar secreto») |
| `TIENDA_RUC=` | tu RUC (aparece en el Libro de Reclamaciones y en los términos) |
| `TIENDA_DOMICILIO=` / `TIENDA_CORREO=` | domicilio y correo de atención al cliente |

No toques `APP_KEY`. `IDENTIDAD_CLIENT_ID` ya es el de producción (`01a17035-…`).

## 5. Acceso a la administración

- [ ] **panel.juarezcampos.net** → Usuarios → tu usuario → **Matricular** en `FASTORE` con rol `ADMIN`
  (`COLABORADOR` también puede administrar la tienda).

## 6. Comprobar

| Abre | Debes ver |
|---|---|
| `https://shop.juarezcampos.net/` | La tienda con banners, categorías y productos; el aviso de cookies en la primera visita |
| `https://shop.juarezcampos.net/fastore-api/api/estado` | `{"estado":"ok"}` |
| `https://shop.juarezcampos.net/producto/tarjetas-nfc-215` → *Ver código fuente* | `<title>Tarjetas NFC 215 \| S/ 40.00 \| FAStore</title>` y `og:image` (vista previa al compartir) |
| `https://shop.juarezcampos.net/sitemap.xml` | Un XML con las URLs de productos |
| `https://shop.juarezcampos.net/admin` | Te lleva a identidad y vuelve al dashboard |
| `https://shop.juarezcampos.net/fastore-api/.env` y `/seo-config.php` | Error 403 |

- [ ] Haz un pedido de prueba: debe generar una cotización `COT-…` y abrir WhatsApp. Luego márcalo como **Descartado** en el admin.
- [ ] Comparte un producto por WhatsApp para ver la vista previa (la primera vez WhatsApp puede tardar unos minutos).
- [ ] Opcional: registra el sitemap en **Google Search Console**.

## 7. Actualizaciones posteriores

```bash
ACTUALIZACION=1 bash fastore-api/despliegue/empaquetar-cpanel.sh
```

Genera un `.zip` **sin** `.env`, sin fotos (`storage/`) y sin `.sql`: súbelo y extráelo igual (sobrescribir). Si una versión
trae migraciones nuevas, su `.sql` se entregará aparte.

## Generar el paquete

Desde Git Bash, en `C:\JJUAREZCA\Proyectos_GIThub` (con MySQL de Laragon encendido):

```bash
ORIGEN_SQL="C:/ruta/al/respaldo/juarezc_sistema_admin.sql" TIENDA_RUC=20xxxxxxxxx \
  TIENDA_DOMICILIO="Dirección, Lima" TIENDA_CORREO=ventas@juarezcampos.net \
  bash fastore-api/despliegue/empaquetar-cpanel.sh
```

El paquete queda en `paquetes/cpanel-fastore-<fecha>/` (fuera de los repositorios: el `.env` lleva secretos).

## Si algo falla

Las pantallas nunca muestran detalles técnicos: muestran una **referencia** como `E-HBK8FZIB`. Búscala en
`/home/juarezc/fastore-api-app/storage/logs/laravel.log` para ver el detalle.
