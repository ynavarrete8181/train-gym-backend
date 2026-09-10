<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
try {
    $count = Illuminate\Support\Facades\DB::connection('pgsql_v2')->table('socios.membresias')->count();
    echo "Count: $count\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
