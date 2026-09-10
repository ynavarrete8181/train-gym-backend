<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $camposAcademicos = [
        'ADMINISTRACION' => 'Administración',
        'AGRICULTURA_SILVICULTURA_PESCA_Y_VETERINARIA' => 'Agricultura, Silvicultura, Pesca y Veterinaria',
        'ARTES_Y_HUMANIDADES' => 'Artes y Humanidades',
        'CIENCIAS_NATURALES_MATEMATICAS_Y_ESTADISTICA' => 'Ciencias Naturales, Matemáticas y Estadística',
        'CIENCIAS_SOCIALES_PERIODISMO_INFORMACION_Y_DERECHO' => 'Ciencias Sociales, Periodismo, Información y Derecho',
        'EDUCACION' => 'Educación',
        'INGENIERIA_INDUSTRIA_Y_CONSTRUCCION' => 'Ingeniería, Industria y Construcción',
        'SALUD_Y_BIENESTAR' => 'Salud y Bienestar',
        'SERVICIOS' => 'Servicios',
        'TECNOLOGIAS_DE_LA_INFORMACION_Y_LA_COMUNICACION' => 'Tecnologías de la Información y la Comunicación',
    ];

    private array $camposDeportivos = [
        'ENTRENAMIENTO',
        'CANCHAS',
        'CLASES_DEPORTIVAS',
        'PREPARACION_FISICA',
        'RECUPERACION_FISICA',
        'VENTAS_Y_TIENDA',
        'MEMBRESIAS',
        'EVENTOS_Y_TORNEOS',
    ];

    public function up(): void
    {
        $now = Carbon::now();

        DB::table('institucional.campos_amplios')
            ->where('codigo', 'ENTRENAMIENTO')
            ->update(['codigo' => 'SERVICIOS', 'nombre' => 'Servicios', 'activo' => true, 'updated_at' => $now]);

        foreach ($this->camposAcademicos as $codigo => $nombre) {
            DB::table('institucional.campos_amplios')->updateOrInsert(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        DB::table('institucional.campos_amplios')
            ->whereIn('codigo', $this->camposDeportivos)
            ->whereNotIn('id_campo_amplio', function ($query): void {
                $query->select('id_campo_amplio')
                    ->from('institucional.carreras_areas')
                    ->whereNotNull('id_campo_amplio');
            })
            ->delete();

        $this->restaurarEtiquetasMenu($now);
    }

    public function down(): void
    {
        $now = Carbon::now();

        DB::table('institucional.campos_amplios')
            ->where('codigo', 'SERVICIOS')
            ->update(['codigo' => 'ENTRENAMIENTO', 'nombre' => 'Entrenamiento', 'activo' => true, 'updated_at' => $now]);
    }

    private function restaurarEtiquetasMenu(Carbon $now): void
    {
        $etiquetas = [
            'INSTITUCIONAL-UNIDADES' => ['nombre' => 'Facultades / Direcciones', 'icono' => 'account_balance', 'orden' => 2],
            'INSTITUCIONAL-CAMPOS-AMPLIOS' => ['nombre' => 'Campos amplios', 'icono' => 'category', 'orden' => 3],
            'INSTITUCIONAL-CARRERAS-AREAS' => ['nombre' => 'Carreras / Áreas', 'icono' => 'school', 'orden' => 4],
        ];

        foreach ($etiquetas as $codigo => $datos) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', $codigo)
                    ->update([
                        'nombre' => $datos['nombre'],
                        'icono' => $datos['icono'],
                        'orden' => $datos['orden'],
                        'updated_at' => $now,
                    ]);
            }
        }
    }
};
