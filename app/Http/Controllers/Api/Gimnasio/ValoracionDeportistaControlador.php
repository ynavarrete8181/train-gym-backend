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
            'evaluacion'=>['peso_kg','talla_cm','cintura_cm','grasa_corporal_pct','movilidad','observaciones'],
            'objetivos'=>['objetivo','indicador','valor_inicial','valor_meta','observaciones'],
            'valoracion'=>['conclusiones','recomendaciones','observaciones'],
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
