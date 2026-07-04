<?php
$images = [
    'BEB-001' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?q=80&w=600&auto=format&fit=crop',
    'BEB-002' => 'https://images.unsplash.com/photo-1600271886742-f049cd451bba?q=80&w=600&auto=format&fit=crop',
    'BEB-003' => 'https://images.unsplash.com/photo-1575037614876-c38556865f12?q=80&w=600&auto=format&fit=crop',
    'PROT-001' => 'https://images.unsplash.com/photo-1593095948071-474c5cc2989d?q=80&w=600&auto=format&fit=crop',
    'PREW-001' => 'https://images.unsplash.com/photo-1579722820308-d74e571900a9?q=80&w=600&auto=format&fit=crop',
    'BCAA-001' => 'https://images.unsplash.com/photo-1622830872826-681665a38a74?q=80&w=600&auto=format&fit=crop',
    'CREA-001' => 'https://images.unsplash.com/photo-1610488663806-0ceee0d86927?q=80&w=600&auto=format&fit=crop',
    'AGUA-001' => 'https://images.unsplash.com/photo-1548839140-29a749e1bc4e?q=80&w=600&auto=format&fit=crop',
    'GATO-001' => 'https://images.unsplash.com/photo-1622543925917-763c34d1a86e?q=80&w=600&auto=format&fit=crop',
    'QBAR-001' => 'https://images.unsplash.com/photo-1622484211147-5121b64ccdf7?q=80&w=600&auto=format&fit=crop',
    'TOAL-001' => 'https://images.unsplash.com/photo-1584852924976-1e642cb23f8b?q=80&w=600&auto=format&fit=crop',
    'SHAK-001' => 'https://images.unsplash.com/photo-1594882645126-14020914d58d?q=80&w=600&auto=format&fit=crop',
];

foreach($images as $code => $url) {
    echo $code . "\n";
    system("curl -sI \"$url\" | head -n 1");
}
