<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Por pedido de la administración (25/11/2026) se eliminan los clientes inactivos con todo su flujo:
 * los 16 deportistas en estado INACTIVO (incluida Thalia Cedeño, con su contrato congelado y su venta
 * anulada), Carlos Zambrano (SUSPENDIDO) y su tutora Adriana Azua, que solo los representaba a él y a
 * Mateo. Se conservan Cecilia Saltos (prospecto con pago) y el tutor Carlitos Montesdeoca (tiene hijos
 * activos).
 *
 * Resguardos: cada id debe coincidir con su nombre y estado esperados; no se elimina nada si alguna
 * venta tiene pagos confirmados, si un tutor representa a alguien más o si otra tabla los usa. Copia
 * completa de lo eliminado en auditoria.eventos. Respaldo de producción tomado antes.
 */
return new class extends Migration
{
    /** id del deportista => [nombre, estado esperado] */
    private const DEPORTISTAS = [
        6 => ['Paul Palma Perez', 'INACTIVO'], 8 => ['Carlos Zambrano', 'SUSPENDIDO'], 9 => ['Mateo Zambrano', 'INACTIVO'],
        13 => ['Mirna San Lucas', 'INACTIVO'], 17 => ['Xavier Meza', 'INACTIVO'], 18 => ['Saul Garcia', 'INACTIVO'],
        19 => ['Kevin Vera', 'INACTIVO'], 21 => ['Thalia Cedeño', 'INACTIVO'], 24 => ['Auro Fernandez', 'INACTIVO'],
        27 => ['Melba Lozada', 'INACTIVO'], 28 => ['Abraham Palma', 'INACTIVO'], 31 => ['Jacob Cedeño', 'INACTIVO'],
        32 => ['Alejandra Oviedo', 'INACTIVO'], 34 => ['Maria Fernanda Andrade', 'INACTIVO'], 35 => ['Maybeline Chamba', 'INACTIVO'],
        37 => ['Klever Loor', 'INACTIVO'], 48 => ['Juan Teran', 'INACTIVO'],
    ];
    /** Tutores a eliminar si no representan a nadie más: persona_id => nombre */
    private const TUTORES = [50 => 'Adriana Azua'];

    public function up(): void
    {
        $filas = DB::table('clientes.deportistas as d')->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->whereIn('d.id', array_keys(self::DEPORTISTAS))
            ->get(['d.id', 'd.estado', 'd.persona_id', 'd.usuario_id', 'p.nombre_completo']);
        $ids = [];
        foreach ($filas as $f) {
            [$nombre, $estado] = self::DEPORTISTAS[(int) $f->id];
            if (trim($f->nombre_completo) === $nombre && $f->estado === $estado && $f->usuario_id === null) {
                $ids[] = (int) $f->id;
            } else {
                Log::warning("Eliminar inactivos: se omite el deportista {$f->id} ({$f->nombre_completo}, {$f->estado}): no coincide.");
            }
        }
        if (! $ids) {
            Log::warning('Eliminar inactivos: ningún deportista coincide; no se elimina nada.');
            return;
        }
        $personas = $filas->whereIn('id', $ids)->pluck('persona_id')->map(fn ($v) => (int) $v)->all();

        $tutores = [];
        foreach (self::TUTORES as $personaId => $nombre) {
            $persona = DB::table('personas.personas')->where('id', $personaId)->first(['nombre_completo']);
            $otros = DB::table('clientes.deportista_representantes')->where('representante_persona_id', $personaId)
                ->whereNotIn('deportista_id', $ids)->exists();
            $libre = ! $otros
                && ! DB::table('clientes.deportistas')->where('persona_id', $personaId)->exists()
                && ! DB::table('seguridad.users')->where('persona_id', $personaId)->exists()
                && ! DB::table('ventas.pagos')->where('pagador_persona_id', $personaId)->exists();
            if ($persona && trim($persona->nombre_completo) === $nombre && $libre) {
                $tutores[] = $personaId;
            } else {
                Log::warning("Eliminar inactivos: se conserva al tutor {$nombre} (representa a otros o no coincide).");
            }
        }
        $personasEliminar = [...$personas, ...$tutores];

        $membresias = DB::table('membresias.membresias')->whereIn('deportista_id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $ventas = DB::table('ventas.ventas')
            ->where(fn ($q) => $q->whereIn('cliente_id', $ids)->orWhereIn('membresia_id', $membresias ?: [0]))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (DB::table('ventas.pagos')->whereIn('venta_id', $ventas ?: [0])->exists()) {
            Log::warning('Eliminar inactivos: hay pagos en sus ventas; no se elimina nada.');
            return;
        }

        $en = fn (string $tabla, string $col, array $valores) => $valores
            ? DB::table($tabla)->whereIn($col, $valores)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
        $cuentas = $en('cuentas_cobrar.cuentas', 'venta_id', $ventas);
        $comprobantes = $en('ventas.comprobantes', 'venta_id', $ventas);
        $periodos = $en('membresias.membresia_periodos', 'membresia_id', $membresias);
        $cuotas = $en('membresias.membresia_cuotas', 'membresia_id', $membresias);
        $movimientos = $en('membresias.membresia_movimientos', 'membresia_id', $membresias);
        $descuentos = $en('membresias.descuentos_deportistas', 'deportista_id', $ids);

        $plan = [
            ['cuentas_cobrar.gestiones', 'cuenta_id', $cuentas],
            ['cuentas_cobrar.compromisos_pago', 'cuenta_id', $cuentas],
            ['cuentas_cobrar.cuentas', 'id', $cuentas],
            ['ventas.comprobante_envios', 'comprobante_id', $comprobantes],
            ['ventas.comprobantes', 'id', $comprobantes],
            ['membresias.membresia_reprogramaciones', 'membresia_id', $membresias],
            ['membresias.membresia_reprogramaciones', 'cuota_id', $cuotas],
            ['membresias.membresia_reprogramaciones', 'periodo_id', $periodos],
            ['membresias.membresia_reprogramaciones', 'movimiento_id', $movimientos],
            ['membresias.membresia_suspensiones', 'membresia_id', $membresias],
            ['membresias.membresia_cuotas', 'id', $cuotas],
            ['ventas.venta_detalles', 'venta_id', $ventas],
            ['membresias.membresia_periodos', 'id', $periodos],
            ['ventas.ventas', 'id', $ventas],
            ['membresias.membresia_condiciones_historial', 'membresia_id', $membresias],
            ['membresias.membresia_sedes', 'membresia_id', $membresias],
            ['entrenamiento.asignaciones_entrenador_cliente', 'membresia_id', $membresias],
            ['membresias.membresia_movimientos', 'id', $movimientos],
            ['membresias.descuentos_deportistas', 'id', $descuentos],
            ['membresias.membresias', 'id', $membresias],
            ['entrenamiento.asignaciones_entrenador_cliente', 'cliente_id', $ids],
            ['clientes.deportista_codigos_historicos', 'deportista_id', $ids],
            ['clientes.deportista_representantes', 'deportista_id', $ids],
            ['clientes.deportistas', 'id', $ids],
            ['clientes.tutores', 'persona_id', $tutores],
            ['personas.personas', 'id', $personasEliminar],
        ];

        // Cualquier otra tabla que los use detiene todo: hay que revisarla a mano.
        $cubiertas = collect($plan)->map(fn ($p) => $p[0])->unique()->all();
        $buscar = [
            'cliente_id' => $ids, 'deportista_id' => $ids, 'membresia_id' => $membresias, 'venta_id' => $ventas,
            'persona_id' => $personasEliminar, 'representante_persona_id' => $personasEliminar, 'pagador_persona_id' => $personasEliminar,
        ];
        $columnas = DB::select("select c.table_schema||'.'||c.table_name as t, c.column_name as c from information_schema.columns c
            join information_schema.tables tb on tb.table_schema=c.table_schema and tb.table_name=c.table_name and tb.table_type='BASE TABLE'
            where c.table_schema not in ('pg_catalog','information_schema','auditoria') and c.column_name in ('".implode("','", array_keys($buscar))."')");
        foreach ($columnas as $col) {
            if (in_array($col->t, $cubiertas, true) || ! $buscar[$col->c]) {
                continue;
            }
            if (DB::table($col->t)->whereIn($col->c, $buscar[$col->c])->exists()) {
                Log::warning("Eliminar inactivos: {$col->t}.{$col->c} también los usa; no se elimina nada.");
                return;
            }
        }

        $existe = fn (string $tabla, string $col) => DB::table('information_schema.columns')
            ->where('table_schema', explode('.', $tabla)[0])->where('table_name', explode('.', $tabla)[1])
            ->where('column_name', $col)->exists();
        $copia = [];
        DB::transaction(function () use ($plan, $existe, &$copia): void {
            foreach ($plan as [$tabla, $col, $valores]) {
                if (! $valores || ! $existe($tabla, $col)) {
                    continue;
                }
                $filas = DB::table($tabla)->whereIn($col, $valores)->get();
                if ($filas->isEmpty()) {
                    continue;
                }
                $copia[$tabla] = array_merge($copia[$tabla] ?? [], $filas->all());
                DB::table($tabla)->whereIn($col, $valores)->delete();
            }
            DB::table('auditoria.eventos')->insert([
                'usuario_nombre' => 'Sistema (migración)',
                'modulo' => 'clientes',
                'tabla' => 'clientes.deportistas',
                'registro_id' => 'INACTIVOS-2026-11-25',
                'accion' => 'ELIMINAR',
                'descripcion' => 'Eliminación de clientes inactivos (y Carlos Zambrano, suspendido) con su tutora Adriana Azua, por pedido de la administración. Deportistas: '
                    .implode(', ', array_map(fn ($f) => $f->id, $copia['clientes.deportistas'] ?? [])).'. Copia completa en datos_antes.',
                'datos_antes' => json_encode($copia, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });

        Log::info('Eliminar inactivos: eliminado.', array_map('count', $copia));
    }

    public function down(): void
    {
        // La copia completa queda en auditoria.eventos y en el respaldo previo.
    }
};
