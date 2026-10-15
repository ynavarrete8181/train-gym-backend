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

            if ($perfil->usuario_id !== null) {
                $this->error('Perfil inactivo vinculado a usuario: requiere revisión manual.');
                return self::FAILURE;
            }

            $referenciasCliente = $this->referencias('clientes', 'deportistas', $id);
            $referenciasPersona = $this->referencias('personas', 'personas', (int) $perfil->persona_id);
            $this->line('Inactivo: cliente #'.$id.' / persona #'.$perfil->persona_id);
            $this->line('Activo a conservar: cliente #'.$principal->first()->id.' / persona #'.$principal->first()->persona_id);
            $this->line('Referencias al cliente: '.json_encode($referenciasCliente));
            $this->line('Referencias a la persona: '.json_encode($referenciasPersona));

            if ($referenciasCliente || $referenciasPersona) {
                $this->warn('Hay dependencias. No se elimina ni fusiona ningún dato.');
                return self::FAILURE;
            }
            if (! $this->option('confirmar')) {
                $this->info('Simulación completada. Si los IDs son correctos, repetir con --confirmar.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($perfil, $id, $principal): void {
                // Revalidar las referencias bajo bloqueo, sin deshabilitar claves foráneas.
                DB::table('clientes.deportistas')->where('id', $id)->lockForUpdate()->first();
                DB::table('personas.personas')->where('id', $perfil->persona_id)->lockForUpdate()->first();
                if ($this->referencias('clientes', 'deportistas', $id) || $this->referencias('personas', 'personas', (int) $perfil->persona_id)) {
                    throw new \RuntimeException('Las dependencias cambiaron. Operación cancelada.');
                }
                DB::table('clientes.deportistas')->where('id', $id)->where('estado', 'INACTIVO')->delete();
                DB::table('personas.personas')->where('id', $perfil->persona_id)->delete();
            });

            app(AuditoriaServicio::class)->registrar([
                'modulo' => 'clientes', 'accion' => 'ELIMINAR',
                'tabla' => 'clientes.deportistas', 'registro_id' => $id,
                'descripcion' => 'Depuración de duplicado inactivo en desarrollo. Activo conservado #'.$principal->first()->id,
                'datos_antes' => ['cliente_id' => $id, 'persona_id' => $perfil->persona_id, 'estado' => 'INACTIVO'],
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
            if ($schema === 'personas' && $tabla === 'personas'
                && $fk->esquema === 'clientes' && $fk->tabla === 'deportistas' && $fk->columna === 'persona_id') {
                // El perfil duplicado será eliminado en la misma transacción.
                $cantidad = DB::table('clientes.deportistas')->where('persona_id', $id)->count();
                if ($cantidad <= 1) {
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
