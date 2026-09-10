<?php

namespace App\Services\Notificaciones;

use App\Jobs\Notificaciones\EnviarDestinatarioCampaniaJob;
use App\Jobs\Notificaciones\EnviarInternoCampaniaJob;
use App\Jobs\Notificaciones\EnviarPushCampaniaJob;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CampaniaNotificacionService
{
    use RegistraAuditoria;

    public function resolver(array $criterios, array $canales = ['interno' => false, 'correo' => true, 'push' => false, 'app' => false]): Collection
    {
        $usarInterno = (bool) ($canales['interno'] ?? false);
        $usarCorreo = (bool) ($canales['correo'] ?? false);
        $usarPush = (bool) ($canales['push'] ?? false);
        $usarApp = (bool) ($canales['app'] ?? false);

        if ($usarApp && ($usarInterno || $usarCorreo || $usarPush)) {
            throw new RuntimeException('Publicación app no puede combinarse con otros canales.');
        }

        if (! $usarInterno && ! $usarCorreo && ! $usarPush && ! $usarApp) {
            throw new RuntimeException('Selecciona al menos un canal de envío.');
        }

        $query = DB::table('seguridad.users as u')
            ->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->select('u.id as usuario_id', 'u.name as nombre_destinatario', 'u.email as correo_destino', 'r.role')
            ->where('u.usr_estado', 1);

        if ($usarApp) {
            $query->whereExists(function ($sub): void {
                $sub->selectRaw('1')->from('seguridad.usuarios_app as ua')
                    ->whereColumn('ua.usuario_id', 'u.id')->where('ua.activo', true);
            });
            if ($vinculaciones = ($criterios['vinculaciones'] ?? [])) {
                $query->whereExists(function ($sub) use ($vinculaciones): void {
                    $sub->selectRaw('1')->from('seguridad.usuarios_app as ua')
                        ->join('seguridad.usuario_app_vinculaciones as v', 'v.usuario_app_id', '=', 'ua.id')
                        ->whereColumn('ua.usuario_id', 'u.id')->where('ua.activo', true)->where('v.activo', true)
                        ->whereIn('v.tipo', $vinculaciones);
                });
            }
        }

        if ($usarCorreo && ! $usarInterno && ! $usarPush) {
            $query->whereNotNull('u.email');
        }

        if (! ($criterios['todos'] ?? false)) {
            $query->where(function ($q) use ($criterios): void {
                if ($ids = ($criterios['usuarios'] ?? [])) {
                    $q->orWhereIn('u.id', $ids);
                }
                if ($roles = ($criterios['roles'] ?? [])) {
                    $q->orWhereIn('u.usr_tipo', $roles);
                }
                if (($criterios['sedes'] ?? []) || ($criterios['unidades'] ?? []) || ($criterios['carreras_areas'] ?? [])) {
                    $q->orWhereExists(function ($sub) use ($criterios): void {
                        $sub->selectRaw('1')->from('institucional.usuario_contexto as uc')
                            ->join('institucional.contextos as c', 'c.id_contexto', '=', 'uc.id_contexto')
                            ->whereColumn('uc.id_usuario', 'u.id')->where('uc.activo', true)
                            ->when($criterios['sedes'] ?? [], fn ($x, $v) => $x->whereIn('c.id_sede', $v))
                            ->when($criterios['unidades'] ?? [], fn ($x, $v) => $x->whereIn('c.id_unidad', $v))
                            ->when($criterios['carreras_areas'] ?? [], fn ($x, $v) => $x->whereIn('c.id_carrera_area', $v));
                    });
                }
            });
        }

        $internos = $query->get()->map(fn ($u) => (array) $u);
        $externos = $usarCorreo
            ? collect($criterios['externos'] ?? [])->map(fn ($e) => [
                'usuario_id' => null,
                'nombre_destinatario' => $e['nombre'] ?? null,
                'correo_destino' => mb_strtolower(trim($e['correo'])),
                'role' => null,
            ])
            : collect();

        return $internos->concat($externos)
            ->filter(function (array $d) use ($usarInterno, $usarCorreo, $usarPush, $usarApp): bool {
                $internoValido = ($usarInterno || $usarPush || $usarApp) && ! empty($d['usuario_id']);
                $correoValido = $usarCorreo && filter_var($d['correo_destino'] ?? null, FILTER_VALIDATE_EMAIL);

                return $internoValido || $correoValido;
            })
            ->unique(fn (array $d) => $d['usuario_id'] ? 'u:'.$d['usuario_id'] : 'e:'.mb_strtolower((string) $d['correo_destino']))
            ->values();
    }

    public function crear(array $datos, ?int $usuarioId): int
    {
        $canales = $this->normalizarCanales($datos['canales'] ?? []);
        $destinatarios = $this->resolver($datos['criterios'], $canales);
        if ($destinatarios->isEmpty()) {
            throw new RuntimeException('Los criterios seleccionados no encontraron destinatarios para los canales elegidos.');
        }

        $destinatariosCorreo = $destinatarios->filter(fn ($d) => filter_var($d['correo_destino'] ?? null, FILTER_VALIDATE_EMAIL));
        if (! $canales['correo']) {
            $datos['correos_cc'] = [];
            $datos['correos_cco'] = [];
        }
        if ($canales['correo'] && $destinatariosCorreo->count() > 1 && (($datos['correos_cc'] ?? []) || ($datos['correos_cco'] ?? []))) {
            throw new RuntimeException('CC y CCO solo pueden utilizarse cuando el comunicado tiene un destinatario principal.');
        }

        $principales = $destinatariosCorreo->pluck('correo_destino')->map(fn ($v) => mb_strtolower((string) $v));
        $datos['correos_cc'] = collect($datos['correos_cc'] ?? [])->reject(fn ($v) => $principales->contains(mb_strtolower($v)))->values()->all();
        $datos['correos_cco'] = collect($datos['correos_cco'] ?? [])->reject(fn ($v) => $principales->contains(mb_strtolower($v)))->values()->all();

        $plantilla = ! empty($datos['plantilla_id']) ? DB::table('notificaciones.plantillas')->where('id', $datos['plantilla_id'])->where('tipo', 'COMUNICADO')->where('activo', true)->first() : null;
        if (($canales['interno'] || $canales['correo'] || $canales['push']) && ! $plantilla) {
            throw new RuntimeException('La plantilla seleccionada no está disponible.');
        }

        $tituloApp = trim((string) ($datos['publicacion_app']['titulo'] ?? ''));
        $descripcionApp = trim((string) ($datos['publicacion_app']['descripcion'] ?? ''));

        $id = DB::transaction(function () use ($datos, $usuarioId, $destinatarios, $plantilla, $canales, $tituloApp, $descripcionApp): int {
            $id = DB::table('notificaciones.campanias')->insertGetId([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'plantilla_id' => $plantilla?->id,
                'asunto' => $plantilla?->asunto ?? $tituloApp,
                'cuerpo_html' => $plantilla?->cuerpo_html ?? '<p>'.e($descripcionApp).'</p>',
                'cuerpo_texto' => $plantilla?->cuerpo_texto ?? $descripcionApp,
                'titulo_app' => $tituloApp ?: null,
                'descripcion_app' => $descripcionApp ?: null,
                'criterios' => json_encode($datos['criterios']),
                'canal_interno' => $canales['interno'],
                'canal_correo' => $canales['correo'],
                'canal_push' => $canales['push'],
                ...$this->datosPublicacionApp($datos),
                'total_destinatarios' => $destinatarios->count(),
                'correos_cc' => json_encode($datos['correos_cc'] ?? []),
                'correos_cco' => json_encode($datos['correos_cco'] ?? []),
                'estado' => 'BORRADOR',
                'created_by' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($destinatarios as $d) {
                $this->insertarDestinatario($id, $d, $canales);
            }

            return (int) $id;
        });

        $this->auditar('notificaciones', 'CREAR', 'notificaciones.campanias', $id, null, DB::table('notificaciones.campanias')->find($id), 'Comunicado creado (borrador).');

        return $id;
    }

    public function duplicar(int $campaniaId, ?int $usuarioId): int
    {
        $original = DB::table('notificaciones.campanias')->find($campaniaId);
        if (! $original) {
            throw new RuntimeException('El comunicado original no existe.');
        }
        if ($original->estado === 'BORRADOR') {
            throw new RuntimeException('El comunicado seleccionado todavía es un borrador.');
        }

        $canales = [
            'interno' => (bool) $original->canal_interno,
            'correo' => (bool) $original->canal_correo,
            'push' => (bool) $original->canal_push,
            'app' => (bool) ($original->publicar_inicio_app ?? false),
        ];

        $id = DB::transaction(function () use ($original, $usuarioId, $canales): int {
            $id = DB::table('notificaciones.campanias')->insertGetId([
                'nombre' => 'Copia de '.$original->nombre,
                'descripcion' => $original->descripcion,
                'plantilla_id' => $original->plantilla_id,
                'asunto' => $original->asunto,
                'cuerpo_html' => $original->cuerpo_html,
                'cuerpo_texto' => $original->cuerpo_texto,
                'criterios' => $original->criterios,
                'canal_interno' => $canales['interno'],
                'canal_correo' => $canales['correo'],
                'canal_push' => $canales['push'],
                'publicar_inicio_app' => (bool) ($original->publicar_inicio_app ?? false),
                'imagen_inicio_app' => $original->imagen_inicio_app ?? null,
                'imagen_archivo_app' => $original->imagen_archivo_app ?? null,
                'accion_url_app' => $original->accion_url_app ?? null,
                'accion_etiqueta_app' => $original->accion_etiqueta_app ?? null,
                'titulo_app' => $original->titulo_app ?? null,
                'descripcion_app' => $original->descripcion_app ?? null,
                'total_destinatarios' => $original->total_destinatarios,
                'correos_cc' => $original->correos_cc,
                'correos_cco' => $original->correos_cco,
                'estado' => 'BORRADOR',
                'created_by' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $destinatarios = DB::table('notificaciones.campania_destinatarios')->where('campania_id', $original->id)->get();
            foreach ($destinatarios as $d) {
                $this->insertarDestinatario($id, [
                    'usuario_id' => $d->usuario_id,
                    'correo_destino' => $d->correo_destino,
                    'nombre_destinatario' => $d->nombre_destinatario,
                ], $canales, $d->variables);
            }

            return (int) $id;
        });

        $this->auditar('notificaciones', 'CREAR', 'notificaciones.campanias', $id, null, DB::table('notificaciones.campanias')->find($id), "Comunicado duplicado desde #{$original->id}.");

        return $id;
    }

    public function actualizarBorrador(int $campaniaId, array $datos): void
    {
        $campania = DB::table('notificaciones.campanias')->find($campaniaId);
        if (! $campania) {
            throw new RuntimeException('La campaña no existe.');
        }
        if ($campania->estado !== 'BORRADOR') {
            throw new RuntimeException('Solo se pueden editar campañas en borrador.');
        }

        $canales = $this->normalizarCanales($datos['canales'] ?? []);
        $destinatarios = $this->resolver($datos['criterios'], $canales);
        if ($destinatarios->isEmpty()) {
            throw new RuntimeException('Los criterios seleccionados no encontraron destinatarios para los canales elegidos.');
        }

        $destinatariosCorreo = $destinatarios->filter(fn ($d) => filter_var($d['correo_destino'] ?? null, FILTER_VALIDATE_EMAIL));
        if (! $canales['correo']) {
            $datos['correos_cc'] = [];
            $datos['correos_cco'] = [];
        }
        if ($canales['correo'] && $destinatariosCorreo->count() > 1 && (($datos['correos_cc'] ?? []) || ($datos['correos_cco'] ?? []))) {
            throw new RuntimeException('CC y CCO solo pueden utilizarse con un destinatario principal.');
        }

        $plantilla = ! empty($datos['plantilla_id']) ? DB::table('notificaciones.plantillas')->where('id', $datos['plantilla_id'])->where('tipo', 'COMUNICADO')->where('activo', true)->first() : null;
        if (($canales['interno'] || $canales['correo'] || $canales['push']) && ! $plantilla) {
            throw new RuntimeException('La plantilla seleccionada no está disponible.');
        }

        $tituloApp = trim((string) ($datos['publicacion_app']['titulo'] ?? ''));
        $descripcionApp = trim((string) ($datos['publicacion_app']['descripcion'] ?? ''));

        $antes = DB::table('notificaciones.campanias')->where('id', $campaniaId)->first();
        DB::transaction(function () use ($campaniaId, $datos, $destinatarios, $plantilla, $canales, $tituloApp, $descripcionApp): void {
            DB::table('notificaciones.campanias')->where('id', $campaniaId)->update([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'plantilla_id' => $plantilla?->id,
                'asunto' => $plantilla?->asunto ?? $tituloApp,
                'cuerpo_html' => $plantilla?->cuerpo_html ?? '<p>'.e($descripcionApp).'</p>',
                'cuerpo_texto' => $plantilla?->cuerpo_texto ?? $descripcionApp,
                'titulo_app' => $tituloApp ?: null,
                'descripcion_app' => $descripcionApp ?: null,
                'criterios' => json_encode($datos['criterios']),
                'canal_interno' => $canales['interno'],
                'canal_correo' => $canales['correo'],
                'canal_push' => $canales['push'],
                ...$this->datosPublicacionApp($datos),
                'total_destinatarios' => $destinatarios->count(),
                'correos_cc' => json_encode($datos['correos_cc'] ?? []),
                'correos_cco' => json_encode($datos['correos_cco'] ?? []),
                'updated_at' => now(),
            ]);

            DB::table('notificaciones.campania_destinatarios')->where('campania_id', $campaniaId)->delete();
            foreach ($destinatarios as $d) {
                $this->insertarDestinatario($campaniaId, $d, $canales);
            }
        });

        $this->auditar('notificaciones', 'ACTUALIZAR', 'notificaciones.campanias', $campaniaId, $antes, DB::table('notificaciones.campanias')->find($campaniaId), 'Borrador de comunicado actualizado.');
    }

    public function enviar(int $campaniaId, bool $soloErrores = false): void
    {
        $campania = DB::table('notificaciones.campanias')->find($campaniaId);
        if (! $campania) {
            throw new RuntimeException('La campaña no existe.');
        }

        $destinatarios = DB::table('notificaciones.campania_destinatarios')
            ->where('campania_id', $campaniaId)
            ->when($soloErrores, fn ($q) => $q->where('estado', 'ERROR'))
            ->get();

        if ($destinatarios->isEmpty()) {
            throw new RuntimeException('La campaña no tiene envíos pendientes.');
        }

        DB::table('notificaciones.campanias')->where('id', $campaniaId)->update([
            'estado' => 'EN_COLA', 'iniciada_at' => now(), 'finalizada_at' => null, 'updated_at' => now(),
        ]);

        $this->auditar('notificaciones', 'ENVIAR', 'notificaciones.campanias', $campaniaId, null, null, $soloErrores ? 'Reintento de envío del comunicado (solo errores).' : 'Envío del comunicado iniciado.');

        foreach ($destinatarios as $d) {
            $encolado = false;
            $updates = ['updated_at' => now()];

            if ($campania->canal_interno && $d->usuario_id && in_array($d->estado_interno, ['PENDIENTE', 'ERROR'], true)) {
                $updates['estado_interno'] = 'EN_COLA';
                $updates['error_interno'] = null;
                $encolado = true;
            }
            if ($campania->canal_correo && $d->correo_destino && in_array($d->estado_correo, ['PENDIENTE', 'ERROR'], true)) {
                $updates['estado_correo'] = 'EN_COLA';
                $updates['ultimo_error'] = null;
                $encolado = true;
            }
            if ($campania->canal_push && $d->usuario_id && in_array($d->estado_push, ['PENDIENTE', 'ERROR'], true)) {
                $updates['estado_push'] = 'EN_COLA';
                $updates['error_push'] = null;
                $encolado = true;
            }
            if ($campania->publicar_inicio_app && $d->usuario_id && in_array($d->estado_app, ['PENDIENTE', 'ERROR'], true)) {
                $updates['estado_app'] = 'ENVIADA';
                $updates['error_app'] = null;
                $encolado = true;
            }

            if (! $encolado) {
                continue;
            }

            $updates['estado'] = 'EN_COLA';
            DB::table('notificaciones.campania_destinatarios')->where('id', $d->id)->update($updates);

            app(CampaniaEstadoService::class)->actualizarDestinatario((int) $d->id);

            if (($updates['estado_interno'] ?? null) === 'EN_COLA') {
                EnviarInternoCampaniaJob::dispatch((int) $d->id)->onConnection('database')->afterCommit();
            }
            if (($updates['estado_correo'] ?? null) === 'EN_COLA') {
                EnviarDestinatarioCampaniaJob::dispatch((int) $d->id)->onConnection('database')->afterCommit();
            }
            if (($updates['estado_push'] ?? null) === 'EN_COLA') {
                EnviarPushCampaniaJob::dispatch((int) $d->id)->onConnection('database')->afterCommit();
            }
        }
    }

    private function insertarDestinatario(int $campaniaId, array|object $destinatario, array $canales, mixed $variablesOriginales = null): void
    {
        $d = (array) $destinatario;
        $usuarioId = $d['usuario_id'] ?? null;
        $correo = isset($d['correo_destino']) ? mb_strtolower(trim((string) $d['correo_destino'])) : null;
        $correo = filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
        $nombre = $d['nombre_destinatario'] ?? null;
        $variables = $variablesOriginales ?: json_encode([
            'nombre_sistema' => config('app.name'),
            'nombre_destinatario' => $nombre,
            'nombre_usuario' => $nombre,
            'correo_destino' => $correo,
        ]);

        DB::table('notificaciones.campania_destinatarios')->insert([
            'campania_id' => $campaniaId,
            'usuario_id' => $usuarioId,
            'correo_destino' => $correo,
            'nombre_destinatario' => $nombre,
            'variables' => is_string($variables) ? $variables : json_encode($variables),
            'estado' => 'PENDIENTE',
            'estado_interno' => $canales['interno'] && $usuarioId ? 'PENDIENTE' : 'OMITIDO',
            'estado_correo' => $canales['correo'] && $correo ? 'PENDIENTE' : 'OMITIDO',
            'estado_push' => $canales['push'] && $usuarioId ? 'PENDIENTE' : 'OMITIDO',
            'estado_app' => $canales['app'] && $usuarioId ? 'PENDIENTE' : 'OMITIDO',
            'numero_intentos' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizarCanales(array $canales): array
    {
        $normalizados = [
            'interno' => (bool) ($canales['interno'] ?? false),
            'correo' => (bool) ($canales['correo'] ?? false),
            'push' => (bool) ($canales['push'] ?? false),
            'app' => (bool) ($canales['app'] ?? false),
        ];

        if ($normalizados['app'] && ($normalizados['interno'] || $normalizados['correo'] || $normalizados['push'])) {
            throw new RuntimeException('Publicación app no puede combinarse con otros canales.');
        }

        if (! in_array(true, $normalizados, true)) {
            throw new RuntimeException('Selecciona al menos un canal de envío.');
        }

        return $normalizados;
    }

    private function datosPublicacionApp(array $datos): array
    {
        $publicacion = $datos['publicacion_app'] ?? [];
        $visible = (bool) ($datos['canales']['app'] ?? $publicacion['visible'] ?? false);
        $archivo = $visible ? ($publicacion['imagen_archivo'] ?? null) : null;
        $imagen = $archivo ? '/storage/'.$archivo : ($publicacion['imagen_url'] ?? null);

        return [
            'publicar_inicio_app' => $visible,
            'imagen_inicio_app' => $visible ? $imagen : null,
            'imagen_archivo_app' => $archivo,
            'accion_url_app' => $visible ? ($publicacion['accion_url'] ?? null) : null,
            'accion_etiqueta_app' => $visible && ! empty($publicacion['accion_url'])
                ? ($publicacion['accion_etiqueta'] ?? 'Ver más')
                : null,
        ];
    }
}
