<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ValoracionDeportistaControlador extends Controller
{
    private const SECCIONES = ['antecedentes','evaluacion','objetivos','valoracion'];

    private function autorizar(Request $request, int $id): void
    {
        abort_unless(DB::table('clientes.deportistas')->where('id',$id)->exists(),404);
        $usuario = $request->user();
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole',$usuario->usr_tipo)->value('role');
        abort_unless(in_array($rol,['ADMINISTRADOR','ENTRENADOR'],true),403);
        if ($rol === 'ENTRENADOR') {
            abort_unless(DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
                ->join('entrenamiento.entrenadores as e','e.id','=','a.entrenador_id')
                ->where('a.deportista_id',$id)->where('e.usuario_id',$usuario->id)
                ->where('a.estado','ACTIVO')->exists(),403);
        }
    }

    public function listar(Request $request, int $id)
    {
        $this->autorizar($request,$id);
        $seccion = $request->validate(['seccion'=>['required',Rule::in(self::SECCIONES)]])['seccion'];
        $registros = DB::table('clientes.valoracion_registros')
            ->where('deportista_id',$id)->where('seccion',$seccion)
            ->orderByDesc('fecha_registro')->orderByDesc('id')->limit(150)->get()
            ->map(function ($registro) {
                $registro->datos = json_decode($registro->datos ?: '{}',true);
                return $registro;
            });
        return ApiResponse::exito('Historial consultado.',$registros);
    }

    public function crear(Request $request, int $id)
    {
        $this->autorizar($request,$id);
        $v = $request->validate([
            'seccion'=>['required',Rule::in(self::SECCIONES)],
            'titulo'=>'required|string|max:180',
            'fecha_registro'=>'required|date',
            'fecha_inicio'=>'nullable|date',
            'fecha_fin'=>'nullable|date|after_or_equal:fecha_inicio',
            'proximo_control'=>'nullable|date|after:fecha_registro',
            'datos'=>'required|array',
            'datos.*'=>'nullable',
            'control_id'=>'nullable|integer',
        ]);
        $permitidos = [
            'antecedentes'=>['descripcion','tipo','restricciones','observaciones'],
            'evaluacion'=>['peso_kg','talla_cm','cintura_cm','grasa_corporal_pct','actividad_fisica','patologia','movilidad','observaciones'],
            'objetivos'=>['objetivo','indicador','unidad_medida','valor_inicial','valor_meta','prioridad','estado_objetivo','observaciones','objetivo_codigo','plazo_semanas'],
            'valoracion'=>['condicion_fisica','movilidad_general','experiencia_entrenamiento','requiere_adaptacion','frecuencia_seguimiento','conclusiones','recomendaciones','observaciones'],
        ];
        $datos = array_intersect_key($v['datos'],array_flip($permitidos[$v['seccion']]));
        foreach ($datos as $campo => $valor) {
            if (!is_null($valor) && (!is_scalar($valor) || mb_strlen((string)$valor)>3000)) {
                throw ValidationException::withMessages([$campo=>'Dato inválido o demasiado extenso.']);
            }
        }
        if (!collect($datos)->contains(fn($x)=>is_scalar($x) && trim((string)$x)!=='')) {
            throw ValidationException::withMessages(['datos'=>'Registra al menos un dato antes de guardar.']);
        }
        foreach (['peso_kg'=>500,'talla_cm'=>270,'cintura_cm'=>300,'grasa_corporal_pct'=>100] as $campo=>$max) {
            if (isset($datos[$campo]) && $datos[$campo]!==''
                && (!is_numeric($datos[$campo]) || (float)$datos[$campo]<=0 || (float)$datos[$campo]>$max)) {
                throw ValidationException::withMessages([$campo=>'Valor fuera del rango permitido.']);
            }
        }
        $opciones = [
            'actividad_fisica' => ['SEDENTARIO','LIGERA','MODERADA','ALTA','MUY_ALTA'],
            'objetivo_codigo' => ['FUERZA','RESISTENCIA','MOVILIDAD','COMPOSICION','ADHERENCIA'],
            'prioridad' => ['ALTA','MEDIA','BAJA'],
            'estado_objetivo' => ['PENDIENTE','EN_PROGRESO','CUMPLIDO','SUSPENDIDO'],
            'unidad_medida' => ['KG','CM','PORCENTAJE','REPETICIONES','MINUTOS','OTRO'],
            'condicion_fisica' => ['INICIAL','INTERMEDIA','AVANZADA','POR_EVALUAR'],
            'movilidad_general' => ['ADECUADA','LIMITADA','REQUIERE_EVALUACION'],
            'experiencia_entrenamiento' => ['PRINCIPIANTE','INTERMEDIO','AVANZADO'],
            'requiere_adaptacion' => ['SI','NO','POR_DETERMINAR'],
            'frecuencia_seguimiento' => ['SEMANAL','QUINCENAL','MENSUAL','SEGUN_NECESIDAD'],
        ];
        foreach ($opciones as $campo => $permitidas) {
            if (isset($datos[$campo]) && $datos[$campo] !== '' && !in_array($datos[$campo], $permitidas, true)) {
                throw ValidationException::withMessages(['datos.'.$campo => 'Selecciona una opción válida.']);
            }
        }
        if ($v['seccion'] === 'objetivos') {
            foreach (['valor_inicial','valor_meta'] as $campo) {
                if (isset($datos[$campo]) && $datos[$campo] !== ''
                    && (!is_numeric($datos[$campo]) || !is_finite((float) $datos[$campo]) || abs((float) $datos[$campo]) > 1000000)) {
                    throw ValidationException::withMessages(['datos.'.$campo => 'Ingresa un número válido.']);
                }
            }
        }
        if ($v['seccion'] === 'evaluacion') {
            $peso = isset($datos['peso_kg']) && is_numeric($datos['peso_kg']) ? (float) $datos['peso_kg'] : null;
            $talla = isset($datos['talla_cm']) && is_numeric($datos['talla_cm']) ? (float) $datos['talla_cm'] : null;
            $cintura = isset($datos['cintura_cm']) && is_numeric($datos['cintura_cm']) ? (float) $datos['cintura_cm'] : null;
            $grasa = isset($datos['grasa_corporal_pct']) && is_numeric($datos['grasa_corporal_pct']) ? (float) $datos['grasa_corporal_pct'] : null;
            if ($peso !== null && $talla !== null) $datos['imc'] = round($peso / (($talla / 100) ** 2), 2);
            if ($cintura !== null && $talla !== null) $datos['indice_cintura_altura'] = round($cintura / $talla, 4);
            if ($peso !== null && $grasa !== null) $datos['masa_magra_kg'] = round($peso * (1 - $grasa / 100), 2);
        }
        if ($v['seccion'] === 'objetivos') {
            $objetivos = $opciones['objetivo_codigo'];
            if (!empty($datos['objetivo_codigo']) && !in_array($datos['objetivo_codigo'], $objetivos, true)) {
                throw ValidationException::withMessages(['objetivo_codigo' => 'Selecciona un objetivo válido.']);
            }
            if (isset($datos['plazo_semanas']) && $datos['plazo_semanas'] !== ''
                && (!ctype_digit((string) $datos['plazo_semanas']) || (int) $datos['plazo_semanas'] < 1 || (int) $datos['plazo_semanas'] > 104)) {
                throw ValidationException::withMessages(['plazo_semanas' => 'El plazo debe estar entre 1 y 104 semanas.']);
            }
        }
        $registro = DB::transaction(function () use ($request,$id,$v,$datos) {
            $control = null;
            if (!empty($v['control_id'])) {
                $control = DB::table('clientes.valoracion_controles')->where('id',$v['control_id'])
                    ->where('deportista_id',$id)->lockForUpdate()->first();
                abort_unless($control && $control->estado==='PROGRAMADO',422,'El control no está pendiente.');
            }
            $idRegistro = DB::table('clientes.valoracion_registros')->insertGetId([
                'deportista_id'=>$id,'registrado_por'=>$request->user()->id,
                'seccion'=>$v['seccion'],'titulo'=>$v['titulo'],'fecha_registro'=>$v['fecha_registro'],
                'fecha_inicio'=>$v['fecha_inicio']??null,'fecha_fin'=>$v['fecha_fin']??null,
                'proximo_control'=>$v['proximo_control']??null,'datos'=>json_encode($datos),
                'estado'=>'ACTIVO','created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($control) {
                DB::table('clientes.valoracion_controles')->where('id',$control->id)->update([
                    'estado'=>'REALIZADO','registro_resultado_id'=>$idRegistro,
                    'realizado_at'=>now(),'updated_at'=>now(),
                ]);
            }
            if (!empty($v['proximo_control'])) {
                DB::table('clientes.valoracion_controles')->insert([
                    'registro_origen_id'=>$idRegistro,'deportista_id'=>$id,
                    'responsable_id'=>$request->user()->id,'fecha_programada'=>$v['proximo_control'],
                    'estado'=>'PROGRAMADO','created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            return $idRegistro;
        });
        return ApiResponse::exito('Registro guardado correctamente.',['id'=>$registro],codigo:201);
    }

    public function progreso(Request $request, int $id)
    {
        $this->autorizar($request, $id);
        $evaluaciones = DB::table('clientes.valoracion_registros')
            ->where('deportista_id', $id)->where('seccion', 'evaluacion')
            ->orderBy('fecha_registro')->orderBy('id')->get(['id', 'fecha_registro', 'titulo', 'datos', 'registrado_por']);

        $mediciones = $evaluaciones->map(function ($fila) {
            $datos = json_decode($fila->datos ?: '{}', true) ?: [];
            $peso = isset($datos['peso_kg']) && is_numeric($datos['peso_kg']) ? (float) $datos['peso_kg'] : null;
            $talla = isset($datos['talla_cm']) && is_numeric($datos['talla_cm']) ? (float) $datos['talla_cm'] : null;
            $grasa = isset($datos['grasa_corporal_pct']) && is_numeric($datos['grasa_corporal_pct']) ? (float) $datos['grasa_corporal_pct'] : null;
            return [
                'id' => $fila->id, 'fecha' => $fila->fecha_registro, 'titulo' => $fila->titulo,
                'peso_kg' => $peso, 'talla_cm' => $talla,
                'cintura_cm' => isset($datos['cintura_cm']) && is_numeric($datos['cintura_cm']) ? (float) $datos['cintura_cm'] : null,
                'grasa_corporal_pct' => $grasa,
                'imc' => $peso > 0 && $talla > 0 ? round($peso / (($talla / 100) ** 2), 2) : null,
                'masa_magra_kg' => $peso > 0 && $grasa !== null && $grasa >= 0 && $grasa <= 100
                    ? round($peso * (1 - $grasa / 100), 2) : null,
            ];
        })->values();

        $pruebas = $evaluaciones->map(function ($fila) {
            $datos = json_decode($fila->datos ?: '{}', true) ?: [];
            $catalogo = ['FLEXIONES' => ['unidad'=>'repeticiones','sentido'=>'MAYOR','nombre'=>'Flexiones'],
                'PLANCHA' => ['unidad'=>'segundos','sentido'=>'MAYOR','nombre'=>'Plancha abdominal'],
                'CARRERA_2KM' => ['unidad'=>'segundos','sentido'=>'MENOR','nombre'=>'Carrera 2 km']];
            $codigo = $datos['prueba_codigo'] ?? '';
            if (!isset($catalogo[$codigo]) || !isset($datos['prueba_valor']) || !is_numeric($datos['prueba_valor'])
                || ($datos['prueba_unidad'] ?? '') !== $catalogo[$codigo]['unidad']) return null;
            return [
                'id'=>$fila->id,'fecha'=>$fila->fecha_registro,'codigo'=>$codigo,
                'nombre'=>$catalogo[$codigo]['nombre'],'unidad'=>$catalogo[$codigo]['unidad'],
                'sentido'=>$catalogo[$codigo]['sentido'],'valor'=>(float)$datos['prueba_valor'],
                'protocolo'=>$datos['prueba_protocolo'] ?? '',
            ];
        })->filter()->values();
        $comparativas = $pruebas->groupBy('codigo')->map(function ($serie) {
            $primero = $serie->first(); $ultimo = $serie->last();
            $variacion = count($serie) > 1 ? round($ultimo['valor'] - $primero['valor'], 2) : null;
            return [
                'codigo'=>$primero['codigo'],'nombre'=>$primero['nombre'],'unidad'=>$primero['unidad'],
                'sentido'=>$primero['sentido'],'inicial'=>$primero['valor'],'actual'=>$ultimo['valor'],
                'variacion'=>$variacion,
                // No emitir mensajes positivos con muestras aisladas o protocolos diferentes.
                'mejora_verificada'=>count($serie) > 1 && $primero['protocolo'] !== ''
                    && $primero['protocolo'] === $ultimo['protocolo']
                    && ($primero['sentido'] === 'MAYOR' ? $variacion > 0 : $variacion < 0),
            ];
        })->values();
        $pesos = $mediciones->filter(fn ($m) => $m['peso_kg'] !== null)->values();
        $inicial = $pesos->first();
        $ultimo = $pesos->last();
        return ApiResponse::exito('Informe de progreso consultado.', [
            'mediciones' => $mediciones,
            'pruebas' => $pruebas,
            'comparativas_pruebas' => $comparativas,
            'resumen' => [
                'evaluaciones' => $mediciones->count(),
                'peso_inicial_kg' => $inicial['peso_kg'] ?? null,
                'peso_actual_kg' => $ultimo['peso_kg'] ?? null,
                'variacion_peso_kg' => $inicial && $ultimo ? round($ultimo['peso_kg'] - $inicial['peso_kg'], 2) : null,
                'ultima_evaluacion' => $mediciones->last()['fecha'] ?? null,
            ],
            'fuente' => 'clientes.valoracion_registros',
        ]);
    }

    public function controles(Request $request)
    {
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole',$request->user()->usr_tipo)->value('role');
        abort_unless($rol==='ADMINISTRADOR',403);
        $dias = min(90,max(0,(int)$request->query('dias',7)));
        $items = DB::table('clientes.valoracion_controles as c')
            ->join('clientes.valoracion_registros as r','r.id','=','c.registro_origen_id')
            ->leftJoin('clientes.deportistas as d','d.id','=','c.deportista_id')
            ->where('c.estado','PROGRAMADO')
            ->whereDate('c.fecha_programada','<=',now()->addDays($dias)->toDateString())
            ->select('c.id','c.deportista_id','c.fecha_programada','c.responsable_id','r.seccion','r.titulo','d.codigo_deportista')
            ->orderBy('c.fecha_programada')->limit(200)->get();
        return ApiResponse::exito('Controles próximos y vencidos consultados.',$items);
    }
}
