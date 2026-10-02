<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            if (! Schema::hasColumn('membresias.membresias', 'modalidad_id')) {
                $table->foreignId('modalidad_id')
                    ->nullable()
                    ->after('plan_id')
                    ->constrained('membresias.plan_modalidades')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            if (Schema::hasColumn('membresias.membresias', 'modalidad_id')) {
                $table->dropConstrainedForeignId('modalidad_id');
            }
        });
    }
};
