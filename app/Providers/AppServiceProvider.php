<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Límites con contador propio por acción e IP: "throttle:N,1" comparte un solo contador por IP
        // entre rutas, y las sincronizaciones del carrito agotaban el cupo para enviar el pedido.
        $limite = fn (string $nombre, int $porMinuto) => RateLimiter::for(
            $nombre,
            fn (Request $request) => Limit::perMinute($porMinuto)->by($nombre.'|'.$request->ip()),
        );
        $limite('carrito', 120);
        $limite('pedido', 10);
        $limite('cupon', 20);
        $limite('acceso', 20);
        $limite('renovar', 60);
    }
}
