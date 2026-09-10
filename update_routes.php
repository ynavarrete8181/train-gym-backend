<?php
$file = 'routes/base/gimnasio.php';
$content = file_get_contents($file);

if (strpos($content, 'EntrenadorControlador::class') === false) {
    // Inject use statement
    $content = str_replace(
        "use Illuminate\Support\Facades\Route;",
        "use Illuminate\Support\Facades\Route;\nuse App\Http\Controllers\Api\Gimnasio\EntrenadorControlador;",
        $content
    );
    
    // Inject route
    $content = str_replace(
        "});",
        "    Route::apiResource('entrenadores', EntrenadorControlador::class)->only(['index', 'store', 'update']);\n});",
        $content
    );
    file_put_contents($file, $content);
}
