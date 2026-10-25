# fastore-api

API de la tienda virtual **FAStore** (`shop.juarezcampos.net`). Laravel 13 · PHP 8.3 · MySQL/MariaDB `juarezc_sistema_admin`.
La interfaz está en `fastore-web` (Angular). La administración ingresa con **identidad.juarezcampos.net** (sistema `FASTORE`).

## Qué hace

| Área | Rutas | Detalle |
|---|---|---|
| Catálogo público | `GET /api/tienda/{config,inicio,categorias,productos,productos/{slug}}` | Búsqueda con filtros (texto, categoría, marcas, precio, disponibilidad, condición, orden) y facetas con conteos. |
| Carrito y pedido | `POST /api/tienda/carrito`, `/carrito/pedido`, `/cupon` | El navegador sincroniza el carrito con un token anónimo: así se ve quién agregó y no compró. El pedido sale por WhatsApp y queda registrado. |
| Imágenes | `GET /api/media/{productos\|banners}/...webp` | Se suben como JPG/PNG/WebP y se guardan como WebP redimensionado (+ miniatura). Con `.jpg` se entrega la copia JPEG para `og:image`. |
| SEO | `GET /api/seo/meta?ruta=/producto/...`, `/api/seo/sitemap.xml` | Metadatos (título, descripción, Open Graph, JSON-LD Product) que `fastore-web/seo.php` escribe en el HTML. |
| Ingreso | `/api/acceso/{config,canjear,renovar,salir,yo}` | OAuth 2.0 + PKCE contra identidad. JWT RS256 validado con JWKS; refresh token en cookie HttpOnly. |
| Administración | `/api/admin/*` (JWT con `aud=FASTORE` y rol de `IDENTIDAD_ROLES`) | Dashboard, productos y fotos, categorías y marcas, banners, carritos y pedidos, ventas manuales, cupones, parámetros de costos. |

## Base de datos

Las tablas heredadas (`mae_productos`, `mae_marca`, `mae_categoria`…) **solo reciben columnas nuevas**, nunca se borran:

- `mae_productos`: `n_categoria`, `x_slug`, `x_resumen`, `l_destacado`, `f_creado`, `n_costo`, `n_costo_extra` (y `x_producto` pasa a 150 caracteres; los nombres que estaban cortados a 50 se completan desde la descripción).
- `mae_categoria`: `x_slug`, `x_descripcion`, `n_orden` (+ categorías `TECNOLOGÍA` y `ACCESORIOS`). `mae_marca`: `x_slug`.
- Nuevas: `mae_producto_imagen`, `mae_banner`, `car_carrito`, `car_carrito_item`, `car_cupon`, `cfg_parametro`.

## Fórmula de costos (`app/Support/Costos.php`)

```
costo unitario  = costo × (1 + flete% + aduana%) + empaque + costo extra del producto
precio sugerido = costo unitario ÷ (1 − margen objetivo% − comisión%)
ganancia venta  = total cobrado − Σ(costo unitario × cantidad) − entrega por pedido − comisión
```

Los parámetros se editan en Admin → Fórmula de costos. Al **confirmar una venta** se guarda la foto de sus costos y se descuenta el stock; reabrirla lo revierte. Así, cambiar un costo hoy no altera las ventas pasadas.

## Puesta en marcha local

```bash
composer install
cp .env.example .env          # y ajustar IDENTIDAD_* a la identidad local (ver abajo)
php artisan key:generate
php artisan migrate
php artisan fastore:importar-imagenes "C:\JJUAREZCA\proyecto_fastore\FAStore\public\img\productos"
php artisan fastore:banners "..\fastore-web\herramientas\banners"
php artisan serve --port=8010  # o _local\fastore-api.cmd
```

Identidad local: `IDENTIDAD_URL=http://localhost:4300`, `IDENTIDAD_API_URL=http://127.0.0.1:8001/api`, `IDENTIDAD_REDIRECT_URI=http://localhost:4700/callback`, con el `client_id`/secreto que entrega `php artisan identidad:sistema FASTORE ...` en `identidad`.

## Producción

- `.env`: `APP_DEBUG=false`, `TIENDA_URL=https://shop.juarezcampos.net`, `TIENDA_API_URL=https://shop.juarezcampos.net/fastore-api`, `IDENTIDAD_URL=https://identidad.juarezcampos.net`, `IDENTIDAD_CLIENT_ID=01a17035-6b8c-72e7-b44e-e353ceb723bb` y el secreto del panel, `IDENTIDAD_REDIRECT_URI=https://shop.juarezcampos.net/callback`.
- Matricular en el panel a quien administrará la tienda (`FASTORE` → `ADMIN` o `COLABORADOR`).
- `storage/app/public` guarda las fotos: debe tener permisos de escritura y respaldarse.
- Requiere las extensiones `gd` (con WebP), `pdo_mysql`, `openssl`, `fileinfo`.
