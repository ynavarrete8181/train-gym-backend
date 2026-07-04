<?php
$replacements = [
    'BEB-003' => 'https://images.pexels.com/photos/1283219/pexels-photo-1283219.jpeg?auto=compress&cs=tinysrgb&w=600', // Beer
    'BCAA-001' => 'https://images.pexels.com/photos/556828/pexels-photo-556828.jpeg?auto=compress&cs=tinysrgb&w=600', // Protein Shake
    'CREA-001' => 'https://images.pexels.com/photos/1435904/pexels-photo-1435904.jpeg?auto=compress&cs=tinysrgb&w=600', // Preworkout/supplement
    'AGUA-001' => 'https://images.pexels.com/photos/4000088/pexels-photo-4000088.jpeg?auto=compress&cs=tinysrgb&w=600', // Water
    'QBAR-001' => 'https://images.pexels.com/photos/7311029/pexels-photo-7311029.jpeg?auto=compress&cs=tinysrgb&w=600', // Protein Bar
    'TOAL-001' => 'https://images.pexels.com/photos/4108819/pexels-photo-4108819.jpeg?auto=compress&cs=tinysrgb&w=600', // Towel
];

foreach ($replacements as $codigo => $url) {
    DB::table('inventario.productos')->where('codigo', $codigo)->update(['imagen_url' => $url]);
}
