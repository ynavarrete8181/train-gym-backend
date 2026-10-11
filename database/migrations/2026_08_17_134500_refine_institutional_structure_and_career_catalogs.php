<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institucional.campos_amplios', function (Blueprint $t): void {
            $t->id('id_campo_amplio');
            $t->string('codigo', 120)->unique();
            $t->string('nombre', 180)->unique();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::table('institucional.sede_unidad', function (Blueprint $t): void {
            $t->string('codigo', 200)->nullable()->after('id_unidad');
            $t->unique('codigo', 'sede_unidad_codigo_unique');
        });

        Schema::table('institucional.carreras_areas', function (Blueprint $t): void {
            $t->unsignedBigInteger('id_sede_unidad')->nullable()->after('id_unidad');
            $t->string('codigo_ces', 100)->nullable()->after('codigo');
            $t->unsignedBigInteger('id_campo_amplio')->nullable()->after('tipo');
            $t->foreign('id_sede_unidad')->references('id')->on('institucional.sede_unidad')->restrictOnDelete();
            $t->foreign('id_campo_amplio')->references('id_campo_amplio')->on('institucional.campos_amplios')->restrictOnDelete();
            $t->index(['id_sede_unidad', 'tipo'], 'carreras_areas_sede_unidad_tipo_idx');
            $t->index('codigo_ces');
        });

        foreach ([
            'Administración',
            'Agricultura, Silvicultura, Pesca y Veterinaria',
            'Artes y Humanidades',
            'Ciencias Naturales, Matemáticas y Estadística',
            'Ciencias Sociales, Periodismo, Información y Derecho',
            'Educación',
            'Ingeniería, Industria y Construcción',
            'Salud y Bienestar',
            'Servicios',
            'Tecnologías de la Información y la Comunicación',
        ] as $nombre) {
            $codigo = Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
            DB::table('institucional.campos_amplios')->insertOrIgnore([
                'codigo' => $codigo,
                'nombre' => $nombre,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $relaciones = DB::table('institucional.sede_unidad as su')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->select('su.id', 's.codigo as sede_codigo', 'u.tipo', 'u.nombre')
            ->get();

        foreach ($relaciones as $relacion) {
            $nombre = Str::of(Str::ascii($relacion->nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
            $sede = Str::of(Str::ascii($relacion->sede_codigo))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
            $codigo = substr($relacion->tipo.'_'.$sede.'_'.$nombre, 0, 190);
            if (DB::table('institucional.sede_unidad')->where('codigo', $codigo)->where('id', '<>', $relacion->id)->exists()) {
                $codigo = substr($codigo, 0, 180).'_'.$relacion->id;
            }
            DB::table('institucional.sede_unidad')->where('id', $relacion->id)->update(['codigo' => $codigo, 'updated_at' => now()]);
        }

        foreach (DB::table('institucional.carreras_areas')->select('id_carrera_area', 'id_unidad')->get() as $carrera) {
            $relacionesUnidad = DB::table('institucional.sede_unidad')->where('id_unidad', $carrera->id_unidad)->where('activo', true)->pluck('id');
            if ($relacionesUnidad->count() === 1) {
                DB::table('institucional.carreras_areas')->where('id_carrera_area', $carrera->id_carrera_area)->update([
                    'id_sede_unidad' => $relacionesUnidad->first(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('institucional.carreras_areas', function (Blueprint $t): void {
            $t->dropForeign(['id_sede_unidad']);
            $t->dropForeign(['id_campo_amplio']);
            $t->dropIndex('carreras_areas_sede_unidad_tipo_idx');
            $t->dropIndex(['codigo_ces']);
            $t->dropColumn(['id_sede_unidad', 'codigo_ces', 'id_campo_amplio']);
        });
        Schema::table('institucional.sede_unidad', function (Blueprint $t): void {
            $t->dropUnique('sede_unidad_codigo_unique');
            $t->dropColumn('codigo');
        });
        Schema::dropIfExists('institucional.campos_amplios');
    }
};
