<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rentabilidad: costo de cada producto, parámetros de la fórmula de costos, cupones de descuento
 * y la foto de costos de cada venta (para que cambiar un costo hoy no altere las ventas pasadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mae_productos', function (Blueprint $table) {
            // Costo de manufactura o compra por unidad (S/). La fórmula le suma flete, aduana y empaque.
            $table->decimal('n_costo', 10, 2)->nullable()->after('x_descuento');
            // Costo adicional propio del producto (S/ por unidad): estuche, grabado, etc.
            $table->decimal('n_costo_extra', 10, 2)->default(0)->after('n_costo');
        });

        Schema::create('cfg_parametro', function (Blueprint $table) {
            $table->string('x_clave', 40)->primary();
            $table->string('x_valor', 255);
        });
        DB::table('cfg_parametro')->insert([
            ['x_clave' => 'costo_flete_pct', 'x_valor' => '15'],
            ['x_clave' => 'costo_aduana_pct', 'x_valor' => '0'],
            ['x_clave' => 'costo_empaque', 'x_valor' => '1.50'],
            ['x_clave' => 'costo_entrega_pedido', 'x_valor' => '3.00'],
            ['x_clave' => 'comision_pct', 'x_valor' => '0'],
            ['x_clave' => 'margen_objetivo_pct', 'x_valor' => '35'],
        ]);

        Schema::create('car_cupon', function (Blueprint $table) {
            $table->increments('n_cupon');
            $table->string('c_codigo', 30)->unique();
            $table->string('x_descripcion', 150)->nullable();
            $table->enum('x_tipo', ['porcentaje', 'monto'])->default('porcentaje');
            $table->decimal('n_valor', 10, 2);
            $table->decimal('n_minimo', 10, 2)->default(0);
            $table->unsignedInteger('n_max_usos')->nullable();
            $table->unsignedInteger('n_usos')->default(0);
            $table->date('f_inicio')->nullable();
            $table->date('f_fin')->nullable();
            $table->enum('l_activo', ['0', '1'])->default('1');
            $table->timestamp('f_creado')->nullable();
            $table->timestamp('f_actualizado')->nullable();
        });

        Schema::table('car_carrito', function (Blueprint $table) {
            // web: pedido desde la tienda · manual: venta registrada desde el admin (WhatsApp, en persona…)
            $table->string('x_canal', 10)->default('web')->after('x_estado');
            $table->decimal('n_subtotal', 10, 2)->default(0)->after('n_items');
            $table->string('x_cupon', 30)->nullable()->after('n_total');
            $table->decimal('n_descuento', 10, 2)->default(0)->after('x_cupon');
            // Costos fijados al confirmar la venta.
            $table->decimal('n_costo_entrega', 10, 2)->default(0)->after('n_descuento');
            $table->decimal('n_comision', 10, 2)->default(0)->after('n_costo_entrega');
            $table->timestamp('f_venta')->nullable()->index()->after('f_pedido');
        });
        DB::table('car_carrito')->update(['n_subtotal' => DB::raw('n_total')]);

        Schema::table('car_carrito_item', function (Blueprint $table) {
            $table->decimal('n_costo', 10, 2)->nullable()->after('n_precio');
        });
    }

    public function down(): void
    {
        Schema::table('car_carrito_item', fn (Blueprint $table) => $table->dropColumn('n_costo'));
        Schema::table('car_carrito', fn (Blueprint $table) => $table->dropColumn(['x_canal', 'n_subtotal', 'x_cupon', 'n_descuento', 'n_costo_entrega', 'n_comision', 'f_venta']));
        Schema::dropIfExists('car_cupon');
        Schema::dropIfExists('cfg_parametro');
        Schema::table('mae_productos', fn (Blueprint $table) => $table->dropColumn(['n_costo', 'n_costo_extra']));
    }
};
