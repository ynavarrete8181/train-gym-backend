<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('membresias.membresia_periodos', 'renovacion_manual')) {
            Schema::table('membresias.membresia_periodos', function (Blueprint $table): void {
                $table->boolean('renovacion_manual')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('membresias.membresia_periodos', 'renovacion_manual')) {
            Schema::table('membresias.membresia_periodos', function (Blueprint $table): void {
                $table->dropColumn('renovacion_manual');
            });
        }
    }
};
