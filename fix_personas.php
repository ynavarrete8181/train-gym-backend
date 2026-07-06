<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::transaction(function () {
    $personas = DB::table('core.personas')->orderBy('id')->get();
    
    $newId = 1;
    foreach ($personas as $persona) {
        $oldId = $persona->id;
        
        if ($oldId == $newId) {
            $newId++;
            continue;
        }

        echo "Updating Persona ID {$oldId} to {$newId}\n";
        
        // Temporarily change the old record's identification so we can insert the new one
        DB::table('core.personas')->where('id', $oldId)->update([
            'numero_identificacion' => $persona->numero_identificacion . '_OLD_' . $oldId
        ]);
        
        $newPersona = (array) $persona;
        $newPersona['id'] = $newId;
        
        DB::table('core.personas')->insert($newPersona);

        $tables = [
            'core.persona_sedes',
            'core.persona_tipo_detalle',
            'seguridad.usuarios',
            'asistencias.asistencias',
            'asistencias.reconocimiento_facial',
            'entrenamiento.evaluaciones_rm',
            'entrenamiento.planes',
            'entrenamiento.plan_asignaciones',
            'ventas.ventas',
            'ventas.punto_venta_borradores',
        ];

        foreach ($tables as $table) {
            $schemaTable = explode('.', $table);
            $schema = $schemaTable[0];
            $tableName = $schemaTable[1];
            
            $exists = DB::select("SELECT to_regclass('{$table}') as exists");
            if ($exists[0]->exists) {
                $hasColumn = DB::select("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_schema='{$schema}' AND table_name='{$tableName}' AND column_name='persona_id'
                ");
                
                if (count($hasColumn) > 0) {
                    $updated = DB::table($table)->where('persona_id', $oldId)->update(['persona_id' => $newId]);
                    if ($updated > 0) {
                        echo "  - Updated {$updated} records in {$table}\n";
                    }
                }
            }
        }
        
        DB::table('core.personas')->where('id', $oldId)->delete();
        
        $newId++;
    }
    
    $maxId = DB::table('core.personas')->max('id') ?? 0;
    $nextId = $maxId + 1;
    DB::statement("ALTER SEQUENCE core.personas_id_seq RESTART WITH {$nextId}");
    echo "Sequence restarted at {$nextId}\n";
});

echo "Done!\n";
