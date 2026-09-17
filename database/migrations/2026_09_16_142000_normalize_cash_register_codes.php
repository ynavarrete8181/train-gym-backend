<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $cajas = DB::table('ventas.cajas as caja')
                ->join('institucional.sedes as sede', 'caja.sede_id', '=', 'sede.id_sede')
                ->select('caja.id', 'caja.codigo', 'caja.sede_id', 'sede.nombre as sede_nombre')
                ->whereRaw("caja.codigo ~ '^CAJA-[0-9]+-[0-9]+$'")
                ->orderBy('caja.sede_id')
                ->orderBy('caja.id')
                ->get()
                ->groupBy('sede_id');

            foreach ($cajas as $grupo) {
                $primera = $grupo->first();
                $prefijo = 'CAJA-' . $this->codigoSede((string) $primera->sede_nombre) . '-';

                $ultimo = 0;
                $existentes = DB::table('ventas.cajas')
                    ->where('codigo', 'like', $prefijo . '%')
                    ->pluck('codigo');

                foreach ($existentes as $codigo) {
                    if (preg_match('/^' . preg_quote($prefijo, '/') . '(\d+)$/', (string) $codigo, $coincidencias)) {
                        $ultimo = max($ultimo, (int) $coincidencias[1]);
                    }
                }

                foreach ($grupo as $caja) {
                    $ultimo++;
                    DB::table('ventas.cajas')
                        ->where('id', $caja->id)
                        ->update([
                            'codigo' => $prefijo . str_pad((string) $ultimo, 3, '0', STR_PAD_LEFT),
                            'updated_at' => now(),
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Normalización de datos legibles: no se restaura el código interno anterior de la sede.
    }

    private function codigoSede(string $nombre): string
    {
        $base = strtoupper(Str::ascii($nombre));
        $base = preg_replace('/^SEDE[\s_-]+/', '', $base) ?: $base;
        $base = preg_replace('/^REVIVE[\s_-]+/', '', $base) ?: $base;
        $base = preg_replace('/[^A-Z0-9]+/', '-', $base) ?: '';
        $base = trim($base, '-');

        return $base !== '' ? $base : 'SEDE';
    }
};
