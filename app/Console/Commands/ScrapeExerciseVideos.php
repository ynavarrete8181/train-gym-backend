<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ScrapeExerciseVideos extends Command
{
    protected $signature = 'ejercicios:scrape-videos';
    protected $description = 'Busca videos funcionales en YouTube para cada ejercicio y actualiza la base de datos';

    public function handle()
    {
        $this->info('Iniciando búsqueda en toda la web (YouTube) para los ejercicios...');
        
        $ejercicios = DB::table('entrenamiento.ejercicios')->get();

        foreach ($ejercicios as $ej) {
            $query = urlencode("ejercicio " . $ej->nombre);
            $url = "https://www.youtube.com/results?search_query={$query}";

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
            ])->get($url);

            if ($response->successful()) {
                $html = $response->body();
                // Buscar el primer videoId en el código fuente de YouTube
                if (preg_match('/"videoId":"([a-zA-Z0-9_-]{11})"/', $html, $matches)) {
                    $videoId = $matches[1];
                    $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
                    
                    DB::table('entrenamiento.ejercicios')
                        ->where('id', $ej->id)
                        ->update(['url_recurso' => $videoUrl]);
                        
                    $this->info("Actualizado: {$ej->nombre} -> {$videoUrl}");
                } else {
                    $this->error("No se encontró video para: {$ej->nombre}");
                }
            } else {
                $this->error("Error de conexión para: {$ej->nombre}");
            }
            
            // Pausa breve para evitar bloqueos
            sleep(1);
        }

        $this->info('¡Búsqueda y actualización masiva completada!');
    }
}
