<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("
            CREATE SCHEMA IF NOT EXISTS inventarios;

            CREATE SEQUENCE IF NOT EXISTS inventarios.seq_id_proveedor
                INCREMENT 1
                START 1
                MINVALUE 1
                MAXVALUE 2147483647
                CACHE 1;

            CREATE TABLE IF NOT EXISTS inventarios.proveedores
            (
                prov_id integer NOT NULL DEFAULT nextval('inventarios.seq_id_proveedor'::regclass),
                prov_ruc text COLLATE pg_catalog.\"default\",
                prov_nombre text COLLATE pg_catalog.\"default\",
                prov_direccion text COLLATE pg_catalog.\"default\",
                prov_telefono text COLLATE pg_catalog.\"default\",
                prov_correo text COLLATE pg_catalog.\"default\",
                prov_id_usuario integer,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                prov_estado integer,
                CONSTRAINT cpu_proveedores_pkey PRIMARY KEY (prov_id),
                CONSTRAINT id FOREIGN KEY (prov_id)
                    REFERENCES inventarios.proveedores (prov_id) MATCH SIMPLE
                    ON UPDATE NO ACTION
                    ON DELETE NO ACTION
            )
            TABLESPACE pg_default;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("
            DROP TABLE IF EXISTS inventarios.proveedores;
            DROP SEQUENCE IF NOT EXISTS inventarios.seq_id_proveedor;
        ");
    }
};
