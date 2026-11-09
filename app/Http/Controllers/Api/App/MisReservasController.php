<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MisReservasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deportistaId = $this->deportistaId($request);
        $items = DB::table('agenda.reservas_dia as r')
            ->join('servicios.servicios as sv', 'sv.id', '=', 'r.servicio_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'r.sede_id')
            ->where('r.cliente_id', $deportistaId)->orderByDesc('r.fecha')->orderBy('r.hora_inicio')
            ->get(['r.id', 'r.fecha', 'r.hora_inicio', 'r.hora_fin', 'r.estado', 'r.observaciones', 'r.sede_id', 'sv.nombre as servicio_nombre', 's.nombre as sede_nombre'])
            ->map(function ($r): array {
                $estado = $r->estado === 'CONFIRMADA' ? 'RESERVADA' : $r->estado;

                return array_merge((array) $r, [
                    'id' => (int) $r->id, 'sede_id' => $r->sede_id ? (int) $r->sede_id : null,
                    'estado' => $estado,
                    'estado_nombre' => match ($estado) {
                        'ASISTIO' => 'Asistió', 'NO_ASISTIO' => 'No asistió',
                        'CANCELADA' => 'Cancelada', default => 'Pendiente',
                    },
                ]);
            });

        return response()->json($items);
    }

    public function membresias(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'nullable|date'])['fecha'] ?? now('America/Guayaquil')->toDateString();
        $items = DB::table('membresias.membresias as m')->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->where('m.deportista_id', $this->deportistaId($request))->where('m.estado', 'ACTIVA')
            ->whereDate('m.fecha_inicio', '<=', $fecha)->whereDate('m.fecha_fin', '>=', $fecha)
            ->orderBy('s.nombre')->get(['m.id as socio_membresia_id', 'm.plan_id as membresia_id', 'm.sede_id', 'm.fecha_inicio', 'm.fecha_fin', 'm.precio_aplicado', 'p.nombre as membresia_nombre', 'p.tipo_producto', 's.nombre as sede_nombre'])
            ->map(fn ($i): array => array_merge((array) $i, [
                'socio_membresia_id' => (int) $i->socio_membresia_id, 'membresia_id' => (int) $i->membresia_id,
                'sede_id' => $i->sede_id ? (int) $i->sede_id : null, 'precio_aplicado' => (float) $i->precio_aplicado,
                'es_pase_diario' => $i->tipo_producto === 'PASE_DIARIO',
            ]));

        return response()->json($items);
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'required|date', 'sede_id' => 'required|integer', 'servicio_id' => 'nullable|integer']);
        $this->deportistaId($request);
        $dia = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'][Carbon::parse($datos['fecha'])->dayOfWeek];
        $items = DB::table('agenda.horarios_servicio as h')->join('servicios.servicios as sv', 'sv.id', '=', 'h.servicio_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'h.sede_id')->where('h.activo', true)->whereNull('h.deleted_at')
            ->where('h.sede_id', $datos['sede_id'])->where('h.dia_semana', $dia)
            ->when($datos['servicio_id'] ?? null, fn ($q, $id) => $q->where('h.servicio_id', $id))->orderBy('h.hora_inicio')
            ->get(['h.id', 'h.sede_id', 'h.servicio_id', 'h.hora_inicio', 'h.hora_fin', 'h.capacidad', 'sv.nombre as servicio_nombre', 's.nombre as sede_nombre'])
            ->map(function ($i) use ($datos): array {
                $ocupados = DB::table('agenda.reservas_dia')->where('horario_id', $i->id)->whereDate('fecha', $datos['fecha'])->whereIn('estado', ['RESERVADA', 'CONFIRMADA', 'ASISTIO'])->count();

                return array_merge((array) $i, ['id' => (int) $i->id, 'fecha' => $datos['fecha'], 'sede_id' => (int) $i->sede_id, 'servicio_id' => (int) $i->servicio_id, 'capacidad' => (int) $i->capacidad, 'ocupados' => $ocupados, 'disponibles' => max(0, (int) $i->capacidad - $ocupados)]);
            });

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate(['horario_id' => 'required|integer', 'socio_membresia_id' => 'required|integer', 'fecha' => 'required|date|after_or_equal:'.now('America/Guayaquil')->toDateString()]);
        $deportistaId = $this->deportistaId($request);
        $m = DB::table('membresias.membresias')->where('id', $datos['socio_membresia_id'])->where('deportista_id', $deportistaId)->where('estado', 'ACTIVA')->whereDate('fecha_inicio', '<=', $datos['fecha'])->whereDate('fecha_fin', '>=', $datos['fecha'])->first();
        $h = DB::table('agenda.horarios_servicio')->where('id', $datos['horario_id'])->where('activo', true)->first();
        if (! $m || ! $h || (int) $m->sede_id !== (int) $h->sede_id) {
            throw ValidationException::withMessages(['reserva' => 'La membresía y el horario no son compatibles.']);
        }
        $existe = DB::table('agenda.reservas_dia')->where('cliente_id', $deportistaId)->whereDate('fecha', $datos['fecha'])->where('hora_inicio', '<', $h->hora_fin)->where('hora_fin', '>', $h->hora_inicio)->whereIn('estado', ['RESERVADA', 'CONFIRMADA', 'ASISTIO'])->exists();
        $ocupados = DB::table('agenda.reservas_dia')->where('horario_id', $h->id)->whereDate('fecha', $datos['fecha'])->whereIn('estado', ['RESERVADA', 'CONFIRMADA', 'ASISTIO'])->count();
        if ($existe || $ocupados >= (int) $h->capacidad) {
            throw ValidationException::withMessages(['reserva' => $existe ? 'Ya tienes una reserva en ese horario.' : 'El cupo está completo.']);
        }
        $id = DB::table('agenda.reservas_dia')->insertGetId(['cliente_id' => $deportistaId, 'servicio_id' => $h->servicio_id, 'horario_id' => $h->id, 'entrenador_id' => $h->entrenador_id, 'sede_id' => $h->sede_id, 'fecha' => $datos['fecha'], 'hora_inicio' => $h->hora_inicio, 'hora_fin' => $h->hora_fin, 'estado' => 'RESERVADA', 'observaciones' => 'Reserva creada desde la app.', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Reserva creada correctamente.', 'data' => ['id' => $id]], 201);
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $motivo = $request->validate(['motivo' => 'nullable|string|max:500'])['motivo'] ?? 'Cancelada desde app';
        $ok = DB::table('agenda.reservas_dia')->where('id', $id)->where('cliente_id', $this->deportistaId($request))->whereIn('estado', ['RESERVADA', 'CONFIRMADA'])->update(['estado' => 'CANCELADA', 'observaciones' => $motivo, 'updated_at' => now()]);
        if (! $ok) {
            throw ValidationException::withMessages(['reserva' => 'La reserva no existe o ya no se puede cancelar.']);
        }

        return response()->json(['message' => 'Reserva cancelada correctamente.', 'data' => ['id' => $id, 'estado' => 'CANCELADA']]);
    }

    private function deportistaId(Request $request): int
    {
        $id = DB::table('clientes.deportistas')->where('usuario_id', $request->user()->id)->value('id');
        abort_unless($id, 403, 'La cuenta no tiene un deportista vinculado.');

        return (int) $id;
    }
}
