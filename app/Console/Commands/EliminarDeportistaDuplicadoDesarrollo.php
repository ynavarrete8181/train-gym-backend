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

            $venta = DB::table('ventas.ventas')->where('cliente_id', $id)->first();
            $membresia = DB::table('membresias.membresias')->where('deportista_id', $id)->first();
            $borradoPrueba = false;
            if ($venta && $membresia) {
                $borradoPrueba = DB::table('ventas.ventas')->where('cliente_id', $id)->count() === 1
                    && DB::table('membresias.membresias')->where('deportista_id', $id)->count() === 1
                    && (int) ($venta->membresia_id ?? 0) === (int) $membresia->id
                    && $venta->estado === 'PENDIENTE'
                    && $membresia->estado === 'PENDIENTE_PAGO'
                    && $venta->caja_id === null
                    && $venta->turno_caja_id === null
                    && $venta->inventario_aplicado_at === null
                    && $this->referencias('ventas', 'ventas', (int) $venta->id) === []
                    && $this->referencias('membresias', 'membresias', (int) $membresia->id, [
                        'ventas.ventas:membresia_id' => (int) $venta->id,
                    ]) === [];
            }

            if ($venta && $membresia) {
                $this->line('Referencias a venta #'.$venta->id.': '.json_encode($this->referencias('ventas', 'ventas', (int) $venta->id)));
                $this->line('Referencias a membresía #'.$membresia->id.': '.json_encode($this->referencias('membresias', 'membresias', (int) $membresia->id)));
            }

            $referenciasCliente = $this->referencias('clientes', 'deportistas', $id);
            $referenciasPersona = $this->referencias('personas', 'personas', (int) $perfil->persona_id);
            $this->line('Inactivo: cliente #'.$id.' / persona #'.$perfil->persona_id);
            $this->line('Activo a conservar: cliente #'.$principal->first()->id.' / persona #'.$principal->first()->persona_id);
            $this->line('Usuario vinculado: '.($usuario ? '#'.$usuario->id : 'ninguno'));
            $this->line('Venta y membresía pendientes sin referencias adicionales: '.($borradoPrueba ? 'SÍ' : 'NO'));
            $this->line('Referencias al usuario: '.json_encode($referenciasUsuario));
            $this->line('Referencias al cliente: '.json_encode($referenciasCliente));
            $this->line('Referencias a la persona: '.json_encode($referenciasPersona));

            $soloReferenciasPrueba = $borradoPrueba
                && count($referenciasCliente) === 2
                && collect($referenciasCliente)->contains(fn ($r) => $r['tabla'] === 'ventas.ventas' && $r['columna'] === 'cliente_id' && $r['total'] === 1)
                && collect($referenciasCliente)->contains(fn ($r) => $r['tabla'] === 'membresias.membresias' && $r['columna'] === 'deportista_id' && $r['total'] === 1);
            if (($referenciasCliente && ! $soloReferenciasPrueba) || $referenciasPersona || $referenciasUsuario) {
                $this->warn('Hay dependencias. No se elimina ni fusiona ningún dato.');
                return self::FAILURE;
            }
            if (! $this->option('confirmar')) {
                $this->info('Simulación completada. Si los IDs son correctos, repetir con --confirmar.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($perfil, $id, $principal, $usuario, $venta, $membresia, $soloReferenciasPrueba): void {
                // Revalidar las referencias bajo bloqueo, sin deshabilitar claves foráneas.
                DB::table('clientes.deportistas')->where('id', $id)->lockForUpdate()->first();
                DB::table('personas.personas')->where('id', $perfil->persona_id)->lockForUpdate()->first();
                if ((! $soloReferenciasPrueba && $this->referencias('clientes', 'deportistas', $id))
                    || ($soloReferenciasPrueba && ($this->referencias('ventas', 'ventas', (int) $venta->id)
                        || $this->referencias('membresias', 'membresias', (int) $membresia->id, ['ventas.ventas:membresia_id' => (int) $venta->id])
                        || DB::table('ventas.ventas')->where('id', $venta->id)->where('estado', 'PENDIENTE')->whereNull('caja_id')->whereNull('turno_caja_id')->count() !== 1
                        || DB::table('membresias.membresias')->where('id', $membresia->id)->where('estado', 'PENDIENTE_PAGO')->count() !== 1))
                    || $this->referencias('personas', 'personas', (int) $perfil->persona_id)
                    || ($usuario && $this->referencias('seguridad', 'users', (int) $usuario->id))) {
                    throw new \RuntimeException('Las dependencias cambiaron. Operación cancelada.');
                }
                if ($soloReferenciasPrueba) {
                    DB::table('ventas.ventas')->where('id', $venta->id)->delete();
                    DB::table('membresias.membresias')->where('id', $membresia->id)->delete();
                    if ($this->referencias('clientes', 'deportistas', $id)) {
                        throw new \RuntimeException('Se detectaron nuevas dependencias del cliente.');
                    }
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
                'datos_antes' => ['cliente_id' => $id, 'persona_id' => $perfil->persona_id, 'usuario_id' => $usuario?->id, 'venta_id' => $soloReferenciasPrueba ? $venta->id : null, 'membresia_id' => $soloReferenciasPrueba ? $membresia->id : null, 'estado' => 'INACTIVO'],
            ]);
            $this->info('Perfil duplicado sin referencias eliminado. Registro activo conservado.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Depuración de deportista duplicado fallida', ['cliente_id' => $id, 'exception' => $e]);
            $this->error('Operación cancelada: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function referencias(string $schema, string $tabla, int $id, array $excluir = []): array
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
            $consulta = DB::table(DB::raw($relation))->whereRaw($column.' = ?', [$id]);
            $clave = $fk->esquema.'.'.$fk->tabla.':'.$fk->columna;
            if (isset($excluir[$clave])) {
                $consulta->where('id', '<>', $excluir[$clave]);
            }
            $count = $consulta->count();
            if ($count > 0) {
                $resultados[] = ['tabla' => $fk->esquema.'.'.$fk->tabla, 'columna' => $fk->columna, 'total' => $count];
            }
        }

        return $resultados;
    }
}
