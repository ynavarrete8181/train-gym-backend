<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso 2 de la revisión del 21/11/2026: Ezequiel y Daniela Palma (tutor Jacinto Palma) se
 * retiraron y no deben nada. Por pedido de la administración se elimina todo lo suyo: fichas,
 * personas, contratos con su calendario, descuentos y ventas sin pagos. Su tutor se elimina solo
 * si no representa a nadie más ni tiene ficha o usuario propio.
 *
 * Resguardos: actúa solo si encuentra exactamente a los dos deportistas con ese tutor; no borra
 * nada si alguna de sus ventas tiene pagos; guarda una copia completa de lo eliminado en
 * auditoria.eventos. La auditoría previa no se toca.
 */
return new class extends Migration
{
    private const NOMBRES = ['Ezequiel Palma', 'Daniela Palma'];
    private const TUTOR = 'Jacinto Palma';

    public function up(): void
    {
        $deportistas = DB::table('clientes.deportistas as d')
            ->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->whereIn(DB::raw('TRIM(p.nombre_completo)'), self::NOMBRES)
            ->get(['d.id', 'd.persona_id', 'd.usuario_id', 'p.nombre_completo']);

        if ($deportistas->count() !== 2 || $deportistas->pluck('nombre_completo')->unique()->count() !== 2) {
            Log::warning('Eliminar Palma: no se encontraron exactamente Ezequiel y Daniela Palma; no se elimina nada.');
            return;
        }
        if ($deportistas->whereNotNull('usuario_id')->isNotEmpty()) {
            Log::warning('Eliminar Palma: algún deportista tiene usuario de acceso; no se elimina nada.');
            return;
        }

        $ids = $deportistas->pluck('id')->map(fn ($v) => (int) $v)->all();
        $personas = $deportistas->pluck('persona_id')->map(fn ($v) => (int) $v)->all();

        $tutores = DB::table('clientes.deportista_representantes as r')
            ->join('personas.personas as p', 'p.id', '=', 'r.representante_persona_id')
            ->whereIn('r.deportista_id', $ids)
            ->get(['r.representante_persona_id', 'p.nombre_completo']);
        if ($tutores->pluck('nombre_completo')->map(fn ($n) => trim($n))->unique()->values()->all() !== [self::TUTOR]) {
            Log::warning('Eliminar Palma: el tutor no es solo Jacinto Palma; no se elimina nada.');
            return;
        }
        $tutorId = (int) $tutores->first()->representante_persona_id;

        $membresias = DB::table('membresias.membresias')->whereIn('deportista_id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $ventas = DB::table('ventas.ventas')
            ->where(fn ($q) => $q->whereIn('cliente_id', $ids)->orWhereIn('membresia_id', $membresias ?: [0]))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        if (DB::table('ventas.pagos')->whereIn('venta_id', $ventas ?: [0])->exists()) {
            Log::warning('Eliminar Palma: hay pagos en sus ventas; no se elimina nada.');
            return;
        }

        // Ids de segundo nivel.
        $en = fn (string $tabla, string $col, array $valores) => $valores
            ? DB::table($tabla)->whereIn($col, $valores)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
        $cuentas = $en('cuentas_cobrar.cuentas', 'venta_id', $ventas);
        $comprobantes = $en('ventas.comprobantes', 'venta_id', $ventas);
        $periodos = $en('membresias.membresia_periodos', 'membresia_id', $membresias);
        $cuotas = $en('membresias.membresia_cuotas', 'membresia_id', $membresias);
        $movimientos = $en('membresias.membresia_movimientos', 'membresia_id', $membresias);
        $descuentos = $en('membresias.descuentos_deportistas', 'deportista_id', $ids);

        // El tutor se elimina solo si nada más lo usa.
        $tutorLibre = ! DB::table('clientes.deportista_representantes')->where('representante_persona_id', $tutorId)->whereNotIn('deportista_id', $ids)->exists()
            && ! DB::table('clientes.deportistas')->where('persona_id', $tutorId)->exists()
            && ! DB::table('seguridad.users')->where('persona_id', $tutorId)->exists()
            && ! DB::table('ventas.pagos')->where('pagador_persona_id', $tutorId)->exists();
        $personasEliminar = $tutorLibre ? [...$personas, $tutorId] : $personas;

        // Orden: de las hojas a la raíz. [tabla, columna, valores]
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
            ['clientes.tutores', 'persona_id', $tutorLibre ? [$tutorId] : []],
            ['personas.personas', 'id', $personasEliminar],
        ];

        // Cualquier otra tabla que los mencione detiene todo: hay que revisarla a mano.
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
                Log::warning("Eliminar Palma: {$col->t}.{$col->c} también los usa; no se elimina nada.");
                return;
            }
        }

        $copia = [];
        $existe = fn (string $tabla, string $col) => DB::table('information_schema.columns')
            ->where('table_schema', explode('.', $tabla)[0])->where('table_name', explode('.', $tabla)[1])
            ->where('column_name', $col)->exists();
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
                'registro_id' => implode(',', array_map(fn ($f) => $f->id, $copia['clientes.deportistas'] ?? [])),
                'accion' => 'ELIMINAR',
                'descripcion' => 'Eliminación de Ezequiel y Daniela Palma (tutor Jacinto Palma) por pedido de la administración: se retiraron y no deben nada. Copia completa en datos_antes.',
                'datos_antes' => json_encode($copia, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });

        Log::info('Eliminar Palma: eliminado.', array_map('count', $copia));
    }

    public function down(): void
    {
        // La copia completa queda en auditoria.eventos y en el respaldo previo.
    }
};
