<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateSchemas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-schemas';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create all required PostgreSQL schemas for the application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $schemas = [
            'train_gimnasio',
            'core',
            'socios',
            'seguridad',
            'salud',
            'entrenamiento',
            'ventas',
            'auditoria',
            'inventario',
            'notificaciones',
            'comunicaciones',
        ];

        foreach ($schemas as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$schema};");
            $this->info("Schema {$schema} verified/created successfully.");
        }
    }
}
