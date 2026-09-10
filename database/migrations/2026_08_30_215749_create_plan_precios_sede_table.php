<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('gimnasio.plan_precios_sede', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('gimnasio.planes')->onDelete('cascade');
            $table->foreignId('sede_id')->constrained('institucional.sedes', 'id_sede')->onDelete('cascade');
            $table->decimal('precio', 10, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'sede_id'], 'uq_plan_sede');
        });
    }

    public function down()
    {
        Schema::dropIfExists('gimnasio.plan_precios_sede');
    }
};
