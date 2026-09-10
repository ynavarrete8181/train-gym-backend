<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create('/api/test-planes/8', 'PUT', [
    'codigo' => 'PLAN-8',
    'nombre' => 'Deportivo Editado',
    'descripcion' => 'Ideal para deportistas edit',
    'tipo_duracion' => 'DIAS',
    'duracion' => 30,
    'precio_base' => 100.00,
    'tarifa_inscripcion' => 0.00,
    'activo' => true
]);

$response = app()->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";
