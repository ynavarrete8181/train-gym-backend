<?php

namespace App\Services\Notificaciones;

use App\Jobs\Notificaciones\EnviarNotificacionUsuarioJob;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class NotificacionUsuarioService
{
    public function registrarPendiente(User $usuario, ?int $solicitadoPor = null): int
    {
        $existente = DB::table('notificaciones.notificaciones')
            ->where('usuario_id', $usuario->id)
            ->where('tipo', 'INVITACION_USUARIO')
            ->where('estado', 'PENDIENTE')
            ->latest('id')->value('id');
        if ($existente) {
            return (int) $existente;
        }

        $plantilla = $this->plantillaAcceso();
        if (! $plantilla) {
            throw new \RuntimeException('No existe una plantilla activa de invitación.');
        }

        return (int) DB::table('notificaciones.notificaciones')->insertGetId([
            'usuario_id' => $usuario->id, 'plantilla_id' => $plantilla->id, 'tipo' => 'INVITACION_USUARIO',
            'estado' => 'PENDIENTE', 'correo_destino' => $usuario->email, 'solicitado_por' => $solicitadoPor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        return $this->consultaListado($filtros)
            ->select('u.id', 'u.name', 'u.email', 'u.cedula', 'r.role', DB::raw("COALESCE(n.estado, 'PENDIENTE') as estado_notificacion"), 'n.id as notificacion_id', 'n.enviado_at')
            ->orderBy('u.name')
            ->paginate(min(100, max(5, (int) ($filtros['per_page'] ?? 10))));
    }

    public function idsFiltrados(array $filtros, int $limite = 500): array
    {
        $consulta = $this->consultaListado($filtros)->orderBy('u.name');
        $total = (clone $consulta)->count('u.id');
        $ids = $consulta->limit($limite)->pluck('u.id')->map(fn ($id) => (int) $id)->all();

        return [
            'usuarios' => $ids,
            'total' => $total,
            'limite' => $limite,
            'limitado' => $total > $limite,
        ];
    }

    public function solicitar(User $usuario, ?int $solicitadoPor = null, bool $forzar = false): int
    {
        if (! filter_var($usuario->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('El usuario no tiene un correo válido.');
        }
        $pendiente = DB::table('notificaciones.notificaciones')->where('usuario_id', $usuario->id)->whereIn('estado', ['EN_COLA', 'PROCESANDO'])->exists();
        if ($pendiente && ! $forzar) {
            throw new \RuntimeException('El usuario ya tiene una notificación en proceso.');
        }
        $plantilla = $this->plantillaAcceso();
        if (! $plantilla) {
            throw new \RuntimeException('No existe una plantilla activa de invitación.');
        }
        $id = DB::table('notificaciones.notificaciones')->where('usuario_id', $usuario->id)->where('tipo', 'INVITACION_USUARIO')->where('estado', 'PENDIENTE')->latest('id')->value('id');
        if ($id) {
            DB::table('notificaciones.notificaciones')->where('id', $id)->update(['plantilla_id' => $plantilla->id, 'estado' => 'EN_COLA', 'correo_destino' => $usuario->email, 'solicitado_por' => $solicitadoPor, 'encolado_at' => now(), 'updated_at' => now()]);
        } else {
            $id = DB::table('notificaciones.notificaciones')->insertGetId(['usuario_id' => $usuario->id, 'plantilla_id' => $plantilla->id, 'tipo' => 'INVITACION_USUARIO', 'estado' => 'EN_COLA', 'correo_destino' => $usuario->email, 'solicitado_por' => $solicitadoPor, 'encolado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        EnviarNotificacionUsuarioJob::dispatch($id)->onConnection('database')->afterCommit();

        return $id;
    }

    public function solicitarRestablecimiento(User $usuario, ?int $solicitadoPor = null): int
    {
        if (! filter_var($usuario->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('El usuario no tiene un correo válido.');
        }
        $plantilla = $this->plantillaEvento('USUARIO_RESTABLECER_CLAVE');
        if (! $plantilla) {
            throw new \RuntimeException('No existe una plantilla activa de restablecimiento de contraseña.');
        }
        DB::table('notificaciones.tokens_activacion')->where('usuario_id', $usuario->id)->whereNull('usado_at')->update(['invalidado_at' => now(), 'updated_at' => now()]);
        DB::table('seguridad.tokens_acceso')->where('id_usuario', $usuario->id)->delete();
        $id = DB::table('notificaciones.notificaciones')->insertGetId(['usuario_id' => $usuario->id, 'plantilla_id' => $plantilla->id, 'tipo' => 'RESTABLECIMIENTO_CLAVE', 'estado' => 'EN_COLA', 'correo_destino' => $usuario->email, 'solicitado_por' => $solicitadoPor, 'encolado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        EnviarNotificacionUsuarioJob::dispatch($id)->onConnection('database')->afterCommit();

        return (int) $id;
    }

    private function consultaListado(array $filtros): Builder
    {
        return DB::table('seguridad.users as u')->leftJoin('notificaciones.notificaciones as n', function ($join) {
            $join->on('n.usuario_id', '=', 'u.id')->whereRaw('n.id = (SELECT MAX(n2.id) FROM notificaciones.notificaciones n2 WHERE n2.usuario_id = u.id)');
        })->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->when($filtros['busqueda'] ?? null, fn ($q, $v) => $q->where(fn ($s) => $s->where('u.name', 'ilike', "%{$v}%")->orWhere('u.email', 'ilike', "%{$v}%")->orWhere('u.cedula', 'ilike', "%{$v}%")))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->whereRaw("COALESCE(n.estado, 'PENDIENTE') = ?", [$v]));
    }

    private function plantillaAcceso(): ?object
    {
        return $this->plantillaEvento('USUARIO_CREADO');
    }

    private function plantillaEvento(string $evento): ?object
    {
        return DB::table('notificaciones.plantillas as p')
            ->join('notificaciones.evento_plantilla as ep', 'ep.plantilla_id', '=', 'p.id')
            ->join('notificaciones.eventos as e', 'e.id', '=', 'ep.evento_id')
            ->where('e.codigo', $evento)->where('e.activo', true)
            ->where('ep.activo', true)->where('ep.predeterminada', true)->where('p.activo', true)
            ->select('p.*')->first();
    }
}
