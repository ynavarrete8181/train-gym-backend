<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
UPDATE entrenamiento.ejercicios
SET equipamiento = 'Balón Medicinal',
    updated_at = NOW()
WHERE equipamiento ILIKE 'Balón Medicional';

UPDATE entrenamiento.ejercicios
SET nombre = 'Vuelos frontales cable',
    updated_at = NOW()
WHERE id = 8
  AND nombre = 'Vuelos frontales cabo';

DELETE FROM entrenamiento.ejercicios e
WHERE e.id = 36
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.rutinas r WHERE r.ejercicio_id = e.id OR r.ejercicio_transferencia_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.rutina_plantilla_detalles d WHERE d.ejercicio_id = e.id OR d.ejercicio_transferencia_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.plan_ejercicios p WHERE p.ejercicio_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.plan_ejercicio_transferencias t WHERE t.ejercicio_id = e.id);

DELETE FROM entrenamiento.ejercicios e
WHERE e.id = 39
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.rutinas r WHERE r.ejercicio_id = e.id OR r.ejercicio_transferencia_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.rutina_plantilla_detalles d WHERE d.ejercicio_id = e.id OR d.ejercicio_transferencia_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.plan_ejercicios p WHERE p.ejercicio_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM entrenamiento.plan_ejercicio_transferencias t WHERE t.ejercicio_id = e.id);
SQL);
    }

    public function down(): void
    {
    }
};
