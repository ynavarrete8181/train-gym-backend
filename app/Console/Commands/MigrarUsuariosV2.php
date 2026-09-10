<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class MigrarUsuariosV2 extends Command
{
    protected $signature = 'revive:migrar-usuarios';
    protected $description = 'Migra usuarios desde db_gimnasio_v2 a la base actual';

    public function handle()
    {
        $this->info("Iniciando migración de usuarios desde db_gimnasio_v2...");

        try {
            // Conexión directa a V2 usando variables de entorno o defaults
            $dsn = "pgsql:host=" . env('DB_HOST', '127.0.0.1') . ";port=" . env('DB_PORT', '5433') . ";dbname=db_gimnasio_v2";
            $pdoV2 = new PDO($dsn, env('DB_USERNAME', 'postgres'), env('DB_PASSWORD', ''));
            $pdoV2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Extraer usuarios de V2 haciendo JOIN con core.personas
            $stmt = $pdoV2->query("
                SELECT u.email, u.password_hash, u.estado, u.created_at, u.updated_at,
                       p.nombres, p.apellidos, p.numero_identificacion as cedula
                FROM seguridad.usuarios u
                LEFT JOIN core.personas p ON u.persona_id = p.id
            ");
            $usuariosV2 = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->info("Se encontraron " . count($usuariosV2) . " usuarios en V2. Copiando...");

            $insertados = 0;
            $actualizados = 0;

            foreach ($usuariosV2 as $u) {
                // Verificar si existe por email
                $existe = DB::table('seguridad.users')->where('email', $u['email'])->first();

                $datos = [
                    'name' => trim(($u['nombres'] ?? '') . ' ' . ($u['apellidos'] ?? '')),
                    'nombres' => $u['nombres'] ?? null,
                    'apellidos' => $u['apellidos'] ?? null,
                    'cedula' => $u['cedula'] ?? null,
                    'email' => $u['email'],
                    'password' => $u['password_hash'], // Mantener el mismo hash
                    'usr_estado' => ($u['estado'] === 'ACTIVO') ? 1 : 0,
                    'created_at' => $u['created_at'] ?? now(),
                    'updated_at' => $u['updated_at'] ?? now(),
                ];

                if (!$existe) {
                    DB::table('seguridad.users')->insert($datos);
                    $insertados++;
                } else {
                    DB::table('seguridad.users')->where('id', $existe->id)->update($datos);
                    $actualizados++;
                }
            }

            $this->info("Migración completada. Nuevos: $insertados, Actualizados: $actualizados.");

        } catch (\Exception $e) {
            $this->error("Error durante la migración: " . $e->getMessage());
        }
    }
}
