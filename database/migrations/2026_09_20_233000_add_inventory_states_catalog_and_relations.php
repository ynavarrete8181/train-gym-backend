<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        $estados = [
            [
                'codigo' => 'INV_MOV_REGISTRADO',
                'entidad' => 'INVENTARIO_MOVIMIENTO',
                'valor_interno' => 'REGISTRADO',
                'nombre' => 'Registrado',
                'descripcion' => 'Movimiento de inventario registrado y vigente.',
                'color' => 'info',
                'orden' => 10,
                'activo' => true,
                'es_inicial' => true,
                'es_final' => false,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_MOV_APROBADO',
                'entidad' => 'INVENTARIO_MOVIMIENTO',
                'valor_interno' => 'APROBADO',
                'nombre' => 'Aprobado',
                'descripcion' => 'Movimiento de inventario aprobado cuando el proceso requiera autorización.',
                'color' => 'success',
                'orden' => 20,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => false,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_MOV_REVERSADO',
                'entidad' => 'INVENTARIO_MOVIMIENTO',
                'valor_interno' => 'REVERSADO',
                'nombre' => 'Reversado',
                'descripcion' => 'Movimiento compensado mediante un movimiento inverso trazable.',
                'color' => 'warning',
                'orden' => 90,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => true,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_MOV_ANULADO',
                'entidad' => 'INVENTARIO_MOVIMIENTO',
                'valor_interno' => 'ANULADO',
                'nombre' => 'Anulado',
                'descripcion' => 'Movimiento anulado sin eliminar su trazabilidad histórica.',
                'color' => 'error',
                'orden' => 100,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => true,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_LOTE_DISPONIBLE',
                'entidad' => 'INVENTARIO_LOTE',
                'valor_interno' => 'DISPONIBLE',
                'nombre' => 'Disponible',
                'descripcion' => 'Lote activo con existencias disponibles y vigente.',
                'color' => 'success',
                'orden' => 10,
                'activo' => true,
                'es_inicial' => true,
                'es_final' => false,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_LOTE_AGOTADO',
                'entidad' => 'INVENTARIO_LOTE',
                'valor_interno' => 'AGOTADO',
                'nombre' => 'Agotado',
                'descripcion' => 'Lote sin existencias disponibles.',
                'color' => 'default',
                'orden' => 20,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => true,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_LOTE_VENCIDO',
                'entidad' => 'INVENTARIO_LOTE',
                'valor_interno' => 'VENCIDO',
                'nombre' => 'Vencido',
                'descripcion' => 'Lote cuya fecha de vencimiento ya fue alcanzada.',
                'color' => 'error',
                'orden' => 30,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => true,
                'protegido_sistema' => true,
            ],
            [
                'codigo' => 'INV_LOTE_BLOQUEADO',
                'entidad' => 'INVENTARIO_LOTE',
                'valor_interno' => 'BLOQUEADO',
                'nombre' => 'Bloqueado',
                'descripcion' => 'Lote retenido administrativamente y no disponible para salidas.',
                'color' => 'warning',
                'orden' => 40,
                'activo' => true,
                'es_inicial' => false,
                'es_final' => false,
                'protegido_sistema' => true,
            ],
        ];

        foreach ($estados as $estado) {
            DB::table('configuracion.estados_catalogo')->updateOrInsert(
                ['codigo' => $estado['codigo']],
                [...$estado, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.movimientos', 'estado_id')) {
                $table->unsignedBigInteger('estado_id')->nullable()->after('tipo_movimiento');
                $table->foreign('estado_id')->references('id')->on('configuracion.estados_catalogo')->restrictOnDelete();
                $table->index('estado_id');
            }
        });

        Schema::table('inventario.lotes_producto', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.lotes_producto', 'estado_id')) {
                $table->unsignedBigInteger('estado_id')->nullable()->after('activo');
                $table->foreign('estado_id')->references('id')->on('configuracion.estados_catalogo')->restrictOnDelete();
                $table->index('estado_id');
            }
        });

        $estadoMovimiento = DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'INVENTARIO_MOVIMIENTO')
            ->where('valor_interno', 'REGISTRADO')
            ->value('id');

        $estadoLoteDisponible = DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'INVENTARIO_LOTE')
            ->where('valor_interno', 'DISPONIBLE')
            ->value('id');

        $estadoLoteAgotado = DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'INVENTARIO_LOTE')
            ->where('valor_interno', 'AGOTADO')
            ->value('id');

        if ($estadoMovimiento) {
            DB::table('inventario.movimientos')->whereNull('estado_id')->update(['estado_id' => $estadoMovimiento]);
        }

        if ($estadoLoteDisponible && $estadoLoteAgotado) {
            DB::table('inventario.lotes_producto')
                ->whereNull('estado_id')
                ->update([
                    'estado_id' => DB::raw("CASE WHEN stock_actual <= 0 THEN {$estadoLoteAgotado} ELSE {$estadoLoteDisponible} END"),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (Schema::hasColumn('inventario.movimientos', 'estado_id')) {
                $table->dropForeign(['estado_id']);
                $table->dropIndex(['estado_id']);
                $table->dropColumn('estado_id');
            }
        });

        Schema::table('inventario.lotes_producto', function (Blueprint $table): void {
            if (Schema::hasColumn('inventario.lotes_producto', 'estado_id')) {
                $table->dropForeign(['estado_id']);
                $table->dropIndex(['estado_id']);
                $table->dropColumn('estado_id');
            }
        });

        DB::table('configuracion.estados_catalogo')
            ->whereIn('codigo', [
                'INV_MOV_REGISTRADO',
                'INV_MOV_APROBADO',
                'INV_MOV_REVERSADO',
                'INV_MOV_ANULADO',
                'INV_LOTE_DISPONIBLE',
                'INV_LOTE_AGOTADO',
                'INV_LOTE_VENCIDO',
                'INV_LOTE_BLOQUEADO',
            ])
            ->delete();
    }
};
