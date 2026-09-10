<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\AuditoriaServicio;
use App\Services\Notificaciones\ContenidoCorreoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlantillaCorreoController extends Controller
{
    public function __construct(private readonly ContenidoCorreoService $contenido, private readonly AuditoriaServicio $auditoria) {}

    public function index(): JsonResponse
    {
        return ApiResponse::exito('Plantillas consultadas.', DB::table('notificaciones.plantillas as p')->leftJoin('notificaciones.evento_plantilla as ep', fn ($j) => $j->on('ep.plantilla_id', '=', 'p.id')->where('ep.activo', true))->leftJoin('notificaciones.eventos as e', 'e.id', '=', 'ep.evento_id')->select('p.*', 'e.codigo as evento_codigo', 'e.nombre as evento_nombre', DB::raw('COALESCE(ep.predeterminada, false) as predeterminada'))->orderBy('p.nombre')->get()->map(fn ($p) => tap($p, fn ($x) => $x->variables = is_string($x->variables) ? json_decode($x->variables, true) : $x->variables)));
    }

    public function eventos(): JsonResponse
    {
        return ApiResponse::exito('Eventos consultados.', DB::table('notificaciones.eventos')->where('activo', true)->orderBy('nombre')->get(['codigo', 'nombre', 'descripcion']));
    }

    public function guardar(Request $request, ?int $id = null): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:160'], 'tipo' => ['required', Rule::in(['COMUNICADO', 'ACCESO', 'RESTABLECIMIENTO', 'AUTOMATICA'])], 'evento_codigo' => ['nullable', 'string', 'max:100'], 'predeterminada' => ['nullable', 'boolean'], 'asunto' => ['required', 'string', 'max:255'], 'cuerpo_html' => ['required', 'string'], 'cuerpo_texto' => ['nullable', 'string'], 'variables' => ['nullable', 'array'], 'variables.*' => ['string', 'max:80'], 'activo' => ['required', 'boolean']]);
        $d['codigo'] = match ($d['tipo']) {
            'ACCESO' => 'INVITACION_USUARIO',
            'RESTABLECIMIENTO' => 'RESTABLECIMIENTO_CLAVE',
            default => $this->generarCodigo($d['tipo'], $d['nombre']),
        };
        if (DB::table('notificaciones.plantillas')->where('codigo', $d['codigo'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['codigo' => 'El código ya pertenece a otra plantilla.']);
        }
        if ($d['tipo'] !== 'COMUNICADO' && empty($d['evento_codigo'])) {
            throw ValidationException::withMessages(['evento_codigo' => 'Selecciona el evento que utilizará esta plantilla.']);
        }
        if ($d['tipo'] === 'ACCESO' && DB::table('notificaciones.plantillas')->where('tipo', 'ACCESO')->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['tipo' => 'Solo puede existir una plantilla de invitación de acceso. Edita la existente para mantener una configuración única.']);
        }
        if ($d['tipo'] === 'RESTABLECIMIENTO' && DB::table('notificaciones.plantillas')->where('tipo', 'RESTABLECIMIENTO')->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['tipo' => 'Solo puede existir una plantilla de restablecimiento de contraseña.']);
        }
        $eventoCodigo = $d['evento_codigo'] ?? null;
        $predeterminada = (bool) ($d['predeterminada'] ?? false);
        unset($d['evento_codigo'], $d['predeterminada']);
        $d['cuerpo_html'] = $this->contenido->sanitizarHtml($d['cuerpo_html']);
        $d['cuerpo_texto'] = trim((string) ($d['cuerpo_texto'] ?? '')) ?: $this->contenido->generarTexto($d['cuerpo_html']);
        $variablesUsadas = $this->contenido->variablesEn($d['asunto'], $d['cuerpo_html'], $d['cuerpo_texto']);
        $desconocidas = array_diff($variablesUsadas, $this->contenido->variablesPermitidas($d['tipo']));
        if ($desconocidas) {
            throw ValidationException::withMessages(['variables' => 'Variables no admitidas para este tipo de plantilla: '.implode(', ', $desconocidas).'.']);
        }
        if (in_array($d['tipo'], ['ACCESO', 'RESTABLECIMIENTO'], true) && ! in_array('url_activacion', $variablesUsadas, true)) {
            throw ValidationException::withMessages(['variables' => 'La plantilla de acceso debe incluir {{url_activacion}}.']);
        }
        $erroresEstructura = $this->contenido->validarEstructura($d['tipo'], $d['asunto'], $d['cuerpo_html'], $d['cuerpo_texto']);
        if ($erroresEstructura) {
            throw ValidationException::withMessages(['cuerpo_html' => $erroresEstructura]);
        }
        $d['variables'] = json_encode($variablesUsadas);
        $d['updated_at'] = now();
        $esNueva = ! $id;
        $antes = $id ? DB::table('notificaciones.plantillas')->where('id', $id)->first() : null;
        DB::transaction(function () use (&$id, $d, $eventoCodigo, $predeterminada): void {
            if ($id) {
                DB::table('notificaciones.plantillas')->where('id', $id)->update($d);
            } else {
                $id = DB::table('notificaciones.plantillas')->insertGetId($d + ['created_at' => now()]);
            }
            DB::table('notificaciones.evento_plantilla')->where('plantilla_id', $id)->delete();
            if ($eventoCodigo) {
                $evento = DB::table('notificaciones.eventos')->where('codigo', $eventoCodigo)->where('activo', true)->first();
                if (! $evento) {
                    throw ValidationException::withMessages(['evento_codigo' => 'El evento seleccionado no está disponible.']);
                }
                if ($predeterminada) {
                    DB::table('notificaciones.evento_plantilla')->where('evento_id', $evento->id)->update(['predeterminada' => false, 'updated_at' => now()]);
                }
                DB::table('notificaciones.evento_plantilla')->insert(['evento_id' => $evento->id, 'plantilla_id' => $id, 'predeterminada' => $predeterminada, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $despues = DB::table('notificaciones.plantillas')->where('id', $id)->first();
        $this->auditoria->registrar([
            'modulo' => 'notificaciones',
            'tabla' => 'notificaciones.plantillas',
            'registro_id' => $id,
            'accion' => $esNueva ? 'CREAR' : 'ACTUALIZAR',
            'descripcion' => $esNueva ? 'Plantilla de correo creada.' : 'Plantilla de correo actualizada.',
            'datos_antes' => $antes,
            'datos_despues' => $despues,
        ]);

        return ApiResponse::exito('Plantilla guardada.', $despues);
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $d = $request->validate(['asunto' => ['required', 'string'], 'cuerpo_html' => ['required', 'string'], 'cuerpo_texto' => ['nullable', 'string'], 'variables' => ['nullable', 'array']]);
        $v = $d['variables'] ?? [];
        $render = fn (string $t) => preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', fn ($m) => e($v[$m[1]] ?? $m[0]), $t);

        return ApiResponse::exito('Vista previa generada.', ['asunto' => $render($d['asunto']), 'cuerpo_html' => $render($this->contenido->sanitizarHtml($d['cuerpo_html'])), 'cuerpo_texto' => $render(trim((string) ($d['cuerpo_texto'] ?? '')) ?: $this->contenido->generarTexto($d['cuerpo_html']))]);
    }

    private function generarCodigo(string $tipo, string $nombre): string
    {
        $nombreNormalizado = Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();

        return Str::limit($tipo.'_'.$nombreNormalizado, 100, '');
    }
}
