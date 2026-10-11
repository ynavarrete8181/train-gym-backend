<?php
$file = 'app/Http/Controllers/Api/Gimnasio/PlanControlador.php';
$content = file_get_contents($file);

$content = str_replace(
    "return response()->json(\$planes);",
    "return \App\Support\ApiResponse::exito('Planes consultados', \$planes->items(), [
            'pagina_actual' => \$planes->currentPage(),
            'por_pagina' => \$planes->perPage(),
            'total' => \$planes->total(),
            'ultima_pagina' => \$planes->lastPage(),
        ]);",
    $content
);
$content = str_replace(
    "\$planes = DB::table('gimnasio.planes')\n            ->orderBy('nombre')\n            ->get();",
    "\$planes = DB::table('gimnasio.planes')\n            ->orderBy('nombre')\n            ->paginate(\$request->input('per_page', 10));",
    $content
);
file_put_contents($file, $content);
