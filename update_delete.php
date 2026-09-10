<?php
$ctrl = file_get_contents('app/Http/Controllers/Api/Gimnasio/PlanControlador.php');
if (strpos($ctrl, 'destroy') === false) {
    $destroyMethod = '
    public function destroy($id)
    {
        $plan = DB::table(\'gimnasio.planes\')->where(\'id\', $id)->first();
        if (!$plan) {
            return response()->json([\'mensaje\' => \'Plan no encontrado\'], 404);
        }
        
        $enUso = DB::table(\'gimnasio.membresias\')->where(\'plan_id\', $id)->exists();
        if ($enUso) {
            return response()->json([\'mensaje\' => \'El plan no se puede eliminar porque tiene membresías asociadas.\'], 409);
        }

        DB::table(\'gimnasio.planes\')->where(\'id\', $id)->delete();
        return \App\Support\ApiResponse::exito(\'Plan eliminado exitosamente.\');
    }
}';
    $ctrl = preg_replace('/}\s*$/', $destroyMethod, $ctrl);
    file_put_contents('app/Http/Controllers/Api/Gimnasio/PlanControlador.php', $ctrl);
}
