<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catálogo con categorías y URLs amigables. Solo agrega columnas a las tablas existentes
 * (mae_categoria, mae_marca, mae_productos) para no romper el sistema anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mae_categoria', function (Blueprint $table) {
            $table->string('x_slug', 80)->nullable()->unique()->after('x_categoria');
            $table->string('x_descripcion', 255)->nullable()->after('x_slug');
            $table->unsignedSmallInteger('n_orden')->default(0)->after('x_descripcion');
        });

        Schema::table('mae_marca', function (Blueprint $table) {
            $table->string('x_slug', 80)->nullable()->unique()->after('x_marca');
        });

        Schema::table('mae_productos', function (Blueprint $table) {
            $table->string('x_producto', 150)->change();
            $table->integer('n_categoria')->nullable()->after('n_marca');
            $table->string('x_slug', 170)->nullable()->unique()->after('x_producto');
            $table->string('x_resumen', 300)->nullable()->after('x_descripcion');
            $table->enum('l_destacado', ['0', '1'])->default('0')->after('l_estado');
            $table->timestamp('f_creado')->nullable()->after('f_registro');
            $table->foreign('n_categoria', 'FK_mae_productos_categoria')->references('n_categoria')->on('mae_categoria')->nullOnDelete();
        });

        $this->datosIniciales();
    }

    public function down(): void
    {
        Schema::table('mae_productos', function (Blueprint $table) {
            $table->dropForeign('FK_mae_productos_categoria');
            $table->dropColumn(['n_categoria', 'x_slug', 'x_resumen', 'l_destacado', 'f_creado']);
        });
        Schema::table('mae_marca', fn (Blueprint $table) => $table->dropColumn('x_slug'));
        Schema::table('mae_categoria', fn (Blueprint $table) => $table->dropColumn(['x_slug', 'x_descripcion', 'n_orden']));
    }

    /** Slugs, dos categorías nuevas y la categoría de cada producto según su marca. */
    private function datosIniciales(): void
    {
        foreach (['TECNOLOGÍA', 'ACCESORIOS'] as $nombre) {
            if (! DB::table('mae_categoria')->where('x_categoria', $nombre)->exists()) {
                DB::table('mae_categoria')->insert(['x_categoria' => $nombre, 'l_activo' => '1']);
            }
        }

        $descripciones = [
            'NFC' => 'Tarjetas, stickers y lectores NFC/RFID para automatizar y compartir tu información.',
            'CASA' => 'Proyectores y tecnología para disfrutar en casa.',
            'FIGURAS DE ACCIÓN' => 'Figuras coleccionables de anime y videojuegos.',
            'TECNOLOGÍA' => 'Cámaras para auto, almacenamiento y gadgets.',
            'ACCESORIOS' => 'Collares y accesorios de tus series favoritas.',
        ];
        $orden = ['NFC' => 1, 'TECNOLOGÍA' => 2, 'FIGURAS DE ACCIÓN' => 3, 'CASA' => 4, 'ACCESORIOS' => 5];
        foreach (DB::table('mae_categoria')->get() as $c) {
            DB::table('mae_categoria')->where('n_categoria', $c->n_categoria)->update([
                'x_slug' => Str::slug($c->x_categoria),
                'x_descripcion' => $descripciones[$c->x_categoria] ?? null,
                'n_orden' => $orden[$c->x_categoria] ?? 9,
            ]);
        }
        foreach (DB::table('mae_marca')->get() as $m) {
            DB::table('mae_marca')->where('n_marca', $m->n_marca)->update(['x_slug' => Str::slug($m->x_marca)]);
        }

        // Categoría sugerida por marca; se puede cambiar luego desde la administración.
        $categoria = fn (string $nombre) => DB::table('mae_categoria')->where('x_categoria', $nombre)->value('n_categoria');
        $porMarca = [
            'NFC' => 'NFC', 'RFID' => 'NFC',
            'PVC' => 'FIGURAS DE ACCIÓN', 'Pokémon' => 'FIGURAS DE ACCIÓN',
            "Monkey D' Luffy" => 'ACCESORIOS',
            'SoamCam' => 'TECNOLOGÍA', 'Carcasa' => 'TECNOLOGÍA',
            'HY300' => 'CASA',
        ];

        $usados = [];
        foreach (DB::table('mae_productos as p')->join('mae_marca as m', 'm.n_marca', '=', 'p.n_marca')->select('p.*', 'm.x_marca')->orderBy('p.n_producto')->get() as $p) {
            $nombre = $this->nombreCompleto($p->x_producto, (string) $p->x_descripcion);
            $slug = self::slug($nombre);
            if (isset($usados[$slug])) {
                $slug .= '-'.$p->n_producto;
            }
            $usados[$slug] = true;

            DB::table('mae_productos')->where('n_producto', $p->n_producto)->update([
                'x_producto' => $nombre,
                'x_slug' => $slug,
                'n_categoria' => isset($porMarca[$p->x_marca]) ? $categoria($porMarca[$p->x_marca]) : null,
                'f_creado' => $p->f_registro,
                // f_registro se actualiza solo en cada cambio (ON UPDATE); se conserva su valor.
                'f_registro' => DB::raw('f_registro'),
            ]);
        }
    }

    /**
     * El nombre era varchar(50) y quedó cortado a media palabra; la descripción empieza con el
     * nombre completo. Se toma su primera línea (hasta la primera coma si es muy larga).
     */
    private function nombreCompleto(string $nombre, string $descripcion): string
    {
        $primera = rtrim(trim((string) strtok(str_replace("\r", '', $descripcion), "\n")), '.');
        if (mb_strlen($nombre) < 50 || ! str_starts_with($primera, rtrim($nombre))) {
            return trim($nombre);
        }
        if (mb_strlen($primera) > 90 && ($coma = mb_strpos($primera, ', ', 25)) !== false) {
            $primera = mb_substr($primera, 0, $coma);
        }

        return Str::limit($primera, 150, '');
    }

    /** Slug de hasta 70 caracteres sin cortar palabras. */
    private static function slug(string $nombre): string
    {
        $slug = Str::slug($nombre);

        return strlen($slug) <= 70 ? $slug : substr($slug, 0, strrpos(substr($slug, 0, 71), '-') ?: 70);
    }
};
