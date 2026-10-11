<?php

namespace App\Services\Ventas;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CajaServicio
{
    use RegistraAuditoria;

    public function __construct(
        private readonly AlcanceOperativoService $alcance,
    ) {
    }

    public function guardar(array $datos, ?int $id = null, ?int $usuarioId = null): object
    {
        $sedeId = (int) ($datos['sede_id'] ?? 0);
        $this->alcance->validarSede($usuarioId, $sedeId ?: null, 'maneja_caja');

        return DB::transaction(function () use ($datos, $id, $sedeId): object {
            $sede = DB::table('institucional.sedes')
                ->where('id_sede', $sedeId)
                ->lockForUpdate()
                ->first();

            if (! $sede) {
                throw ValidationException::withMessages([
                    'sede_id' => 'La sede seleccionada no existe.',
                ]);
            }

            $antes = $id ? DB::table('ventas.cajas')->where('id', $id)->first() : null;
            if ($id && ! $antes) {
                throw ValidationException::withMessages([
                    'id' => 'La caja que intentas actualizar no existe.',
                ]);
            }

            $cambioSede = $antes && (int) $antes->sede_id !== $sedeId;
            if ($cambioSede && DB::table('ventas.turnos_caja')->where('caja_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'sede_id' => 'No puedes cambiar la sede de una caja que ya tiene turnos registrados. Crea una nueva caja para la nueva sede.',
                ]);
            }

            $codigo = $antes?->codigo;
            if (! $codigo || $cambioSede) {
                $codigo = $this->siguienteCodigo($sede);
            }

            $descripcion = trim((string) ($datos['descripcion'] ?? ''));
            if ($descripcion === '') {
                $descripcion = "Caja operativa para la gestión de cobros y ventas de la sede {$sede->nombre}.";
            }

            $registro = [
                'sede_id' => $sedeId,
                'codigo' => $codigo,
                'nombre' => trim((string) $datos['nombre']),
                'descripcion' => $descripcion,
                // El efectivo inicial pertenece al turno, no a la configuración permanente de la caja.
                'saldo_inicial' => 0,
                'activa' => (bool) ($datos['activa'] ?? true),
                'updated_at' => now(),
            ];

            if ($id) {
                DB::table('ventas.cajas')->where('id', $id)->update($registro);
                $despues = DB::table('ventas.cajas')->where('id', $id)->first();
                $this->auditar('ventas', 'ACTUALIZAR', 'ventas.cajas', $id, $antes, $despues);

                return $despues;
            }

            $registro['created_at'] = now();
            $nuevoId = DB::table('ventas.cajas')->insertGetId($registro);
            $despues = DB::table('ventas.cajas')->where('id', $nuevoId)->first();
            $this->auditar('ventas', 'CREAR', 'ventas.cajas', $nuevoId, null, $despues);

            return $despues;
        });
    }

    private function siguienteCodigo(object $sede): string
    {
        $prefijo = 'CAJA-' . $this->codigoSede($sede) . '-';
        $ultimo = 0;

        $codigos = DB::table('ventas.cajas')
            ->where('codigo', 'like', $prefijo . '%')
            ->pluck('codigo');

        foreach ($codigos as $codigo) {
            if (preg_match('/^' . preg_quote($prefijo, '/') . '(\d+)$/', (string) $codigo, $coincidencias)) {
                $ultimo = max($ultimo, (int) $coincidencias[1]);
            }
        }

        return $prefijo . str_pad((string) ($ultimo + 1), 3, '0', STR_PAD_LEFT);
    }

    private function codigoSede(object $sede): string
    {
        // El código legible de la caja debe derivarse del nombre visible de la sede,
        // no del código interno institucional (por ejemplo 0002).
        $base = (string) ($sede->nombre ?? '');

        $base = strtoupper(Str::ascii($base));
        $base = preg_replace('/^SEDE[\s_-]+/', '', $base) ?: $base;
        $base = preg_replace('/^REVIVE[\s_-]+/', '', $base) ?: $base;
        $base = preg_replace('/[^A-Z0-9]+/', '-', $base) ?: '';
        $base = trim($base, '-');

        return $base !== '' ? $base : 'SEDE-' . $sede->id_sede;
    }
}
