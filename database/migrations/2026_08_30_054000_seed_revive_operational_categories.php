<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $categorias = [
        'ADMINISTRACION' => 'Administración',
        'ENTRENAMIENTO' => 'Entrenamiento',
        'CANCHAS' => 'Canchas',
        'CLASES_DEPORTIVAS' => 'Clases deportivas',
        'PREPARACION_FISICA' => 'Preparación física',
        'RECUPERACION_FISICA' => 'Recuperación física',
        'VENTAS_Y_TIENDA' => 'Ventas y tienda',
        'MEMBRESIAS' => 'Membresías',
        'EVENTOS_Y_TORNEOS' => 'Eventos y torneos',
    ];

    private array $academicas = [
        'AGRICULTURA_SILVICULTURA_PESCA_Y_VETERINARIA',
        'ARTES_Y_HUMANIDADES',
        'CIENCIAS_NATURALES_MATEMATICAS_Y_ESTADISTICA',
        'CIENCIAS_SOCIALES_PERIODISMO_INFORMACION_Y_DERECHO',
        'EDUCACION',
        'INGENIERIA_INDUSTRIA_Y_CONSTRUCCION',
        'SALUD_Y_BIENESTAR',
        'SERVICIOS',
        'TECNOLOGIAS_DE_LA_INFORMACION_Y_LA_COMUNICACION',
    ];

    public function up(): void
    {
        $now = Carbon::now();

        DB::table('institucional.campos_amplios')
            ->where('codigo', 'SERVICIOS')
            ->update(['codigo' => 'ENTRENAMIENTO', 'nombre' => 'Entrenamiento', 'activo' => true, 'updated_at' => $now]);

        foreach ($this->categorias as $codigo => $nombre) {
            DB::table('institucional.campos_amplios')->updateOrInsert(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        DB::table('institucional.campos_amplios')
            ->whereIn('codigo', array_diff($this->academicas, ['SERVICIOS']))
            ->whereNotIn('id_campo_amplio', function ($query): void {
                $query->select('id_campo_amplio')
                    ->from('institucional.carreras_areas')
                    ->whereNotNull('id_campo_amplio');
            })
            ->delete();
    }

    public function down(): void
    {
        DB::table('institucional.campos_amplios')
            ->whereIn('codigo', array_keys($this->categorias))
            ->delete();
    }
};
