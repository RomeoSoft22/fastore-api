<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carritos de los visitantes, sincronizados desde el navegador. Permiten ver quién agregó productos
 * y no concluyó el pedido (carrito abandonado) para darle seguimiento por WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_carrito', function (Blueprint $table) {
            $table->increments('n_carrito');
            $table->uuid('c_token')->unique();
            // activo: tiene productos y no envió el pedido · pedido: lo envió por WhatsApp
            // vendido: la venta se confirmó · descartado: no se hará seguimiento · vacio: sin productos
            $table->string('x_estado', 12)->default('activo')->index();
            $table->string('x_nombre', 120)->nullable();
            $table->string('x_celular', 15)->nullable();
            $table->string('x_correo', 150)->nullable();
            $table->string('x_estacion', 60)->nullable();
            $table->string('x_hora', 20)->nullable();
            $table->string('x_pago', 30)->nullable();
            $table->unsignedSmallInteger('n_items')->default(0);
            $table->decimal('n_total', 10, 2)->default(0);
            $table->string('x_origen', 255)->nullable();
            $table->string('x_dispositivo', 120)->nullable();
            $table->string('x_ip', 45)->nullable();
            $table->text('x_nota')->nullable();
            $table->unsignedSmallInteger('n_contactos')->default(0);
            $table->timestamp('f_contactado')->nullable();
            $table->timestamp('f_pedido')->nullable();
            $table->timestamp('f_actividad')->nullable()->index();
            $table->timestamp('f_creado')->nullable();
            $table->timestamp('f_actualizado')->nullable();
        });

        Schema::create('car_carrito_item', function (Blueprint $table) {
            $table->increments('n_item');
            $table->unsignedInteger('n_carrito');
            $table->integer('n_producto');
            $table->unsignedSmallInteger('n_cantidad')->default(1);
            $table->decimal('n_precio', 10, 2)->default(0);
            $table->timestamp('f_agregado')->nullable();
            $table->timestamp('f_actualizado')->nullable();
            $table->unique(['n_carrito', 'n_producto']);
            $table->foreign('n_carrito', 'FK_item_carrito')->references('n_carrito')->on('car_carrito')->cascadeOnDelete();
            $table->foreign('n_producto', 'FK_item_producto')->references('n_producto')->on('mae_productos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_carrito_item');
        Schema::dropIfExists('car_carrito');
    }
};
