<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            if (! Schema::connection('pgsql')->hasColumn('ventas.pagos', 'codigo_cobro')) {
                $table->string('codigo_cobro', 40)->nullable()->after('operacion_cobro_id');
                $table->index('codigo_cobro');
            }
        });

        $operaciones = DB::table('ventas.pagos')
            ->selectRaw("COALESCE(operacion_cobro_id, 'PAGO-' || id::text) as operacion")
            ->selectRaw('MIN(fecha_pago) as fecha_pago')
            ->groupByRaw("COALESCE(operacion_cobro_id, 'PAGO-' || id::text)")
            ->orderByRaw('MIN(fecha_pago)')
            ->orderByRaw('MIN(id)')
            ->get();

        $contadores = [];
        foreach ($operaciones as $operacion) {
            $fecha = $operacion->fecha_pago ? date('Ymd', strtotime((string) $operacion->fecha_pago)) : now()->format('Ymd');
            $contadores[$fecha] = ($contadores[$fecha] ?? 0) + 1;
            $codigo = sprintf('COBRO-%s-%04d', $fecha, $contadores[$fecha]);

            DB::table('ventas.pagos')
                ->whereRaw("COALESCE(operacion_cobro_id, 'PAGO-' || id::text) = ?", [$operacion->operacion])
                ->update(['codigo_cobro' => $codigo, 'updated_at' => now()]);
        }

        Schema::connection('pgsql')->table('ventas.comprobantes', function (Blueprint $table): void {
            if (! Schema::connection('pgsql')->hasColumn('ventas.comprobantes', 'correo_destino')) {
                $table->string('correo_destino', 255)->nullable();
            }
            if (! Schema::connection('pgsql')->hasColumn('ventas.comprobantes', 'enviado_at')) {
                $table->timestamp('enviado_at')->nullable();
            }
            if (! Schema::connection('pgsql')->hasColumn('ventas.comprobantes', 'ultimo_envio_at')) {
                $table->timestamp('ultimo_envio_at')->nullable();
            }
            if (! Schema::connection('pgsql')->hasColumn('ventas.comprobantes', 'cantidad_envios')) {
                $table->unsignedInteger('cantidad_envios')->default(0);
            }
        });

        if (! Schema::connection('pgsql')->hasTable('ventas.comprobante_envios')) {
            Schema::connection('pgsql')->create('ventas.comprobante_envios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('comprobante_id')->constrained('ventas.comprobantes')->cascadeOnDelete();
                $table->foreignId('venta_id')->constrained('ventas.ventas')->cascadeOnDelete();
                $table->string('correo_destino', 255);
                $table->string('tipo', 20)->default('ENVIO');
                $table->string('estado', 30)->default('PENDIENTE');
                $table->unsignedBigInteger('solicitado_por')->nullable();
                $table->timestamp('enviado_at')->nullable();
                $table->text('mensaje_error')->nullable();
                $table->timestamps();
                $table->foreign('solicitado_por')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['venta_id', 'estado']);
            });
        }

        $eventoId = DB::table('notificaciones.eventos')->where('codigo', 'VENTA_PAGADA')->value('id');
        if (! $eventoId) {
            $eventoId = DB::table('notificaciones.eventos')->insertGetId([
                'codigo' => 'VENTA_PAGADA',
                'nombre' => 'Venta pagada',
                'descripcion' => 'Envío automático del comprobante final cuando una venta queda pagada.',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $plantillaId = DB::table('notificaciones.plantillas')->where('codigo', 'AUTOMATICA_COMPROBANTE_VENTA')->value('id');
        $plantilla = [
            'nombre' => 'Comprobante de venta pagada',
            'tipo' => 'AUTOMATICA',
            'asunto' => 'Comprobante {{numero_comprobante}} - {{nombre_sistema}}',
            'cuerpo_html' => '<div><h2>Comprobante de pago</h2><p>Hola <strong>{{nombre_cliente}}</strong>,</p><p>Tu pago fue registrado correctamente en {{nombre_sistema}}.</p><p><strong>Comprobante:</strong> {{numero_comprobante}}<br><strong>Venta:</strong> {{numero_venta}}<br><strong>Concepto:</strong> {{concepto_venta}}<br><strong>Total pagado:</strong> {{total_pagado}}<br><strong>Fecha:</strong> {{fecha_pago}}<br><strong>Sede:</strong> {{sede_nombre}}</p><p>Adjuntamos el comprobante PDF con el detalle de la operación{{detalle_membresia}}.</p><p>Gracias por ser parte de Revive.</p></div>',
            'cuerpo_texto' => "Hola {{nombre_cliente}},\n\nTu pago fue registrado correctamente en {{nombre_sistema}}.\nComprobante: {{numero_comprobante}}\nVenta: {{numero_venta}}\nConcepto: {{concepto_venta}}\nTotal pagado: {{total_pagado}}\nFecha: {{fecha_pago}}\nSede: {{sede_nombre}}\n\nAdjuntamos el comprobante PDF con el detalle de la operación{{detalle_membresia}}.\n\nGracias por ser parte de Revive.",
            'variables' => json_encode(['nombre_sistema','nombre_cliente','numero_comprobante','numero_venta','concepto_venta','total_pagado','fecha_pago','sede_nombre','detalle_membresia']),
            'activo' => true,
            'updated_at' => now(),
        ];

        if ($plantillaId) {
            DB::table('notificaciones.plantillas')->where('id', $plantillaId)->update($plantilla);
        } else {
            $plantillaId = DB::table('notificaciones.plantillas')->insertGetId($plantilla + [
                'codigo' => 'AUTOMATICA_COMPROBANTE_VENTA',
                'created_at' => now(),
            ]);
        }

        DB::table('notificaciones.evento_plantilla')->updateOrInsert(
            ['evento_id' => $eventoId, 'plantilla_id' => $plantillaId],
            ['predeterminada' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        $plantillaId = DB::table('notificaciones.plantillas')->where('codigo', 'AUTOMATICA_COMPROBANTE_VENTA')->value('id');
        if ($plantillaId) {
            DB::table('notificaciones.evento_plantilla')->where('plantilla_id', $plantillaId)->delete();
            DB::table('notificaciones.plantillas')->where('id', $plantillaId)->delete();
        }
        DB::table('notificaciones.eventos')->where('codigo', 'VENTA_PAGADA')->delete();

        Schema::connection('pgsql')->dropIfExists('ventas.comprobante_envios');

        Schema::connection('pgsql')->table('ventas.comprobantes', function (Blueprint $table): void {
            foreach (['correo_destino','enviado_at','ultimo_envio_at','cantidad_envios'] as $columna) {
                if (Schema::connection('pgsql')->hasColumn('ventas.comprobantes', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            if (Schema::connection('pgsql')->hasColumn('ventas.pagos', 'codigo_cobro')) {
                $table->dropIndex(['codigo_cobro']);
                $table->dropColumn('codigo_cobro');
            }
        });
    }
};
