<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Cotización: cada pedido genera un código (COT-00012-K7QM) con vigencia, que se envía por WhatsApp.
 * - Lugar de entrega: estación del tren, recojo en tienda, envío local o envío nacional (Shalom).
 * - Aceptación de términos y privacidad al pedir (Ley 29733).
 * - Etiquetas de producto (SEO y búsqueda).
 * - Libro de Reclamaciones virtual (Código de Protección y Defensa del Consumidor, Indecopi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_carrito', function (Blueprint $table) {
            $table->string('c_cotizacion', 24)->nullable()->unique()->after('c_token');
            $table->date('f_vence')->nullable()->after('f_pedido');
            // estacion · tienda · local · nacional
            $table->string('x_entrega', 10)->nullable()->after('x_correo');
            $table->string('x_direccion', 255)->nullable()->after('x_estacion');
            $table->string('x_ciudad', 120)->nullable()->after('x_direccion');
            $table->string('x_dni', 12)->nullable()->after('x_ciudad');
            $table->timestamp('f_acepta_terminos')->nullable()->after('f_vence');
        });

        Schema::table('mae_productos', function (Blueprint $table) {
            $table->string('x_etiquetas', 500)->nullable()->after('x_resumen');
            // La descripción pasa a ser HTML del editor: text (64 KB) puede quedarse corto con imágenes.
            $table->mediumText('x_descripcion')->nullable()->change();
        });

        Schema::create('rcl_reclamo', function (Blueprint $table) {
            $table->increments('n_reclamo');
            $table->string('c_codigo', 24)->unique();
            $table->enum('x_tipo', ['reclamo', 'queja']);
            $table->string('x_nombre', 150);
            $table->string('x_documento_tipo', 10);
            $table->string('x_documento', 20);
            $table->string('x_domicilio', 255);
            $table->string('x_correo', 150);
            $table->string('x_telefono', 20);
            $table->string('x_apoderado', 150)->nullable();
            $table->enum('x_bien', ['producto', 'servicio']);
            $table->decimal('n_monto', 10, 2)->nullable();
            $table->string('x_pedido', 40)->nullable();
            $table->text('x_descripcion_bien');
            $table->text('x_detalle');
            $table->text('x_pedido_consumidor');
            // pendiente · atendido
            $table->string('x_estado', 12)->default('pendiente');
            $table->text('x_respuesta')->nullable();
            $table->timestamp('f_respuesta')->nullable();
            $table->string('x_ip', 45)->nullable();
            $table->timestamp('f_creado')->nullable();
            $table->timestamp('f_actualizado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcl_reclamo');
        Schema::table('mae_productos', fn (Blueprint $table) => $table->dropColumn('x_etiquetas'));
        Schema::table('car_carrito', fn (Blueprint $table) => $table->dropColumn(['c_cotizacion', 'f_vence', 'x_entrega', 'x_direccion', 'x_ciudad', 'x_dni', 'f_acepta_terminos']));
    }
};
