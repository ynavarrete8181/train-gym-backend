<?php
$file = 'app/Http/Controllers/Api/Gimnasio/DeportistaControlador.php';
$content = file_get_contents($file);

$content = str_replace(
    "return response()->json(\$deportistas);",
    "return \App\Support\ApiResponse::exito('Clientes consultados', \$deportistas->items(), [
            'pagina_actual' => \$deportistas->currentPage(),
            'por_pagina' => \$deportistas->perPage(),
            'total' => \$deportistas->total(),
            'ultima_pagina' => \$deportistas->lastPage(),
        ]);",
    $content
);
$content = str_replace(
    "\$porPagina = 15;",
    "\$porPagina = \$request->input('per_page', 15);",
    $content
);
file_put_contents($file, $content);
