<?php

use App\Services\Institucional\NormalizacionSedeService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(NormalizacionSedeService::class)->normalizarManta();
    }

    public function down(): void
    {
        // Corrección de datos: no se recrea el valor erróneo "Matriz - Manta".
    }
};
