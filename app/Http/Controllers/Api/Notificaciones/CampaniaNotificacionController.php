<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notificaciones\GuardarCampaniaRequest;
use App\Models\User;
use App\Services\Notificaciones\CampaniaNotificacionService;
use App\Services\Notificaciones\NotificacionUsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CampaniaNotificacionController extends Controller
{
    public function __construct(
        private readonly CampaniaNotificacionService $service,
        private readonly NotificacionUsuarioService $notificacionUsuarioService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('notificaciones.campanias as c')->leftJoin('notificaciones.plantillas as p', 'p.id', '=', 'c.plantilla_id')
            ->select('c.*', 'p.nombre as plantilla_nombre')
            ->when($request->estado === 'EN_PROCESO', fn ($x) => $x->whereIn('c.estado', ['EN_COLA', 'PROCESANDO']))
            ->when($request->estado === 'COMPLETADAS', fn ($x) => $x->whereIn('c.estado', ['COMPLETADA', 'COMPLETADA_CON_ERRORES']))
            ->when($request->estado === 'CON_ERRORES', fn ($x) => $x->whereIn('c.estado', ['ERROR', 'COMPLETADA_CON_ERRORES']))
            ->when($request->estado && ! in_array($request->estado, ['EN_PROCESO', 'COMPLETADAS', 'CON_ERRORES'], true), fn ($x) => $x->where('c.estado', $request->estado))
            ->when($request->boolean('historial'), fn ($x) => $x->whereIn('c.estado', ['COMPLETADA', 'COMPLETADA_CON_ERRORES']))
            ->orderByDesc('c.id')->paginate(min(100, max(5, (int) $request->input('per_page', 10))));

        return ApiResponse::exito('Campañas consultadas.', $q);
    }

    public function historialGeneral(Request $request): JsonResponse
    {
        $accesos = DB::table('notificaciones.notificaciones as n')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'n.usuario_id')
            ->selectRaw("n.id, 'ACCESO' as categoria, CASE WHEN n.tipo = 'RESTABLECIMIENTO_CLAVE' THEN 'Restablecimiento de contraseña' ELSE 'Invitación de acceso' END as titulo, COALESCE(u.name, n.correo_destino) as destinatario, n.correo_destino, n.estado, CASE WHEN n.estado = 'ENVIADA' THEN 1 ELSE 0 END as enviados, CASE WHEN n.estado = 'ERROR' THEN 1 ELSE 0 END as errores, COALESCE(n.enviado_at, n.encolado_at, n.created_at) as fecha");

        $comunicados = DB::table('notificaciones.campanias as c')
            ->selectRaw("c.id, 'COMUNICADO' as categoria, c.nombre as titulo, CONCAT(c.total_destinatarios, ' destinatario(s)') as destinatario, NULL as correo_destino, c.estado, c.total_enviados as enviados, c.total_errores as errores, COALESCE(c.finalizada_at, c.iniciada_at, c.created_at) as fecha");

        $query = DB::query()->fromSub($accesos->unionAll($comunicados), 'historial')
            ->when($request->tipo, fn ($q, $tipo) => $q->where('categoria', $tipo))
            ->when($request->estado, fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('fecha');

        return ApiResponse::exito('Historial general consultado.', $query->paginate(min(100, max(5, (int) $request->input('per_page', 10)))));
    }

    public function historialDetalle(string $tipo, int $id): JsonResponse
    {
        if ($tipo === 'ACCESO') {
            $notificacion = DB::table('notificaciones.notificaciones as n')
                ->leftJoin('seguridad.users as u', 'u.id', '=', 'n.usuario_id')
                ->leftJoin('notificaciones.plantillas as p', 'p.id', '=', 'n.plantilla_id')
                ->where('n.id', $id)
                ->selectRaw('n.*, u.name as usuario_nombre, u.cedula, p.nombre as plantilla_nombre, COALESCE(n.asunto_enviado, p.asunto) as asunto, COALESCE(n.cuerpo_html_enviado, p.cuerpo_html) as cuerpo_html, COALESCE(n.cuerpo_texto_enviado, p.cuerpo_texto) as cuerpo_texto')
                ->first();
            abort_unless($notificacion, 404, 'La invitación no existe.');
            $intentos = DB::table('notificaciones.intentos')->where('notificacion_id', $id)->orderByDesc('numero_intento')->get();

            return ApiResponse::exito('Detalle de invitación consultado.', compact('notificacion', 'intentos'));
        }

        abort_unless($tipo === 'COMUNICADO', 404, 'Tipo de notificación no válido.');
        $campania = DB::table('notificaciones.campanias')->find($id);
        abort_unless($campania, 404, 'El comunicado no existe.');
        $destinatarios = DB::table('notificaciones.campania_destinatarios')->where('campania_id', $id)->orderBy('nombre_destinatario')->get();
        $intentos = DB::table('notificaciones.campania_intentos as i')
            ->join('notificaciones.campania_destinatarios as d', 'd.id', '=', 'i.destinatario_id')
            ->where('d.campania_id', $id)
            ->select('i.*', 'd.correo_destino', 'd.nombre_destinatario')
            ->orderByDesc('i.id')->get();

        return ApiResponse::exito('Detalle de comunicado consultado.', compact('campania', 'destinatarios', 'intentos'));
    }

    public function reenviarAcceso(Request $request, int $id): JsonResponse
    {
        $notificacion = DB::table('notificaciones.notificaciones')->find($id);
        abort_unless($notificacion?->usuario_id, 404, 'La invitación o su usuario ya no están disponibles.');
        $usuario = User::findOrFail($notificacion->usuario_id);
        $nuevoId = $notificacion->tipo === 'RESTABLECIMIENTO_CLAVE'
            ? $this->notificacionUsuarioService->solicitarRestablecimiento($usuario, $request->user()?->id)
            : $this->notificacionUsuarioService->solicitar($usuario, $request->user()?->id, true);

        return ApiResponse::exito('Se generó una invitación nueva con un enlace de activación nuevo.', ['notificacion_id' => $nuevoId], codigo: 202);
    }

    public function duplicarComunicado(Request $request, int $id): JsonResponse
    {
        $nuevoId = $this->service->duplicar($id, $request->user()?->id);

        return ApiResponse::exito('Se creó un borrador independiente a partir del comunicado.', ['campania_id' => $nuevoId], codigo: 201);
    }

    public function catalogos(): JsonResponse
    {
        return ApiResponse::exito('Catálogos consultados.', [
            'plantillas' => DB::table('notificaciones.plantillas')->where('activo', true)->where('tipo', 'COMUNICADO')->orderBy('nombre')->get(),
            'usuarios' => DB::table('seguridad.users')->where('usr_estado', 1)->orderBy('name')->get(['id', 'name', 'email', 'cedula']),
            'usuarios_app' => DB::table('seguridad.usuarios_app as ua')
                ->join('seguridad.users as u', 'u.id', '=', 'ua.usuario_id')
                ->leftJoin('seguridad.usuario_app_vinculaciones as v', function ($join): void {
                    $join->on('v.usuario_app_id', '=', 'ua.id')->where('v.activo', true);
                })
                ->where('ua.activo', true)->where('u.usr_estado', 1)
                ->groupBy('u.id', 'u.name', 'u.email', 'u.cedula', 'ua.ultimo_acceso_at')
                ->orderBy('u.name')
                ->get(['u.id', 'u.name', 'u.email', 'u.cedula', 'ua.ultimo_acceso_at', DB::raw("STRING_AGG(v.tipo, ', ' ORDER BY v.tipo) as vinculaciones")]),
            'roles' => DB::table('seguridad.cpu_userrole')->where('activo', true)->orderBy('role')->get(['id_userrole as id', 'role as nombre']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
            'unidades' => DB::table('institucional.unidades')->where('activo', true)->orderBy('nombre')->get(['id_unidad as id', 'nombre']),
            'carreras_areas' => DB::table('institucional.carreras_areas')->where('activo', true)->orderBy('nombre')->get(['id_carrera_area as id', 'nombre']),
        ]);
    }

    public function subirImagenApp(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $ruta = $datos['imagen']->store('comunicaciones-app', 'public');
        abort_unless($ruta, 500, 'No se pudo almacenar la imagen.');

        return ApiResponse::exito('Imagen cargada correctamente.', [
            'ruta' => $ruta,
            'nombre' => $datos['imagen']->getClientOriginalName(),
            'url' => $request->getSchemeAndHttpHost().'/storage/'.$ruta,
        ], codigo: 201);
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $d = $request->validate($this->reglasCriterios() + [
            'canales' => ['required', 'array'],
            'canales.interno' => ['required', 'boolean'],
            'canales.correo' => ['required', 'boolean'],
            'canales.push' => ['required', 'boolean'],
            'canales.app' => ['sometimes', 'boolean'],
        ]);
        $destinatarios = $this->service->resolver($d['criterios'], $d['canales']);

        return ApiResponse::exito('Destinatarios calculados.', ['total' => $destinatarios->count(), 'muestra' => $destinatarios->take(10)->values()]);
    }

    public function store(GuardarCampaniaRequest $request): JsonResponse
    {
        $d = $request->validated();
        $cc = collect($d['correos_cc'] ?? [])->map(fn ($v) => mb_strtolower(trim($v)))->unique();
        $cco = collect($d['correos_cco'] ?? [])->map(fn ($v) => mb_strtolower(trim($v)))->reject(fn ($v) => $cc->contains($v))->unique();
        $d['correos_cc'] = $cc->values()->all();
        $d['correos_cco'] = $cco->values()->all();
        $id = $this->service->crear($d, $request->user()?->id);

        return ApiResponse::exito('Campaña creada como borrador.', DB::table('notificaciones.campanias')->find($id), codigo: 201);
    }

    public function update(GuardarCampaniaRequest $request, int $id): JsonResponse
    {
        $d = $request->validated();
        $this->service->actualizarBorrador($id, $d);

        return ApiResponse::exito('Borrador actualizado correctamente.', DB::table('notificaciones.campanias')->find($id));
    }

    public function show(int $id): JsonResponse
    {
        $campania = DB::table('notificaciones.campanias')->find($id);
        abort_unless($campania, 404, 'La campaña no existe.');
        $destinatarios = DB::table('notificaciones.campania_destinatarios')->where('campania_id', $id)->orderBy('nombre_destinatario')->paginate(20);

        return ApiResponse::exito('Detalle consultado.', compact('campania', 'destinatarios'));
    }

    public function enviar(int $id): JsonResponse
    {
        $this->service->enviar($id);

        return ApiResponse::exito('La campaña fue enviada a procesamiento.');
    }

    public function destroy(int $id): JsonResponse
    {
        $campania = DB::table('notificaciones.campanias')->find($id);
        abort_unless($campania, 404, 'La campaña no existe.');
        if ($campania->estado !== 'BORRADOR') {
            return ApiResponse::error('Solo se pueden eliminar comunicados en borrador.');
        }

        DB::transaction(function () use ($id): void {
            DB::table('notificaciones.campania_destinatarios')->where('campania_id', $id)->delete();
            DB::table('notificaciones.campanias')->where('id', $id)->delete();
        });

        return ApiResponse::exito('Borrador eliminado correctamente.');
    }

    public function reenviarErrores(int $id): JsonResponse
    {
        $this->service->enviar($id, true);

        return ApiResponse::exito('Los envíos con error fueron enviados nuevamente a procesamiento.');
    }

    private function reglasCriterios(): array
    {
        return [
            'criterios' => ['required', 'array'],
            'criterios.todos' => ['nullable', 'boolean'],
            'criterios.usuarios' => ['nullable', 'array'],
            'criterios.usuarios.*' => ['integer'],
            'criterios.roles' => ['nullable', 'array'],
            'criterios.roles.*' => ['integer'],
            'criterios.sedes' => ['nullable', 'array'],
            'criterios.sedes.*' => ['integer'],
            'criterios.unidades' => ['nullable', 'array'],
            'criterios.unidades.*' => ['integer'],
            'criterios.carreras_areas' => ['nullable', 'array'],
            'criterios.carreras_areas.*' => ['integer'],
            'criterios.externos' => ['nullable', 'array'],
            'criterios.externos.*.nombre' => ['nullable', 'string', 'max:180'],
            'criterios.externos.*.correo' => ['required_with:criterios.externos', 'email', 'max:255'],
            'criterios.vinculaciones' => ['nullable', 'array'],
            'criterios.vinculaciones.*' => [Rule::in(['ESTUDIANTE', 'DOCENTE', 'ADMINISTRATIVO', 'SIN_CLASIFICAR'])],
        ];
    }
}
