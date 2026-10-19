<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('membresias.membresia_periodos', function (Blueprint $table): void {
            $table->foreignId('descuento_id')->nullable()->constrained('membresias.descuentos_deportistas')->nullOnDelete();
            $table->decimal('descuento_importe', 12, 2)->default(0);
        });
    }

    public function down(): void {
        Schema::table('membresias.membresia_periodos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('descuento_id');
            $table->dropColumn('descuento_importe');
        });
    }
};
