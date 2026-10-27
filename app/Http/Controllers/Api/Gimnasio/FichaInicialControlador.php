<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FichaInicialControlador extends Controller
{
    private function autorizar(Request $request, int $deportistaId): void
    {
        abort_unless(DB::table('clientes.deportistas')->where('id', $deportistaId)->exists(), 404);
        $user = $request->user();
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole', $user->usr_tipo)->value('role');
        abort_unless(
            app(\App\Services\Seguridad\PermisoService::class)->usuarioTieneAlgunaFuncion(
                $user, ['GIMNASIO-DEPORTISTAS', 'ENTRENAMIENTO-EVALUACIONES']
            ) || in_array($rol, ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'ENTRENADOR'], true),
            403
        );
        if ($rol === 'ENTRENADOR') {
            $asignado = DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->join('gimnasio.entrenadores as e', 'e.id', '=', 'entrenamiento.asignaciones_entrenador_cliente.entrenador_id')
                ->where('entrenamiento.asignaciones_entrenador_cliente.deportista_id', $deportistaId)
                ->where('e.usuario_id', $user->id)
                ->where('entrenamiento.asignaciones_entrenador_cliente.estado', 'ACTIVO')->exists();
            abort_unless($asignado, 403, 'Solo puedes registrar fichas de tus deportistas asignados.');
        }
    }

    public function mostrar(Request $request, int $id)
    {
        $this->autorizar($request, $id);
        $ficha = DB::table('clientes.fichas_iniciales')->where('deportista_id', $id)->first();
        if ($ficha) {
            foreach (['antecedentes', 'evaluacion', 'objetivos', 'valoracion'] as $seccion) {
                $ficha->$seccion = json_decode($ficha->$seccion ?: '{}', true);
            }
        }
        return ApiResponse::exito('Ficha inicial consultada.', $ficha ? (array) $ficha : null);
    }

    public function guardar(Request $request, int $id)
    {
        $this->autorizar($request, $id);
        $datos = $request->validate([
            'seccion' => ['required', Rule::in(['antecedentes', 'evaluacion', 'objetivos', 'valoracion'])],
            'datos' => ['required', 'array'],
            'datos.*' => ['nullable'],
        ], [
            'seccion.required' => 'Selecciona una sección de la ficha.',
            'seccion.in' => 'La sección seleccionada no es válida.',
            'datos.required' => 'Ingresa información antes de guardar la sección.',
            'datos.array' => 'Los datos de la sección no tienen el formato esperado.',
        ]);
        $campos = [
            'antecedentes' => ['enfermedades', 'lesiones', 'medicamentos', 'restricciones'],
            'evaluacion' => ['peso_kg', 'talla_cm', 'cintura_cm', 'grasa_corporal_pct', 'movilidad'],
            'objetivos' => ['objetivo_principal', 'experiencia', 'dias_semana', 'metas'],
            'valoracion' => ['conclusiones', 'recomendaciones', 'fecha_seguimiento'],
        ];
        $seccion = $datos['seccion'];
        $permitidos = $campos[$seccion];
        $valores = array_intersect_key($datos['datos'], array_flip($permitidos));
        if (! collect($valores)->contains(fn ($valor) => is_scalar($valor) && trim((string) $valor) !== '')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'datos' => 'Ingresa al menos un dato en esta sección antes de guardar.',
            ]);
        }
        foreach ($valores as $campo => $valor) {
            if (! is_null($valor) && ! is_scalar($valor)) {
                abort(422, 'Los campos deben contener texto o números.');
            }
            if (is_string($valor) && mb_strlen($valor) > 3000) {
                abort(422, 'El campo '.$campo.' supera el máximo permitido.');
            }
        }
        foreach (['peso_kg' => 500, 'talla_cm' => 270, 'cintura_cm' => 300, 'grasa_corporal_pct' => 100, 'dias_semana' => 7] as $campo => $maximo) {
            if (isset($valores[$campo]) && $valores[$campo] !== '') {
                abort_unless(is_numeric($valores[$campo]) && (float) $valores[$campo] >= 0 && (float) $valores[$campo] <= $maximo, 422, 'Valor fuera del rango para '.$campo);
            }
        }
        if (! empty($valores['fecha_seguimiento'])) {
            $request->merge(['fecha_seguimiento_validacion' => $valores['fecha_seguimiento']]);
            $request->validate(['fecha_seguimiento_validacion' => 'date']);
        }

        DB::transaction(function () use ($id, $request, $seccion, $valores): void {
            $antes = null;
            $actual = DB::table('clientes.fichas_iniciales')->where('deportista_id', $id)->lockForUpdate()->first();
            if ($actual) {
                abort_if($actual->estado === 'COMPLETADA', 409, 'La ficha completada no puede sobrescribirse.');
                $antes = $actual->$seccion;
                DB::table('clientes.fichas_iniciales')->where('id', $actual->id)->update([
                    $seccion => json_encode($valores), 'updated_at' => now(),
                ]);
            } else {
                $fichaId = DB::table('clientes.fichas_iniciales')->insertGetId([
                    'deportista_id' => $id, 'registrado_por' => $request->user()->id,
                    $seccion => json_encode($valores), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('clientes.fichas_iniciales_historial')->insert([
                'ficha_id' => $actual?->id ?? $fichaId,
                'modificado_por' => $request->user()->id,
                'seccion' => $seccion,
                'datos_anteriores' => $antes,
                'datos_nuevos' => json_encode($valores),
                'created_at' => now(),
            ]);
        });
        return $this->mostrar($request, $id);
    }
}
