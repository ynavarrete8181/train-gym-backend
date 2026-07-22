<?php

namespace App\Services\Personas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BackgroundRemovalService
{
    public function remove(UploadedFile $file): string
    {
        $apiKey = config('services.removebg.key');

        if (!$apiKey) {
            throw new RuntimeException('Configura REMOVEBG_API_KEY para quitar el fondo con IA.');
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->attach(
                    'image_file',
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName()
                )
                ->post(config('services.removebg.url'), [
                    'size' => 'auto',
                    'type' => 'person',
                    'format' => 'png',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('No se pudo conectar con el servicio de IA para quitar el fondo.');
        }

        if (!$response->successful()) {
            $message = $response->json('errors.0.title')
                ?? $response->json('errors.0.detail')
                ?? $response->json('detail')
                ?? $response->json('message')
                ?? 'No se pudo quitar el fondo con IA.';

            if (str_contains(strtolower($message), 'insufficient credits')) {
                $message = 'La cuenta de remove.bg no tiene creditos disponibles para quitar el fondo.';
            }

            throw new RuntimeException($message);
        }

        return $this->trimForeground($response->body());
    }

    private function trimForeground(string $pngContent): string
    {
        $image = @imagecreatefromstring($pngContent);

        if (!$image) {
            return $pngContent;
        }

        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);
        $visited = str_repeat("\0", $width * $height);
        $largestPixels = [];
        $largestBounds = null;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = $y * $width + $x;

                if ($visited[$index] !== "\0" || !$this->isVisiblePixel($image, $x, $y)) {
                    continue;
                }

                [$pixels, $bounds] = $this->collectVisibleComponent($image, $width, $height, $x, $y, $visited);

                if (count($pixels) > count($largestPixels)) {
                    $largestPixels = $pixels;
                    $largestBounds = $bounds;
                }
            }
        }

        if (!$largestBounds || count($largestPixels) === 0) {
            imagedestroy($image);
            return $pngContent;
        }

        $padding = 18;
        $left = max(0, $largestBounds['minX'] - $padding);
        $top = max(0, $largestBounds['minY'] - $padding);
        $right = min($width - 1, $largestBounds['maxX'] + $padding);
        $bottom = min($height - 1, $largestBounds['maxY'] + $padding);
        $newWidth = $right - $left + 1;
        $newHeight = $bottom - $top + 1;

        $output = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
        imagefilledrectangle($output, 0, 0, $newWidth, $newHeight, $transparent);

        foreach ($largestPixels as $index) {
            $sourceX = $index % $width;
            $sourceY = intdiv($index, $width);

            if ($sourceX < $left || $sourceX > $right || $sourceY < $top || $sourceY > $bottom) {
                continue;
            }

            imagesetpixel($output, $sourceX - $left, $sourceY - $top, imagecolorat($image, $sourceX, $sourceY));
        }

        $this->clearLowerSideArtifacts($output, $newWidth, $newHeight, $transparent);

        ob_start();
        imagepng($output);
        $trimmed = ob_get_clean();

        imagedestroy($image);
        imagedestroy($output);

        return $trimmed ?: $pngContent;
    }

    private function clearLowerSideArtifacts($image, int $width, int $height, int $transparent): void
    {
        $bottomStart = (int) floor($height * 0.84);
        $leftLimit = (int) floor($width * 0.16);
        $rightStart = (int) floor($width * 0.84);

        for ($y = $bottomStart; $y < $height; $y++) {
            $rowVisible = 0;

            for ($x = 0; $x < $width; $x++) {
                if ($this->isVisiblePixel($image, $x, $y)) {
                    $rowVisible++;
                }
            }

            if ($rowVisible < max(8, (int) floor($width * 0.10))) {
                continue;
            }

            for ($x = 0; $x <= $leftLimit; $x++) {
                if (!$this->hasVisibleVerticalSupport($image, $width, $height, $x, $y)) {
                    imagesetpixel($image, $x, $y, $transparent);
                }
            }

            for ($x = $rightStart; $x < $width; $x++) {
                if (!$this->hasVisibleVerticalSupport($image, $width, $height, $x, $y)) {
                    imagesetpixel($image, $x, $y, $transparent);
                }
            }
        }
    }

    private function hasVisibleVerticalSupport($image, int $width, int $height, int $x, int $y): bool
    {
        $minX = max(0, $x - 12);
        $maxX = min($width - 1, $x + 12);
        $minY = max(0, $y - 150);
        $maxY = max(0, $y - 36);
        $visible = 0;

        for ($scanY = $minY; $scanY <= $maxY; $scanY++) {
            for ($scanX = $minX; $scanX <= $maxX; $scanX++) {
                if ($this->isVisiblePixel($image, $scanX, $scanY)) {
                    $visible++;
                }
            }
        }

        return $visible >= 18;
    }

    private function collectVisibleComponent($image, int $width, int $height, int $startX, int $startY, string &$visited): array
    {
        $queue = [$startY * $width + $startX];
        $pixels = [];
        $bounds = [
            'minX' => $startX,
            'maxX' => $startX,
            'minY' => $startY,
            'maxY' => $startY,
        ];

        $visited[$queue[0]] = "\1";

        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            $index = $queue[$cursor];
            $x = $index % $width;
            $y = intdiv($index, $width);
            $pixels[] = $index;

            if ($x < $bounds['minX']) $bounds['minX'] = $x;
            if ($x > $bounds['maxX']) $bounds['maxX'] = $x;
            if ($y < $bounds['minY']) $bounds['minY'] = $y;
            if ($y > $bounds['maxY']) $bounds['maxY'] = $y;

            $neighbors = [
                [$x + 1, $y],
                [$x - 1, $y],
                [$x, $y + 1],
                [$x, $y - 1],
            ];

            foreach ($neighbors as [$nextX, $nextY]) {
                if ($nextX < 0 || $nextX >= $width || $nextY < 0 || $nextY >= $height) {
                    continue;
                }

                $nextIndex = $nextY * $width + $nextX;

                if ($visited[$nextIndex] !== "\0" || !$this->isVisiblePixel($image, $nextX, $nextY)) {
                    continue;
                }

                $visited[$nextIndex] = "\1";
                $queue[] = $nextIndex;
            }
        }

        return [$pixels, $bounds];
    }

    private function isVisiblePixel($image, int $x, int $y): bool
    {
        $rgba = imagecolorat($image, $x, $y);
        $alpha = ($rgba & 0x7F000000) >> 24;

        return $alpha < 118;
    }
}
