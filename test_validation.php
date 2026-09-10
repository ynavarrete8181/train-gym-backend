<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$data = ['codigo' => 'PLAN-8'];
$rules3 = ['codigo' => 'unique:pgsql.gimnasio.planes,codigo,8'];
$rules4 = ['codigo' => Rule::unique('pgsql.gimnasio.planes', 'codigo')->ignore(8)];

try {
    Validator::make($data, $rules3)->validate();
    echo "Rule 3 success\n";
} catch (\Exception $e) {
    echo "Rule 3 error: " . $e->getMessage() . "\n";
}

try {
    Validator::make($data, $rules4)->validate();
    echo "Rule 4 success\n";
} catch (\Exception $e) {
    echo "Rule 4 error: " . $e->getMessage() . "\n";
}
