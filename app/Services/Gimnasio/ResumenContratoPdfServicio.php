<?php

namespace App\Services\Gimnasio;

use App\Support\PdfSimple;
use Illuminate\Support\Facades\DB;

/** Resumen imprimible de todo el contrato de una membresía. */
class ResumenContratoPdfServicio
{
    private const NEGRO = [0.055, 0.055, 0.055];
    private const DORADO = [0.83, 0.63, 0.09];
    private const GRIS = [0.40, 0.43, 0.47];
    private const SUAVE = [0.975, 0.978, 0.982];

    private const MOVIMIENTOS = [
        'CONGELAR' => 'Congelación', 'REACTIVAR' => 'Reactivación', 'CERRAR' => 'Cancelación del contrato',
        'REAPERTURA_EXCEPCIONAL' => 'Reapertura', 'EDITAR_VIGENCIA' => 'Cambio de vigencia', 'CAMBIAR_SEDES' => 'Cambio de sedes',
        'DESCUENTO_REGISTRADO' => 'Descuento registrado', 'DESCUENTO_EDITADO' => 'Descuento editado',
        'DESCUENTO_FINALIZADO' => 'Descuento finalizado', 'DESCUENTO_AJUSTADO' => 'Descuento ajustado al contrato',
        'RENOVACION_MANUAL' => 'Renovación manual', 'RENOVACION_AUTOMATICA' => 'Renovación automática',
        'CONFIGURAR_CONTRATO' => 'Configuración del contrato', 'CONTRATACION' => 'Contratación',
    ];

    public function __construct(
        private readonly ContratoMembresiaServicio $contrato,
        private readonly HistorialIntegralMembresiaServicio $historial,
    ) {
    }

