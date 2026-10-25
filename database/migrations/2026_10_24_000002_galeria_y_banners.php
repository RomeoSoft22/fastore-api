<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Galería de fotos por producto y banners de la portada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mae_producto_imagen', function (Blueprint $table) {
            $table->increments('n_imagen');
            $table->integer('n_producto');
            // Rutas relativas al disco "public" (productos/{id}/{archivo}.webp).
            $table->string('x_archivo', 190);
            $table->string('x_miniatura', 190);
            $table->string('x_alt', 150)->nullable();
            $table->unsignedSmallInteger('n_ancho')->default(0);
            $table->unsignedSmallInteger('n_alto')->default(0);
            $table->unsignedSmallInteger('n_orden')->default(0);
            $table->timestamp('f_creado')->nullable();
            $table->index(['n_producto', 'n_orden']);
            $table->foreign('n_producto', 'FK_imagen_producto')->references('n_producto')->on('mae_productos')->cascadeOnDelete();
        });

        Schema::create('mae_banner', function (Blueprint $table) {
            $table->increments('n_banner');
            $table->string('x_titulo', 120);
            $table->string('x_subtitulo', 255)->nullable();
            $table->string('x_boton', 40)->nullable();
            // Ruta interna de la tienda (/categoria/nfc) o URL completa.
            $table->string('x_enlace', 255)->nullable();
            $table->string('x_imagen', 190);
            $table->string('x_imagen_movil', 190)->nullable();
            // Color del texto sobre la imagen.
            $table->enum('x_tema', ['claro', 'oscuro'])->default('claro');
            $table->unsignedSmallInteger('n_orden')->default(0);
            $table->enum('l_activo', ['0', '1'])->default('1');
            $table->date('f_inicio')->nullable();
            $table->date('f_fin')->nullable();
            $table->timestamp('f_creado')->nullable();
            $table->timestamp('f_actualizado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mae_banner');
        Schema::dropIfExists('mae_producto_imagen');
    }
};
