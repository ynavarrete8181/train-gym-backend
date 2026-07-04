<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE train_gimnasio.producto_precios DROP CONSTRAINT IF EXISTS ck_producto_precios_tipo");
        DB::statement("
            ALTER TABLE train_gimnasio.producto_precios
            ADD CONSTRAINT ck_producto_precios_tipo
            CHECK ((tipo_precio)::text = ANY ((ARRAY[
                'COSTO'::character varying,
                'VENTA'::character varying,
                'SOCIO'::character varying,
                'PROMOCION'::character varying
            ])::text[]))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE train_gimnasio.producto_precios DROP CONSTRAINT IF EXISTS ck_producto_precios_tipo");
        DB::statement("
            ALTER TABLE train_gimnasio.producto_precios
            ADD CONSTRAINT ck_producto_precios_tipo
            CHECK ((tipo_precio)::text = ANY ((ARRAY[
                'COSTO'::character varying,
                'VENTA'::character varying,
                'PROMOCION'::character varying
            ])::text[]))
        ");
    }
};
