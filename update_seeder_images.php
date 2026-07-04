<?php
$content = file_get_contents('/Users/ynavarrete8181/Documents/Desarrollo/Revive/train-gym-backend/database/seeders/InventarioSeeder.php');

$replacements = [
    "'https://images.unsplash.com/photo-1575037614876-c38556865f12?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1695285406073-0424d330c075?q=80&w=600&auto=format&fit=crop'", // Michelada
    "'https://images.unsplash.com/photo-1622830872826-681665a38a74?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1726842348600-c66c2e2797b4?q=80&w=600&auto=format&fit=crop'", // BCAA
    "'https://images.unsplash.com/photo-1610488663806-0ceee0d86927?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1732563290993-602bb95108d2?q=80&w=600&auto=format&fit=crop'", // Creatina
    "'https://images.unsplash.com/photo-1548839140-29a749e1bc4e?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1664527305901-db3d4e724d15?q=80&w=600&auto=format&fit=crop'", // Agua
    "'https://images.unsplash.com/photo-1622484211147-5121b64ccdf7?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1726676075271-d08aef815d79?q=80&w=600&auto=format&fit=crop'", // Quest bar
    "'https://images.unsplash.com/photo-1584852924976-1e642cb23f8b?q=80&w=600&auto=format&fit=crop'" => "'https://images.unsplash.com/photo-1679430887821-ddbcff722424?q=80&w=600&auto=format&fit=crop'", // Toalla
];

$content = str_replace(array_keys($replacements), array_values($replacements), $content);
file_put_contents('/Users/ynavarrete8181/Documents/Desarrollo/Revive/train-gym-backend/database/seeders/InventarioSeeder.php', $content);
echo "Seeder updated.";
