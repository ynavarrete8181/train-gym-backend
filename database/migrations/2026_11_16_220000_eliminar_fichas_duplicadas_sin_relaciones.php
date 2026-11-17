<?php

use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimina las fichas duplicadas que quedaron inactivas (3UJHG, AZVJW, TRUSR), a pedido de la administración,
 * para que no se vuelvan a elegir por error. Solo se borra una ficha si no tiene membresías, ventas,
 * asistencias, reservas, valoraciones ni ningún otro registro relacionado; si tiene algo, se conserva inactiva.
 * La persona solo se borra si tampoco aparece como tutor en el historial de otro cliente.
 * Cada eliminación deja en auditoría los datos completos que tenía.
 */
return new class extends Migration
{
    private const DUPLICADAS = [
        '3UJHG' => 'Ficha duplicada de Carlitos Montesdeoca Montalvo (SUQTJ).',
        'AZVJW' => 'Ficha duplicada de Ema Montesdeoca (5EK69).',
        'TRUSR' => 'Ficha duplicada de Raymon Palma Sacoto (WSZQ7).',
    ];

    /** Registros propios de la ficha que se eliminan con ella. */
    private const PROPIAS = ['clientes.deportista_representantes', 'clientes.deportista_codigos_historicos'];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::DUPLICADAS as $codigo => $motivo) {
                $d = DB::table('clientes.deportistas')->where('codigo_deportista', $codigo)->first();
                if (! $d || $d->estado !== 'INACTIVO' || $this->tieneRelaciones('clientes.deportistas', (int) $d->id, self::PROPIAS)) {
                    continue;
                }
                $persona = $d->persona_id ? DB::table('personas.personas')->where('id', $d->persona_id)->first() : null;
                $antes = [
                    'deportista' => $d,
                    'persona' => $persona,
                    'representantes' => DB::table('clientes.deportista_representantes')->where('deportista_id', $d->id)->get(),
                ];

                DB::table('clientes.deportista_representantes')->where('deportista_id', $d->id)->delete();
                DB::table('clientes.deportista_codigos_historicos')->where('deportista_id', $d->id)->delete();
                DB::table('clientes.deportistas')->where('id', $d->id)->delete();

                // La persona se conserva si figura como tutor en el historial de otro cliente.
                $personaEliminada = false;
                if ($persona && ! DB::table('clientes.deportista_representantes')->where('representante_persona_id', $persona->id)->exists()) {
                    DB::table('clientes.tutores')->where('persona_id', $persona->id)->where('activo', false)->delete();
                    if (! $this->tieneRelaciones('personas.personas', (int) $persona->id, [])) {
                        DB::table('personas.persona_ubicaciones')->where('persona_id', $persona->id)->delete();
                        DB::table('personas.personas')->where('id', $persona->id)->delete();
                        $personaEliminada = true;
                    }
                }

                app(AuditoriaServicio::class)->registrar([
                    'modulo' => 'clientes', 'tabla' => 'clientes.deportistas', 'accion' => 'ELIMINAR', 'registro_id' => $d->id,
                    'datos_antes' => $antes, 'datos_despues' => null,
                    'descripcion' => mb_substr("Eliminada {$codigo} por duplicada, sin membresías ni ventas. {$motivo}"
                        . ($personaEliminada ? '' : ' La persona se conserva por el historial de tutores.'), 0, 255),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sin reversión automática: los datos eliminados quedan completos en auditoria.eventos.
    }

    /** @param array<int, string> $ignorar tablas propias que se eliminan junto con el registro */
    private function tieneRelaciones(string $tablaReferida, int $id, array $ignorar): bool
    {
        $referencias = DB::select("
            SELECT kcu.table_schema || '.' || kcu.table_name AS tabla, kcu.column_name AS columna
            FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
            WHERE ccu.table_schema || '.' || ccu.table_name = ? AND ccu.column_name = 'id'", [$tablaReferida]);

        foreach ($referencias as $r) {
            if (in_array($r->tabla, $ignorar, true)) {
                continue;
            }
            if (DB::table($r->tabla)->where($r->columna, $id)->exists()) {
                return true;
            }
        }

        return false;
    }
};
