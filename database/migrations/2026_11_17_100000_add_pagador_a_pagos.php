<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Persona que entregó el dinero de un cobro. Se llena cuando un tutor paga las
 * cuentas de sus representados (y la suya) en una sola operación, para que el
 * recibo y la lista de cobros salgan a su nombre aunque el vínculo cambie después.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ventas.pagos ADD COLUMN IF NOT EXISTS pagador_persona_id bigint NULL');
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'fk_pagos_pagador_persona\') THEN
                ALTER TABLE ventas.pagos ADD CONSTRAINT fk_pagos_pagador_persona
                    FOREIGN KEY (pagador_persona_id) REFERENCES personas.personas(id);
            END IF;
        END $$');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pagos_pagador_persona ON ventas.pagos (pagador_persona_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ventas.pagos DROP CONSTRAINT IF EXISTS fk_pagos_pagador_persona');
        DB::statement('DROP INDEX IF EXISTS ventas.idx_pagos_pagador_persona');
        DB::statement('ALTER TABLE ventas.pagos DROP COLUMN IF EXISTS pagador_persona_id');
    }
};
