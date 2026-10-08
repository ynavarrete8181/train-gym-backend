<?php

namespace App\Services\Ventas;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ComprobanteCorreoServicio
{
    public function __construct(
        private readonly VentaServicio $ventas,
        private readonly ComprobantePdfServicio $pdfs,
        private readonly CorreoTransportContract $correo,
    ) {
    }

    public function enviar(int $ventaId, ?int $usuarioId = null, bool $reenvio = false): array
    {
        $venta = $this->ventas->detalleVenta($ventaId, $usuarioId);

        if (strtoupper((string) ($venta->estado ?? '')) !== 'PAGADA') {
            throw ValidationException::withMessages([
                'venta_id' => 'El comprobante final solo puede enviarse cuando la venta está pagada.',
            ]);
        }

        if (! $venta->comprobante || strtoupper((string) $venta->comprobante->estado) !== 'EMITIDO') {
            throw ValidationException::withMessages([
                'comprobante' => 'El comprobante todavía no está emitido.',
            ]);
        }

        $correoDestino = trim((string) ($venta->cliente_email ?? ''));
        if (! filter_var($correoDestino, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'correo_destino' => 'El cliente no tiene un correo electrónico válido registrado.',
            ]);
        }

        $plantilla = DB::table('notificaciones.evento_plantilla as ep')
            ->join('notificaciones.eventos as e', 'e.id', '=', 'ep.evento_id')
            ->join('notificaciones.plantillas as p', 'p.id', '=', 'ep.plantilla_id')
            ->where('e.codigo', 'VENTA_PAGADA')
            ->where('e.activo', true)
            ->where('ep.activo', true)
            ->where('ep.predeterminada', true)
            ->where('p.activo', true)
            ->select('p.*')
            ->first();

        if (! $plantilla) {
            throw ValidationException::withMessages([
                'plantilla' => 'No existe una plantilla activa y predeterminada para el evento VENTA_PAGADA.',
            ]);
        }

        $variables = $this->variables($venta);
        $render = fn (string $texto) => preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_]+)\s*}}/',
            fn ($m) => e($variables[$m[1]] ?? ''),
            $texto
        );

        $pdf = $this->pdfs->generar($venta);
        $nombrePdf = ($venta->comprobante->numero ?? $venta->numero) . '.pdf';

        $envioId = DB::table('ventas.comprobante_envios')->insertGetId([
            'comprobante_id' => $venta->comprobante->id,
            'venta_id' => $venta->id,
            'correo_destino' => $correoDestino,
            'tipo' => $reenvio ? 'REENVIO' : 'ENVIO',
            'estado' => 'PROCESANDO',
            'solicitado_por' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $resultado = $this->correo->enviar([
                'para' => $correoDestino,
                'asunto' => $render((string) $plantilla->asunto),
                'html' => $render((string) $plantilla->cuerpo_html),
                'adjuntos' => [[
                    'nombre' => $nombrePdf,
                    'mime' => 'application/pdf',
                    'contenido' => $pdf,
                ]],
            ]);

            $ok = (bool) ($resultado['ok'] ?? false);

            DB::table('ventas.comprobante_envios')->where('id', $envioId)->update([
                'estado' => $ok ? 'ENVIADO' : 'ERROR',
                'enviado_at' => $ok ? now() : null,
                'mensaje_error' => $ok ? null : (string) data_get($resultado, 'respuesta.mensaje'),
                'updated_at' => now(),
            ]);

            if ($ok) {
                DB::table('ventas.comprobantes')->where('id', $venta->comprobante->id)->update([
                    'correo_destino' => $correoDestino,
                    'enviado_at' => $venta->comprobante->enviado_at ?? now(),
                    'ultimo_envio_at' => now(),
                    'cantidad_envios' => DB::raw('COALESCE(cantidad_envios, 0) + 1'),
                    'updated_at' => now(),
                ]);
            }

            return [
                'ok' => $ok,
                'correo_destino' => $correoDestino,
                'tipo' => $reenvio ? 'REENVIO' : 'ENVIO',
            ];
        } catch (Throwable $e) {
            DB::table('ventas.comprobante_envios')->where('id', $envioId)->update([
                'estado' => 'ERROR',
                'mensaje_error' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    private function variables(object $venta): array
    {
        $pagado = collect($venta->pagos ?? [])
            ->where('estado', 'CONFIRMADO')
            ->sum(fn ($p) => (float) $p->monto);

        $detalleMembresia = '';
        if (! empty($venta->membresia_codigo)) {
            $detalleMembresia = sprintf(
                ' de tu membresía %s%s, vigente del %s al %s',
                $venta->membresia_plan_nombre ?? 'Revive',
                ! empty($venta->membresia_modalidad_nombre) ? ' · ' . $venta->membresia_modalidad_nombre : '',
                $venta->membresia_fecha_inicio ? date('d/m/Y', strtotime((string) $venta->membresia_fecha_inicio)) : '-',
                $venta->membresia_fecha_fin ? date('d/m/Y', strtotime((string) $venta->membresia_fecha_fin)) : '-',
            );
        }

        return [
            'nombre_sistema' => 'Revive',
            'nombre_cliente' => (string) ($venta->cliente_nombre ?? 'Cliente'),
            'numero_comprobante' => (string) ($venta->comprobante->numero ?? ''),
            'numero_venta' => (string) ($venta->numero ?? ''),
            'concepto_venta' => (string) ($venta->concepto ?? ''),
            'total_pagado' => '$' . number_format((float) $pagado, 2, '.', ','),
            'fecha_pago' => now()->format('d/m/Y H:i'),
            'sede_nombre' => (string) ($venta->sede_nombre ?? 'Revive'),
            'detalle_membresia' => $detalleMembresia,
        ];
    }
}
