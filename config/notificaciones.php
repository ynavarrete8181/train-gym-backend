<?php

return [
    'canales' => [
        'interna' => true,
        'correo' => true,
        'push' => true,
    ],
    'colas' => [
        'correos' => 'correos',
        'push' => 'push',
        'segmentos' => 'notificaciones',
    ],
    'limites' => [
        // Protección transversal para cualquier correo procesado por la cola.
        // Puede ajustarse por ambiente sin modificar código.
        'correos_por_minuto' => max(1, (int) env('NOTIFICACIONES_CORREOS_POR_MINUTO', 10)),
    ],
];
