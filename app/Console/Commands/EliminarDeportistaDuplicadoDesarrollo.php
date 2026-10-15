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
            $referenciasCliente = $this->referencias('clientes', 'deportistas', $id);
            $referenciasPersona = $this->referencias('personas', 'personas', (int) $perfil->persona_id);
            $referenciasVenta = $venta ? $this->referencias('ventas', 'ventas', (int) $venta->id) : [];
            $referenciasMembresia = $membresia ? $this->referencias('membresias', 'membresias', (int) $membresia->id) : [];

            $previstos = [
                'cuentas_cobrar.cuentas' => ['venta_id', 'ABIERTA', 'estado'],
                'ventas.venta_detalles' => ['venta_id', null, null],
                'ventas.comprobantes' => ['venta_id', 'BORRADOR', 'estado'],
                'membresias.membresia_periodos' => ['venta_id', 'PENDIENTE_PAGO', 'estado'],
                'membresias.membresia_sedes' => ['membresia_id', null, null],
            ];
            $idsHijos = [];
            $borradoPrueba = $venta && $membresia
                && (int) $id === 3 && (int) $perfil->persona_id === 6
                && (int) $principal->first()->id === 45 && (int) $principal->first()->persona_id === 48
                && (int) $venta->id === 2 && (int) $membresia->id === 2
                && DB::table('ventas.ventas')->where('cliente_id', $id)->count() === 1
                && DB::table('membresias.membresias')->where('deportista_id', $id)->count() === 1
                && (int) $venta->membresia_id === (int) $membresia->id
                && $venta->estado === 'PENDIENTE'
                && $membresia->estado === 'PENDIENTE_PAGO'
                && $venta->caja_id === null && $venta->turno_caja_id === null
                && $venta->inventario_aplicado_at === null
                && count($referenciasCliente) === 2
                && $this->soloReferencias($referenciasCliente, [
                    'ventas.ventas:cliente_id' => 1,
                    'membresias.membresias:deportista_id' => 1,
                ])
                && $this->soloReferencias($referenciasVenta, [
                    'cuentas_cobrar.cuentas:venta_id' => 1,
                    'ventas.venta_detalles:venta_id' => 1,
                    'ventas.comprobantes:venta_id' => 1,
                    'membresias.membresia_periodos:venta_id' => 1,
                ])
                && $this->soloReferencias($referenciasMembresia, [
                    'ventas.ventas:membresia_id' => 1,
                    'membresias.membresia_sedes:membresia_id' => 1,
                    'membresias.membresia_periodos:membresia_id' => 1,
                ]);
            if ($borradoPrueba) {
                foreach ($previstos as $tabla => [$columna, $estado, $campo]) {
                    $padre = $columna === 'venta_id' ? (int) $venta->id : (int) $membresia->id;
                    $filas = DB::table($tabla)->where($columna, $padre)->get();
                    if ($filas->count() !== 1 || ($estado !== null && $filas->first()->{$campo} !== $estado)) {
                        $borradoPrueba = false;
                        break;
                    }
                    $hijo = $filas->first();
                    $idsHijos[$tabla] = (int) $hijo->id;
                    if ($this->referencias(explode('.', $tabla)[0], explode('.', $tabla)[1], (int) $hijo->id)) {
                        $borradoPrueba = false;
                        break;
                    }
                    if ($tabla === 'ventas.comprobantes' &&
                        ($hijo->pago_id !== null || $hijo->emitido_at !== null || $hijo->enviado_at !== null || (int) $hijo->cantidad_envios !== 0)) {
                        $borradoPrueba = false;
                    }
                }
                $periodo = DB::table('membresias.membresia_periodos')->where('id', $idsHijos['membresias.membresia_periodos'] ?? 0)->first();
                if (! $periodo || (int) $periodo->membresia_id !== (int) $membresia->id) {
                    $borradoPrueba = false;
                }
            }

            $this->line('Inactivo: cliente #'.$id.' / persona #'.$perfil->persona_id);
            $this->line('Activo a conservar: cliente #'.$principal->first()->id.' / persona #'.$principal->first()->persona_id);
            $this->line('Referencias al cliente: '.json_encode($referenciasCliente));
            $this->line('Referencias a venta: '.json_encode($referenciasVenta));
            $this->line('Referencias a membresía: '.json_encode($referenciasMembresia));
            $this->line('Referencias al usuario: '.json_encode($referenciasUsuario));
            $this->line('Referencias a la persona: '.json_encode($referenciasPersona));
            $this->line('Depuración de prueba autorizable: '.($borradoPrueba ? 'SÍ' : 'NO'));

            if (! $borradoPrueba || $referenciasPersona || $referenciasUsuario) {
                $this->warn('Existen dependencias o estados no permitidos. No se elimina ningún dato.');
                return self::FAILURE;
            }
            if (! $this->option('confirmar')) {
                $this->info('Simulación completada. No se ha eliminado ningún registro.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($perfil, $id, $principal, $usuario, $venta, $membresia, $idsHijos, $previstos): void {
                DB::table('clientes.deportistas')->where('id', $id)->lockForUpdate()->first();
                DB::table('ventas.ventas')->where('id', $venta->id)->lockForUpdate()->first();
                DB::table('membresias.membresias')->where('id', $membresia->id)->lockForUpdate()->first();
                foreach ($idsHijos as $tabla => $hijoId) {
                    DB::table($tabla)->where('id', $hijoId)->lockForUpdate()->first();
                    if ($this->referencias(explode('.', $tabla)[0], explode('.', $tabla)[1], $hijoId)) {
                        throw new \RuntimeException('El registro asociado tiene nuevas dependencias: '.$tabla);
                    }
                    [$columna, $estado, $campo] = $previstos[$tabla];
                    $padre = $columna === 'venta_id' ? $venta->id : $membresia->id;
                    $query = DB::table($tabla)->where('id', $hijoId)->where($columna, $padre);
                    if ($estado !== null) {
                        $query->where($campo, $estado);
                    }
                    if ($tabla === 'ventas.comprobantes') {
                        $query->whereNull('pago_id')->whereNull('emitido_at')->whereNull('enviado_at')->where('cantidad_envios', 0);
                    }
                    if ($query->count() !== 1) {
                        throw new \RuntimeException('Cambió el registro relacionado: '.$tabla);
                    }
                }
                if ($this->referencias('personas', 'personas', (int) $perfil->persona_id)
                    || ($usuario && $this->referencias('seguridad', 'users', (int) $usuario->id))
                    || ! $this->soloReferencias($this->referencias('ventas', 'ventas', (int) $venta->id), [
                        'cuentas_cobrar.cuentas:venta_id' => 1, 'ventas.venta_detalles:venta_id' => 1,
                        'ventas.comprobantes:venta_id' => 1, 'membresias.membresia_periodos:venta_id' => 1,
                    ])
                    || ! $this->soloReferencias($this->referencias('membresias', 'membresias', (int) $membresia->id), [
                        'ventas.ventas:membresia_id' => 1, 'membresias.membresia_sedes:membresia_id' => 1,
                        'membresias.membresia_periodos:membresia_id' => 1,
                    ])) {
                    throw new \RuntimeException('Las dependencias cambiaron. Operación cancelada.');
                }
                foreach ($idsHijos as $tabla => $hijoId) {
                    DB::table($tabla)->where('id', $hijoId)->delete();
                }
                DB::table('ventas.ventas')->where('id', $venta->id)->where('estado', 'PENDIENTE')->delete();
                DB::table('membresias.membresias')->where('id', $membresia->id)->where('estado', 'PENDIENTE_PAGO')->delete();
                if ($this->referencias('clientes', 'deportistas', $id)) {
                    throw new \RuntimeException('El cliente tiene nuevas dependencias.');
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
                'datos_antes' => ['cliente_id' => $id, 'persona_id' => $perfil->persona_id, 'usuario_id' => $usuario?->id, 'venta_id' => $venta->id, 'membresia_id' => $membresia->id, 'estado' => 'INACTIVO'],
            ]);
            $this->info('Perfil duplicado sin referencias eliminado. Registro activo conservado.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Depuración de deportista duplicado fallida', ['cliente_id' => $id, 'exception' => $e]);
            $this->error('Operación cancelada: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function soloReferencias(array $actuales, array $esperadas): bool
    {
        if (count($actuales) !== count($esperadas)) {
            return false;
        }
        foreach ($actuales as $item) {
            $clave = $item['tabla'].':'.$item['columna'];
            if (! isset($esperadas[$clave]) || (int) $item['total'] !== $esperadas[$clave]) {
                return false;
            }
        }
        return true;
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
