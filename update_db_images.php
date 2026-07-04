<?php
$replacements = [
    'BEB-003' => 'https://images.unsplash.com/photo-1695285406073-0424d330c075?q=80&w=600&auto=format&fit=crop', // Michelada
    'BCAA-001' => 'https://images.unsplash.com/photo-1726842348600-c66c2e2797b4?q=80&w=600&auto=format&fit=crop', // BCAA
    'CREA-001' => 'https://images.unsplash.com/photo-1732563290993-602bb95108d2?q=80&w=600&auto=format&fit=crop', // Creatina
    'AGUA-001' => 'https://images.unsplash.com/photo-1664527305901-db3d4e724d15?q=80&w=600&auto=format&fit=crop', // Agua
    'QBAR-001' => 'https://images.unsplash.com/photo-1726676075271-d08aef815d79?q=80&w=600&auto=format&fit=crop', // Quest bar
    'TOAL-001' => 'https://images.unsplash.com/photo-1679430887821-ddbcff722424?q=80&w=600&auto=format&fit=crop', // Toalla
];

foreach ($replacements as $codigo => $url) {
    DB::table('inventario.productos')->where('codigo', $codigo)->update(['imagen_url' => $url]);
}
