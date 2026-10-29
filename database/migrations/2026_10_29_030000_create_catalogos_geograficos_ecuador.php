<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS geografia');

        Schema::create('geografia.provincias', function (Blueprint $table): void {
            $table->string('codigo_dpa', 2)->primary();
            $table->string('nombre', 120);
            $table->boolean('activo')->default(true);
            $table->string('fuente_version', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('geografia.cantones', function (Blueprint $table): void {
            $table->string('codigo_dpa', 4)->primary();
            $table->string('provincia_codigo', 2);
            $table->string('nombre', 140);
            $table->boolean('activo')->default(true);
            $table->string('fuente_version', 20)->nullable();
            $table->timestamps();
            $table->foreign('provincia_codigo', 'geo_canton_provincia_fk')
                ->references('codigo_dpa')->on('geografia.provincias');
            $table->index(['provincia_codigo', 'nombre'], 'geo_cantones_provincia_nombre');
        });

        Schema::create('geografia.parroquias', function (Blueprint $table): void {
            $table->string('codigo_dpa', 6)->primary();
            $table->string('canton_codigo', 4);
            $table->string('nombre', 180);
            $table->string('tipo', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('fuente_version', 20)->nullable();
            $table->timestamps();
            $table->foreign('canton_codigo', 'geo_parroquia_canton_fk')
                ->references('codigo_dpa')->on('geografia.cantones');
            $table->index(['canton_codigo', 'nombre'], 'geo_parroquias_canton_nombre');
        });

        Schema::table('personas.personas', function (Blueprint $table): void {
            $table->string('provincia_codigo', 2)->nullable();
            $table->string('canton_codigo', 4)->nullable();
            $table->string('parroquia_codigo', 6)->nullable();
            $table->foreign('provincia_codigo', 'persona_provincia_dpa_fk')
                ->references('codigo_dpa')->on('geografia.provincias');
            $table->foreign('canton_codigo', 'persona_canton_dpa_fk')
                ->references('codigo_dpa')->on('geografia.cantones');
            $table->foreign('parroquia_codigo', 'persona_parroquia_dpa_fk')
                ->references('codigo_dpa')->on('geografia.parroquias');
            $table->index(['provincia_codigo', 'canton_codigo', 'parroquia_codigo'], 'persona_ubicacion_dpa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('personas.personas', function (Blueprint $table): void {
            $table->dropForeign('persona_parroquia_dpa_fk');
            $table->dropForeign('persona_canton_dpa_fk');
            $table->dropForeign('persona_provincia_dpa_fk');
            $table->dropIndex('persona_ubicacion_dpa_idx');
            $table->dropColumn(['provincia_codigo', 'canton_codigo', 'parroquia_codigo']);
        });

        Schema::dropIfExists('geografia.parroquias');
        Schema::dropIfExists('geografia.cantones');
        Schema::dropIfExists('geografia.provincias');
        DB::statement('DROP SCHEMA IF EXISTS geografia');
    }
};
