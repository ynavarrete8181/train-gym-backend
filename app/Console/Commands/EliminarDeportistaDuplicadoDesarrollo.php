<?php

namespace App\Console\Commands;

use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EliminarDeportistaDuplicadoDesarrollo extends Command
{
    protected $signature = 'revive:depurar-deportista-duplicado {cliente_id : ID del perfil inactivo a depurar} {--confirmar : Ejecutar tras revisar la simulación}';
    protected $description = 'Depura exclusivamente en local/testing un perfil inactivo sin dependencias y con otro perfil del mismo documento.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Prohibido fuera de local/testing.');
            return self::FAILURE;
        }

        $id = (int) $this->argument('cliente_id');
        if ($id < 1) {
            $this->error('Identificador inválido.');
            return self::FAILURE;
        }

        try {
            $perfil = DB::table('clientes.deportistas as d')
                ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
                ->where('d.id', $id)
                ->first(['d.id', 'd.persona_id', 'd.usuario_id', 'd.estado', 'd.codigo_deportista', 'p.identificacion']);

            if (! $perfil || $perfil->estado !== 'INACTIVO') {
                $this->error('El perfil indicado debe existir y estar INACTIVO.');
                return self::FAILURE;
            }

            $documento = trim((string) $perfil->identificacion);
            if ($documento === '') {
                $this->error('El perfil no tiene identificación verificable.');
                return self::FAILURE;
            }

            $principal = DB::table('clientes.deportistas as d')
                ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
                ->where('d.id', '<>', $id)
                ->where('d.estado', 'ACTIVO')
                ->whereRaw('LOWER(TRIM(p.identificacion)) = LOWER(TRIM(?))', [$documento])
                ->get(['d.id', 'd.persona_id']);

            if ($principal->count() !== 1) {
                $this->error('Se requiere exactamente un perfil ACTIVO con la misma identificación.');
                return self::FAILURE;
            }

            $usuario = $perfil->usuario_id === null ? null
                : DB::table('seguridad.users')->where('id', $perfil->usuario_id)->first(['id', 'persona_id']);

            if ($perfil->usuario_id !== null && (! $usuario || (int) $usuario->persona_id !== (int) $perfil->persona_id)) {
                $this->error('La cuenta está ausente o vinculada a otra persona. Requiere revisión manual.');
                return self::FAILURE;
            }

            $referenciasUsuario = $usuario ? $this->referencias('seguridad', 'users', (int) $usuario->id) : [];

            $referenciasCliente = $this->referencias('clientes', 'deportistas', $id);
            $referenciasPersona = $this->referencias('personas', 'personas', (int) $perfil->persona_id);
            $this->line('Inactivo: cliente #'.$id.' / persona #'.$perfil->persona_id);
            $this->line('Activo a conservar: cliente #'.$principal->first()->id.' / persona #'.$principal->first()->persona_id);
            $this->line('Usuario vinculado: '.($usuario ? '#'.$usuario->id : 'ninguno'));
            $this->line('Referencias al usuario: '.json_encode($referenciasUsuario));
            $this->line('Referencias al cliente: '.json_encode($referenciasCliente));
            $this->line('Referencias a la persona: '.json_encode($referenciasPersona));

            if ($referenciasCliente || $referenciasPersona || $referenciasUsuario) {
                $this->warn('Hay dependencias. No se elimina ni fusiona ningún dato.');
                return self::FAILURE;
            }
            if (! $this->option('confirmar')) {
                $this->info('Simulación completada. Si los IDs son correctos, repetir con --confirmar.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($perfil, $id, $principal, $usuario): void {
                // Revalidar las referencias bajo bloqueo, sin deshabilitar claves foráneas.
                DB::table('clientes.deportistas')->where('id', $id)->lockForUpdate()->first();
                DB::table('personas.personas')->where('id', $perfil->persona_id)->lockForUpdate()->first();
                if ($this->referencias('clientes', 'deportistas', $id)
                    || $this->referencias('personas', 'personas', (int) $perfil->persona_id)
                    || ($usuario && $this->referencias('seguridad', 'users', (int) $usuario->id))) {
                    throw new \RuntimeException('Las dependencias cambiaron. Operación cancelada.');
                }
                DB::table('clientes.deportistas')->where('id', $id)->where('estado', 'INACTIVO')->delete();
                if ($usuario) {
                    DB::table('seguridad.users')->where('id', $usuario->id)->where('persona_id', $perfil->persona_id)->delete();
                }
                DB::table('personas.personas')->where('id', $perfil->persona_id)->delete();
            });

            app(AuditoriaServicio::class)->registrar([
                'modulo' => 'clientes', 'accion' => 'ELIMINAR',
                'tabla' => 'clientes.deportistas', 'registro_id' => $id,
                'descripcion' => 'Depuración de duplicado inactivo en desarrollo. Activo conservado #'.$principal->first()->id,
                'datos_antes' => ['cliente_id' => $id, 'persona_id' => $perfil->persona_id, 'usuario_id' => $usuario?->id, 'estado' => 'INACTIVO'],
            ]);
            $this->info('Perfil duplicado sin referencias eliminado. Registro activo conservado.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Depuración de deportista duplicado fallida', ['cliente_id' => $id, 'exception' => $e]);
            $this->error('Operación cancelada: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function referencias(string $schema, string $tabla, int $id): array
    {
        $fks = DB::select(<<<'SQL'
SELECT ns.nspname AS esquema, cl.relname AS tabla, att.attname AS columna
FROM pg_constraint c
JOIN pg_class target ON target.oid = c.confrelid
JOIN pg_namespace target_ns ON target_ns.oid = target.relnamespace
JOIN pg_class cl ON cl.oid = c.conrelid
JOIN pg_namespace ns ON ns.oid = cl.relnamespace
JOIN pg_attribute att ON att.attrelid = cl.oid AND att.attnum = c.conkey[1]
WHERE c.contype = 'f' AND target_ns.nspname = ? AND target.relname = ?
AND array_length(c.conkey, 1) = 1
SQL, [$schema, $tabla]);

        $resultados = [];
        foreach ($fks as $fk) {
            if ($schema === 'seguridad' && $tabla === 'users'
                && $fk->esquema === 'clientes' && $fk->tabla === 'deportistas' && $fk->columna === 'usuario_id') {
                $count = DB::table('clientes.deportistas')->where('usuario_id', $id)->count();
                if ($count === 1) {
                    continue;
                }
            }

            if ($schema === 'personas' && $tabla === 'personas'
                && $fk->esquema === 'clientes' && $fk->tabla === 'deportistas' && $fk->columna === 'persona_id') {
                // El perfil duplicado será eliminado en la misma transacción.
                $cantidad = DB::table('clientes.deportistas')->where('persona_id', $id)->count();
                if ($cantidad <= 1) {
                    continue;
                }
            }
            if ($schema === 'personas' && $tabla === 'personas'
                && $fk->esquema === 'seguridad' && $fk->tabla === 'users' && $fk->columna === 'persona_id') {
                $usuarios = DB::table('seguridad.users')->where('persona_id', $id)->get(['id']);
                $vinculado = DB::table('clientes.deportistas')->where('persona_id', $id)->value('usuario_id');
                if ($usuarios->count() === 1 && $vinculado !== null && (int) $usuarios->first()->id === (int) $vinculado) {
                    continue;
                }
            }

            $relation = DB::getQueryGrammar()->wrapTable($fk->esquema.'.'.$fk->tabla);
            $column = DB::getQueryGrammar()->wrap($fk->columna);
            $count = DB::table(DB::raw($relation))->whereRaw($column.' = ?', [$id])->count();
            if ($count > 0) {
                $resultados[] = ['tabla' => $fk->esquema.'.'.$fk->tabla, 'columna' => $fk->columna, 'total' => $count];
            }
        }

        return $resultados;
    }
}
