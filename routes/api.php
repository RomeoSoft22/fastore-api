<?php

use App\Http\Controllers\Api\AccesoController;
use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\CarritoController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ReclamoController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\TiendaController;
use App\Http\Middleware\RequiereAcceso;
use Illuminate\Support\Facades\Route;

Route::get('estado', fn () => ['estado' => 'ok']);

// ---------------------------------------------------------------- Tienda pública
Route::prefix('tienda')->group(function () {
    Route::get('config', [TiendaController::class, 'config']);
    Route::get('inicio', [TiendaController::class, 'inicio']);
    Route::get('categorias', [TiendaController::class, 'listaCategorias']);
    Route::get('productos', [TiendaController::class, 'productos']);
    Route::get('productos/{slug}', [TiendaController::class, 'producto'])->where('slug', '[A-Za-z0-9-]+');

    // Carrito anónimo (seguimiento de abandonos) y envío del pedido por WhatsApp.
    Route::post('carrito', [CarritoController::class, 'sincronizar'])->middleware('throttle:carrito');
    Route::post('carrito/pedido', [CarritoController::class, 'pedido'])->middleware('throttle:pedido');
    Route::post('cupon', [CarritoController::class, 'cupon'])->middleware('throttle:cupon');
    Route::get('cotizaciones/{codigo}', [CarritoController::class, 'cotizacion'])->where('codigo', 'COT-[0-9]+-[A-Za-z0-9]+')->middleware('throttle:cupon');
    // Libro de Reclamaciones virtual
    Route::post('reclamos', [ReclamoController::class, 'store'])->middleware('throttle:pedido');
});

Route::get('media/{ruta}', [MediaController::class, 'mostrar'])->where('ruta', '.*');
Route::get('seo/meta', [SeoController::class, 'meta']);
Route::get('seo/sitemap.xml', [SeoController::class, 'sitemap']);

// ---------------------------------------------------------------- Ingreso (identidad.juarezcampos.net)
// El login ocurre en identidad; aquí solo se canjea el código, se renueva el token con la
// cookie HttpOnly y se arma la URL de salida.
Route::get('acceso/config', [AccesoController::class, 'config']);
Route::post('acceso/canjear', [AccesoController::class, 'canjear'])->middleware('throttle:acceso');
Route::post('acceso/renovar', [AccesoController::class, 'renovar'])->middleware('throttle:renovar');
Route::post('acceso/salir', [AccesoController::class, 'salir']);

// ---------------------------------------------------------------- Administración (JWT de identidad, aud=FASTORE)
Route::middleware(RequiereAcceso::class)->group(function () {
    Route::get('acceso/yo', [AccesoController::class, 'yo']);

    Route::prefix('admin')->group(function () {
        Route::get('dashboard', Admin\DashboardController::class);
        Route::get('parametros', [Admin\ParametrosController::class, 'index']);
        Route::put('parametros', [Admin\ParametrosController::class, 'update']);
        Route::post('ventas', [Admin\VentaController::class, 'store']);

        Route::get('cupones', [Admin\CuponController::class, 'index']);
        Route::get('cupones/generar', [Admin\CuponController::class, 'generar']);
        Route::post('cupones', [Admin\CuponController::class, 'store']);
        Route::put('cupones/{cupon}', [Admin\CuponController::class, 'update']);
        Route::delete('cupones/{cupon}', [Admin\CuponController::class, 'destroy']);

        Route::get('productos', [Admin\ProductoController::class, 'index']);
        Route::post('productos', [Admin\ProductoController::class, 'store']);
        Route::get('productos/{producto}', [Admin\ProductoController::class, 'show']);
        Route::put('productos/{producto}', [Admin\ProductoController::class, 'update']);
        Route::patch('productos/{producto}', [Admin\ProductoController::class, 'patch']);
        Route::delete('productos/{producto}', [Admin\ProductoController::class, 'destroy']);
        Route::post('productos/{producto}/imagenes', [Admin\ProductoController::class, 'subirImagenes']);
        Route::put('productos/{producto}/imagenes/orden', [Admin\ProductoController::class, 'ordenarImagenes']);
        Route::delete('productos/{producto}/imagenes/{imagen}', [Admin\ProductoController::class, 'eliminarImagen']);
        Route::post('productos/{producto}/imagen-descripcion', [Admin\ProductoController::class, 'imagenDescripcion']);

        Route::get('reclamos', [ReclamoController::class, 'index']);
        Route::put('reclamos/{reclamo}', [ReclamoController::class, 'responder']);

        Route::get('catalogos', [Admin\CatalogoController::class, 'index']);
        Route::post('categorias', [Admin\CatalogoController::class, 'guardarCategoria']);
        Route::put('categorias/{categoria}', [Admin\CatalogoController::class, 'guardarCategoria']);
        Route::delete('categorias/{categoria}', [Admin\CatalogoController::class, 'eliminarCategoria']);
        Route::post('marcas', [Admin\CatalogoController::class, 'guardarMarca']);
        Route::put('marcas/{marca}', [Admin\CatalogoController::class, 'guardarMarca']);
        Route::delete('marcas/{marca}', [Admin\CatalogoController::class, 'eliminarMarca']);

        // Multipart: la edición va por POST para poder adjuntar la imagen.
        Route::get('banners', [Admin\BannerController::class, 'index']);
        Route::post('banners', [Admin\BannerController::class, 'store']);
        Route::put('banners/orden', [Admin\BannerController::class, 'ordenar']);
        Route::post('banners/{banner}', [Admin\BannerController::class, 'update']);
        Route::delete('banners/{banner}', [Admin\BannerController::class, 'destroy']);

        Route::get('carritos', [Admin\CarritoController::class, 'index']);
        Route::get('carritos/{carrito}', [Admin\CarritoController::class, 'show']);
        Route::patch('carritos/{carrito}', [Admin\CarritoController::class, 'update']);
    });
});
