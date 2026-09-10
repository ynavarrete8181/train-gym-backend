<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Validator;

$data = ['id' => 1];
$rules = ['id' => 'exists:seguridad.users,id'];

try {
    Validator::make($data, $rules)->validate();
    echo "Exists success\n";
} catch (\Exception $e) {
    echo "Exists error: " . $e->getMessage() . "\n";
}
