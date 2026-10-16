<?php

namespace App\Http\Controllers\Api\Entrenamiento;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PruebasRendimientoControlador extends Controller
{
    private const PRUEBAS = [
        'FLEXIONES' => ['nombre'=>'Flexiones', 'unidad'=>'REPETICIONES', 'max'=>500],
        'PLANCHA' => ['nombre'=>'Plancha abdominal', 'unidad'=>'SEGUNDOS', 'max'=>3600],
        'CARRERA_2KM' => ['nombre'=>'Carrera de 2 km', 'unidad'=>'SEGUNDOS', 'max'=>7200],
        'SALTO_VERTICAL' => ['nombre'=>'Salto vertical', 'unidad'=>'CM', 'max'=>150],
        'SPRINT_20M' => ['nombre'=>'Sprint de 20 m', 'unidad'=>'SEGUNDOS', 'max'=>120],
        'SIT_AND_REACH' => ['nombre'=>'Flexibilidad: sit and reach', 'unidad'=>'CM', 'max'=>100],
    ];

    private function autorizar(Request $request, int $id): void
    {
        abort_unless(DB::table('clientes.deportistas')->where('id',$id)->exists(),404);
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole',$request->user()->usr_tipo)->value('role');
        if ($rol === 'ENTRENADOR') {
            abort_unless(DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
                ->join('entrenamiento.entrenadores as e','e.id','=','a.entrenador_id')
                ->where('a.deportista_id',$id)->where('e.usuario_id',$request->user()->id)
                ->where('a.estado','ACTIVO')->exists(),403);
        }
    }

    public function deportistas(Request $request)
    {
        $rol = DB::table('seguridad.cpu_userrole')->where('id_userrole',$request->user()->usr_tipo)->value('role');
        $q = DB::table('clientes.deportistas as d')->join('personas.personas as p','p.id','=','d.persona_id');
        if ($rol === 'ENTRENADOR') {
            $q->whereExists(function ($sub) use ($request) {
                $sub->selectRaw('1')->from('entrenamiento.asignaciones_entrenador_cliente as a')
                    ->join('entrenamiento.entrenadores as e','e.id','=','a.entrenador_id')
                    ->whereColumn('a.deportista_id','d.id')->where('e.usuario_id',$request->user()->id)
                    ->where('a.estado','ACTIVO');
            });
        }
        $funciones = DB::table('seguridad.cpu_userfunction')
            ->where('id_users', $request->user()->id)->where('activo', true)
            ->whereIn('id_menu', [
                'ENTRENAMIENTO-EVALUACIONES-CREAR',
                'ENTRENAMIENTO-EVALUACIONES-EDITAR',
                'ENTRENAMIENTO-EVALUACIONES-ANULAR',
            ])->pluck('id_menu')->all();
        return ApiResponse::exito('Deportistas disponibles.', [
            'deportistas'=>$q->orderBy('p.apellidos')->orderBy('p.nombres')
                ->limit(500)->get(['d.id','d.codigo_deportista','p.nombres','p.apellidos']),
            'permisos'=>[
                'crear'=>in_array('ENTRENAMIENTO-EVALUACIONES-CREAR',$funciones,true),
                'editar'=>in_array('ENTRENAMIENTO-EVALUACIONES-EDITAR',$funciones,true),
                'anular'=>in_array('ENTRENAMIENTO-EVALUACIONES-ANULAR',$funciones,true),
            ],
        ]);
    }

    public function listar(Request $request, int $id)
    {
        $this->autorizar($request,$id);
        $registros = DB::table('entrenamiento.pruebas_rendimiento')
            ->where('deportista_id',$id)->where('estado','ACTIVO')
            ->orderByDesc('fecha_prueba')->orderByDesc('id')->limit(150)->get();
        return ApiResponse::exito('Pruebas de rendimiento consultadas.', [
            'catalogo' => self::PRUEBAS,
            'registros' => $registros,
        ]);
    }

    public function guardar(Request $request, int $id)
    {
        $this->autorizar($request,$id);
        $v = $request->validate([
            'codigo_prueba'=>['required',Rule::in(array_keys(self::PRUEBAS))],
            'fecha_prueba'=>'required|date|before_or_equal:today',
            'resultado'=>'required|numeric|gt:0',
            'protocolo'=>'required|string|min:5|max:500',
            'observaciones'=>'nullable|string|max:2000',
        ]);
        $prueba = self::PRUEBAS[$v['codigo_prueba']];
        if ($v['resultado'] > $prueba['max']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resultado'=>'Resultado fuera del rango admitido.']);
        }
        if ($prueba['unidad'] === 'REPETICIONES' && floor((float)$v['resultado']) != (float)$v['resultado']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resultado'=>'Las repeticiones deben ser un número entero.']);
        }
        $registro = DB::table('entrenamiento.pruebas_rendimiento')->insertGetId([
            'deportista_id'=>$id, 'registrado_por'=>$request->user()->id,
            'codigo_prueba'=>$v['codigo_prueba'], 'unidad'=>$prueba['unidad'],
            'fecha_prueba'=>$v['fecha_prueba'], 'resultado'=>$v['resultado'],
            'protocolo'=>$v['protocolo'], 'observaciones'=>$v['observaciones']??null,
            'estado'=>'ACTIVO','created_at'=>now(),'updated_at'=>now(),
        ]);
        return ApiResponse::exito('Prueba registrada correctamente.', ['id'=>$registro], codigo:201);
    }

    public function actualizar(Request $request, int $id, int $registro)
    {
        $this->autorizar($request,$id);
        $v = $request->validate([
            'codigo_prueba'=>['required',Rule::in(array_keys(self::PRUEBAS))],
            'fecha_prueba'=>'required|date|before_or_equal:today',
            'resultado'=>'required|numeric|gt:0',
            'protocolo'=>'required|string|min:5|max:500',
            'observaciones'=>'nullable|string|max:2000',
        ]);
        $prueba = self::PRUEBAS[$v['codigo_prueba']];
        if ($v['resultado'] > $prueba['max'] || ($prueba['unidad']==='REPETICIONES' && floor((float)$v['resultado']) != (float)$v['resultado'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resultado'=>'Resultado fuera del rango admitido.']);
        }
        $total = DB::table('entrenamiento.pruebas_rendimiento')->where('id',$registro)
            ->where('deportista_id',$id)->where('estado','ACTIVO')->update([
                'codigo_prueba'=>$v['codigo_prueba'],'fecha_prueba'=>$v['fecha_prueba'],
                'resultado'=>$v['resultado'],'unidad'=>$prueba['unidad'],
                'protocolo'=>$v['protocolo'],'observaciones'=>$v['observaciones']??null,
                'updated_at'=>now(),
            ]);
        abort_unless($total,404);
        return ApiResponse::exito('Prueba actualizada correctamente.');
    }

    public function anular(Request $request, int $id, int $registro)
    {
        $this->autorizar($request,$id);
        $total = DB::table('entrenamiento.pruebas_rendimiento')
            ->where('deportista_id',$id)->where('id',$registro)->where('estado','ACTIVO')
            ->update(['estado'=>'ANULADO','updated_at'=>now()]);
        abort_unless($total,404);
        return ApiResponse::exito('Prueba anulada correctamente.');
    }
}