    public function generar(int $id, ?int $usuarioId): array
    {
        $m = DB::table('membresias.membresias as m')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as mo', 'mo.id', '=', 'm.modalidad_id')
            ->join('clientes.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->leftJoin('personas.personas as pe', 'pe.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->where('m.id', $id)
            ->first([
                'm.*', 'p.nombre as plan_nombre', 'p.tipo_producto', 'mo.nombre as modalidad_nombre', 'd.codigo_deportista',
                DB::raw('COALESCE(pe.nombre_completo, u.name) as cliente'),
                DB::raw('COALESCE(pe.identificacion, u.cedula) as identificacion'),
            ]);
        abort_unless($m, 404, 'Membresía no encontrada.');

        $esPase = $m->tipo_producto === 'PASE_DIARIO';
        $hoy = now()->toDateString();
        $sedes = DB::table('membresias.membresia_sedes as ms')->join('institucional.sedes as s', 's.id_sede', '=', 'ms.sede_id')
            ->where('ms.membresia_id', $id)->where('ms.activo', true)->orderBy('s.nombre')->pluck('s.nombre')->implode(', ');
        $descuentos = $this->contrato->descuentos($id)['descuentos'];
        $periodos = DB::table('membresias.membresia_periodos as mp')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->where('mp.membresia_id', $id)->orderBy('mp.numero_periodo')
            ->get(['mp.numero_periodo', 'mp.fecha_inicio', 'mp.fecha_fin', 'mp.estado', 'v.numero', 'v.total', 'v.estado as venta_estado',
                DB::raw("(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO') as pagado")]);
        $eventos = collect($this->historial->consultar($id)['eventos'] ?? [])->sortByDesc('fecha')->values();
        $autor = $usuarioId ? DB::table('seguridad.users')->where('id', $usuarioId)->value('name') : null;

        $f = fn ($v) => $v ? implode('/', array_reverse(explode('-', substr((string) $v, 0, 10)))) : '—';
        $dinero = fn ($v) => '$'.number_format((float) $v, 2, '.', ',');
        $estado = $this->estadoVisible($m, $esPase, $hoy);

        $pdf = new PdfSimple();
        $pdf->alAbrirPagina = function (PdfSimple $p) use ($m) {
            $p->texto(PdfSimple::MARGEN, $p->y - 8, 'Resumen de contrato · '.$m->codigo_contrato.' · '.$m->cliente, 8, true, self::GRIS);
            $p->y -= 24;
        };

        // Encabezado
        // Fondo negro puro para que se funda con el fondo del logo oficial.
        $pdf->rectangulo(0, 754, PdfSimple::ANCHO, 88, [0, 0, 0]);
        if (! $pdf->logo(30, 780, 50)) {
            $pdf->texto(36, 806, 'REVIVE', 23, true, self::DORADO);
            $pdf->texto(120, 806, 'SPORTS', 23, true, [1, 1, 1]);
        }
        $pdf->texto(36, 766, 'RESUMEN DE CONTRATO DE MEMBRESÍA', 8, true, [0.80, 0.80, 0.80]);
        $pdf->texto(36, 722, $m->plan_nombre.' · '.$m->codigo_contrato, 14, true);
        $pdf->texto(430, 722, mb_strtoupper($estado), 10, true, self::DORADO);
        $pdf->y = 700;

        $this->seccion($pdf, 'CLIENTE');
        $this->datos($pdf, [
            ['Cliente', $m->cliente ?? '—'], ['Identificación', $m->identificacion ?? '—'], ['Código', $m->codigo_deportista ?? '—'],
        ]);

        $this->seccion($pdf, 'CONTRATO');
        $vigencia = $esPase
            ? ($m->vigencia_fin ? $f($m->vigencia_inicio).' al '.$f($m->vigencia_fin) : 'Sin límite')
            : $f($m->fecha_inicio).' al '.$f($m->fecha_fin);
        $this->datos($pdf, array_values(array_filter([
            ['Plan', $m->plan_nombre], ['Modalidad', $m->modalidad_nombre ?: 'Sin modalidad'], ['Estado', $estado],
            ['Vigencia', $vigencia],
            $esPase ? ['Último día de uso', $f($m->fecha_inicio)] : ['Días de gracia', (string) ($m->dias_gracia ?? 0)],
            ['Precio registrado', $dinero($m->precio_aplicado)],
            $esPase ? null : ['Renovación automática', $m->renovacion_automatica ? 'Activada' : 'No'],
            ['Sedes autorizadas', $sedes ?: '—'],
        ])));

        $this->seccion($pdf, 'DESCUENTOS');
        $estados = ['VIGENTE' => 'Vigente', 'PROGRAMADO' => 'Programado', 'VENCIDO' => 'Vencido', 'FINALIZADO' => 'Finalizado'];
        $pdf->tabla([
            ['titulo' => 'Descuento', 'ancho' => 80], ['titulo' => 'Desde', 'ancho' => 70], ['titulo' => 'Hasta', 'ancho' => 70],
            ['titulo' => 'Estado', 'ancho' => 70], ['titulo' => 'Motivo', 'ancho' => 233],
        ], collect($descuentos)->map(fn ($d) => [
            $d->tipo === 'PORCENTAJE' ? ((float) $d->valor).' %' : $dinero($d->valor),
            $f($d->fecha_inicio), $d->fecha_fin ? $f($d->fecha_fin) : 'Sin fin', $estados[$d->estado] ?? $d->estado, (string) $d->motivo,
        ])->all());

        $this->seccion($pdf, $esPase ? 'DÍAS VENDIDOS Y PAGOS' : 'PERÍODOS Y PAGOS');
        $pdf->tabla([
            ['titulo' => 'N.º', 'ancho' => 30], ['titulo' => 'Desde', 'ancho' => 66], ['titulo' => 'Hasta', 'ancho' => 66],
            ['titulo' => 'Venta', 'ancho' => 150], ['titulo' => 'Total', 'ancho' => 62], ['titulo' => 'Pagado', 'ancho' => 62],
            ['titulo' => 'Estado', 'ancho' => 87],
        ], $periodos->map(fn ($p) => [
            (string) $p->numero_periodo, $f($p->fecha_inicio), $f($p->fecha_fin), $p->numero ?: 'Sin venta',
            $p->numero ? $dinero($p->total) : '—', $p->numero ? $dinero($p->pagado) : '—',
            $p->venta_estado ? ucfirst(mb_strtolower(str_replace('_', ' ', $p->venta_estado))) : ucfirst(mb_strtolower(str_replace('_', ' ', (string) $p->estado))),
        ])->all());

        $this->seccion($pdf, 'HISTORIAL');
        $pdf->tabla([
            ['titulo' => 'Fecha', 'ancho' => 92], ['titulo' => 'Movimiento', 'ancho' => 135],
            ['titulo' => 'Responsable', 'ancho' => 110], ['titulo' => 'Motivo', 'ancho' => 186],
        ], $eventos->map(fn ($e) => [
            $e['fecha'] ? date('d/m/Y H:i', strtotime((string) $e['fecha'])) : '—',
            self::MOVIMIENTOS[$e['tipo']] ?? ucfirst(mb_strtolower(str_replace('_', ' ', (string) $e['tipo']))),
            $e['autor_nombre'] ?? 'Sistema', (string) ($e['motivo'] ?? ''),
        ])->all());

        $pdf->asegurar(24);
        $pdf->texto(PdfSimple::MARGEN, $pdf->y - 12, 'Generado el '.now()->format('d/m/Y H:i').($autor ? ' por '.$autor : '').'.', 8, false, self::GRIS);

        return ['pdf' => $pdf->generar(), 'nombre' => 'contrato-'.$m->codigo_contrato.'.pdf'];
    }

    private function seccion(PdfSimple $pdf, string $titulo): void
    {
        $pdf->asegurar(40);
        $pdf->y -= 10;
        $pdf->rectangulo(PdfSimple::MARGEN, $pdf->y - 2, 3, 11, self::DORADO);
        $pdf->texto(PdfSimple::MARGEN + 9, $pdf->y, $titulo, 9, true);
        $pdf->y -= 16;
    }

    private function datos(PdfSimple $pdf, array $pares): void
    {
        $filas = array_chunk($pares, 3);
        $alto = count($filas) * 30 + 8;
        $pdf->asegurar($alto);
        $pdf->rectangulo(PdfSimple::MARGEN, $pdf->y - $alto, PdfSimple::ANCHO - 2 * PdfSimple::MARGEN, $alto, self::SUAVE);
        $y = $pdf->y - 14;
        foreach ($filas as $fila) {
            foreach ($fila as $i => [$etiqueta, $valor]) {
                $x = PdfSimple::MARGEN + 12 + $i * 172;
                $pdf->texto($x, $y, $etiqueta, 7, false, self::GRIS);
                $pdf->texto($x, $y - 12, mb_strimwidth((string) $valor, 0, 34, '…', 'UTF-8'), 9, true);
            }
            $y -= 30;
        }
        $pdf->y -= $alto + 6;
    }

    private function estadoVisible(object $m, bool $esPase, string $hoy): string
    {
        $estado = strtoupper((string) $m->estado);
        $etiquetas = ['PENDIENTE_PAGO' => 'Pendiente de pago', 'ACTIVA' => 'Activa', 'CONGELADA' => 'Congelada', 'VENCIDA' => 'Vencida', 'CANCELADA' => 'Cancelada'];
        if (! $esPase || in_array($estado, ['CANCELADA', 'CONGELADA'], true)) return $etiquetas[$estado] ?? $estado;
        if ($m->vigencia_fin && substr((string) $m->vigencia_fin, 0, 10) < $hoy) return 'Vigencia terminada';
        $inicio = substr((string) $m->fecha_inicio, 0, 10);
        $fin = substr((string) $m->fecha_fin, 0, 10);
        if ($estado === 'ACTIVA' && $inicio <= $hoy && $fin >= $hoy) return 'Vigente hoy';
        if ($estado === 'PENDIENTE_PAGO' && $fin >= $hoy) return 'Pendiente de pago';
        return 'Disponible';
    }
}
