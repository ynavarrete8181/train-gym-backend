<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReviveCatalogoDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('inventario.producto_precios_sede') || ! Schema::hasTable('inventario.producto_stock_sede')) {
            throw new RuntimeException('Ejecuta primero las migraciones de inventario por sede.');
        }

        $sedes = DB::table('institucional.sedes')
            ->where('activo', true)
            ->where('maneja_inventario', true)
            ->where(function ($q): void {
                $q->whereRaw('LOWER(nombre) LIKE ?', ['%home%'])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', ['%xpadel%']);
            })
            ->get(['id_sede as id', 'nombre']);

        $home = $sedes->first(fn ($sede) => str_contains(mb_strtolower($sede->nombre), 'home'));
        $xpadel = $sedes->first(fn ($sede) => str_contains(mb_strtolower($sede->nombre), 'xpadel'));

        if (! $home || ! $xpadel) {
            throw new RuntimeException('No se encontraron las dos sedes requeridas: Revive Home y Revive Xpadel.');
        }

        $ahora = now();

        $categorias = [
            'Bebidas' => 'Bebidas del catálogo comercial Revive.',
            'Snacks / alimentos' => 'Snacks y alimentos del catálogo comercial Revive.',
        ];

        foreach ($categorias as $nombre => $descripcion) {
            DB::table('inventario.categorias_producto')->updateOrInsert(
                ['nombre' => $nombre],
                ['descripcion' => $descripcion, 'activo' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        $productos = [
            ['codigo' => 'REV-DEMO-001', 'nombre' => 'Agua con gas splendor', 'precio' => 1.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGQABAQEBAQEAAAAAAAAAAAAAAAYFAQQH/8QAKRAAAgEEAQEIAgMAAAAAAAAAAAECAwQFESESBhMVQlFhcYEiMRRBkf/EABcBAQEBAQAAAAAAAAAAAAAAAAABAgP/xAAaEQEAAwEBAQAAAAAAAAAAAAAAAQIRAzES/9oADAMBAAIRAxEAPwCpAAA5KSityaS9WdMTP5GFOjO0ptutJLleQtazachJnPW1GSktxafwdJ/s/k49P8a4lLvZSbUn+n7FAW1ZrOSRO+AAMqAAAAABHZeDhlrhS/v8l8FiSPaWovFX0+WmlI7cJy8MdI2ry2VOVS/t4xfLmvouCK7P1ovL0er3S37otSdp2+lIyAAHJsAAAAACEzTk8ldbTf5MuyMyKXiN0+G9vj7OnL1m3jw4uFRXtCUU9ua1/p9BI7GalcW/CTUvQsR0jJKzoADm0AAAAABE5WrGjkrnfLcnwWxmXeCsru5nXqRl1zWnqXG/X5NVt8pMamsdcxVzQj5u8SSX75ZcGTjsBa2MlOW61VS3GcuNfRrC9vqSIwABlQAAAAAAAAAAAAAAAH//2Q=='],
            ['codigo' => 'REV-DEMO-002', 'nombre' => 'Agua de coco', 'precio' => 1.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgAAAgMBAQAAAAAAAAAAAAAAAAQCAwUBBv/EADQQAAIBAwICBwYFBQAAAAAAAAECAAMRIQQSMUEFBhMiUWGxFDI0YnOBFVJxcsEjQpGS0f/EABcBAQEBAQAAAAAAAAAAAAAAAAABAgP/xAAZEQEBAQEBAQAAAAAAAAAAAAAAARECMSH/2gAMAwEAAhEDEQA/ANarVf2vLG18DytMmoO21NV9pCHN/CaWqa1UKRctn9JQ5UCxt/2YjZMtVFMqL3vg+UWfSM3eaw+80Fsz7SM2LYlNXZVXfSBupAM1qW2+kxSCgbc/aM07IVGDfJPhLFFOkCXUknnK2r0d1hb/AAYhOrz4q1LNWPabSNuPtKUupupII5iaNQKaZUIQ1sg4iAxGpft16fofUPqdEGqm7KSpPjCVdAfBN+8+ghKhbtX9prBjkAgRLUV1RQSMngL8Y3VW2vcfm/kRDV07Ku/HdIz4zDRR9QXfIKk8RePaS6qAPzC5vEQ5qlAQh2YDHkI/pqezTbmc3xjwlojqyM3uLgHhiZ9NxTqjmwzmP63f3UABR2/iJLsFIpVuHxtbkIMOjUPWftG2gAYtFb3k6ClUq2yoxuBtylcpXo+r/wAC37z6CEOr3wLfUPoISsqa52a+m3jaU66krhw2QTm8v6QU9xhyNpRWVgzKeHGc22dQ0tJKoOTnFzHaduxZWN8i0qVLmWVLpRW3AteWojrE3owuQeRHKZ9GjV3gNtIvxM09QS1MGwzbMVFwwtcWlhqRXs9JVCk2HHzzEd0c1JPspzlmzE7WljL0vV030DfUPoITnVz4BvqH0EJRPVLvQC390o1gsqtyYCOVwy0ywF2U3tM+tU7VO9i3ATm6UuhXtBwk6zK1ArcYIMoCWbdfIl1KmXZgSDfMrKNYgKBe1hKaQLG1+MlqCWJG3M5TQL2VxxOZqI5q7OigH3RF2QAD9I3rk/qkp7pzF2Q2F/CWJW71eFtC31D6CEl0ChXQm44uSIQHqlJanvX+0ofo+g/EMD5GEIw1E9F6c2vvx806vRunX3d487whA4ei9OeO/wD2h+F6fcD38fNCEAfovTPa+/HzSP4TpbgkObci0IQHUVUUKgAUYAEIQgf/2Q=='],
            ['codigo' => 'REV-DEMO-003', 'nombre' => 'Agua Gar', 'precio' => 0.50, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGQABAQEBAQEAAAAAAAAAAAAAAAUEAgMG/8QAKBAAAgIBAwMDBAMAAAAAAAAAAAECAxEEITEFElETInEUMjNBYYGh/8QAFwEBAQEBAAAAAAAAAAAAAAAAAAIBA//EABgRAQEBAQEAAAAAAAAAAAAAAAABAiEx/9oADAMBAAIRAxEAPwD6kAADL1HUT02knbXFSkmkk1/JqJt19upunVSkoQeG3+2B49I6lqtZqJ16iuMYpZTSaLBPrrupl3ZX9G2uffBS4A7AAAAAAAAISvnXfiqC9zeUs+ccl0g2U3u7tUc1ruePLbMqN2zxoesm9nlJ7J4/fg26CcrNMpTSUsvKRMsouis+m38Lf/Sh0xTWkXqJqTk3hmRObbetgAKdQAAAAAJtft1dsXv7tikT5JvqE/GEBoeGuDuhYr+W8HMuNz0p/FH4A7AAAAAAAAMOF9TZLnJuJU7lp9TYp5Sbz8ganuzRV9mPDwTK+oQt7lFNdu3Bv0jlKlTksOTyB7gAAAAAAAHLhGTTlFNrhtAAcwoqrbcK4RcuWorc9AAAAAAAD//Z'],
            ['codigo' => 'REV-DEMO-004', 'nombre' => 'Benefit Imperial Immune 330ml', 'precio' => 1.50, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgABAAMBAQEAAAAAAAAAAAAAAAQFBgECA//EAC8QAAIBAgMFBgYDAAAAAAAAAAABAgMRBBIhBTVBYXEGEzFRscEUIjI0UnIkM4H/xAAWAQEBAQAAAAAAAAAAAAAAAAAAAgH/xAAYEQEBAQEBAAAAAAAAAAAAAAAAAQIxEf/aAAwDAQACEQMRAD8A1IAA8VaipUpTauoq5FhjlJOUo5VbQ5trddfVrReHUz1CvWw0lndRRadlfR6BlvjQrHWh81lLpofXC4pYiUo5XGUTPYbG1O4rKs5Ob+l+RJ2BJzx9RtvWnxfMMmvWhAAUAAAAAIO2d2Vui9SkpU5qCpOFOpFeEJS1fnZ+xd7Z3ZW6L1KPCSU8O3XjeCf1RfzK3GxG01zFK1WorW0WnFaceZI7O/eVP09xiYUMtZ3gpPVZW7s9dnl/Lq/p7m54ydaEAFLAAAAAELa+7a3RepQ7PTUJaQnGTayN2fhwL7a+7q3RepQ4Oyws8yU43bcLeXFPzJ1xOnrE/wBlRWskkkvJWJXZ77mr+nuRe6jGVVRUnCycW+ZM2BDLXq6pvLw6mzjJ1egA1YAAAAAh7W3dW6L1KPCRWHjnc5ZZu2a1425o0WLofEYadK9nJaMzvweOwc5JQbi/x1TJ1PYnTs1erWcpK19Hy4E3Ydu/q2/Hx/0gwo4qtnbozcpcrFtsnA1MLnqVmlKatlXA2Rk6sgAasAAAAAAABw6AAAAAAAf/2Q=='],
            ['codigo' => 'REV-DEMO-005', 'nombre' => 'Cielo', 'precio' => 1.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGQAAAwEBAQAAAAAAAAAAAAAAAAMEAgUB/8QAMhAAAQQAAwUGBQQDAAAAAAAAAQACAxEEEiEFMTNBcRMiMlFywRRhgZGhI0NisVKS0f/EABkBAAMBAQEAAAAAAAAAAAAAAAIDBAABBf/EAB4RAAICAgMBAQAAAAAAAAAAAAABAgMRMRIhMlFB/9oADAMBAAIRAxEAPwCrEyOlne5x50PkEulqTiO6lMw8BldV1qkbZ6LajEiOIqRrMh1NWqOzdJ3WkAndZXSdhGuvw3W/KF5BC1pfYstRccE1dsuL5M5LMLLHJnc4FosGitvORt711hGx+haFP2AEjspFN8xazQyNvXZzYpu0cW5aoJipGHzDu19khwo0ULGwnyR1tmSOkw5DjeU0ChY2TwH+r2Qmx0RWLE2c5/Ed1KrwA731Uj+I71FPw0wieMw0IJ3+QQR2U3eDot8SWwU6XqlRYxhlkzWGhwaLrelR4sl0xy6XoOe67KNkkSuIapR/eU+Exv6LXFvecCQLJ18vyg4oNZIXjUi9Pr/xcYQ/DDR3RQSeIp2HxbWw26y4tuiRrrSS/UkoGPp2zo7K4D/V7IRsrgP9SE2OhFvtnPfxHdSm4aRrZmte2w6/okv4jupW4GOdNGWgkAm0iTa0UX+CuN8BkcHRga7/ADWWyMue23lNhTMhnbMdSW3zTWtJ7catJXYybfZPV3nIzDTwyGstHySYJG4ifERub3wdL8lnCx9nJdgnzpKa2Vk+Ikjtp5Or5ox3CPeCuNkcUJkcGnQ1X9KJjy+JrzvcLXrJcTK3LJ3mgHc2liHSCMfxCFh1R4nW2VwH+r2QjZPAf6vZCbHRNb7ZzZOI7qVVgLz6E/ZSzNLJntcKIcVXsw051pUdlN3dZWc16E3fILDDrLu380wSNz715H+56k1kkRbSQ7QtHVYNjta/pOZvSS7L22Y80LDMQXmOpqt1KJ/iKugkaQdVATqlsfTtnU2TwH+r2QvdlNIw7iRoXaITo6J7fbKJcNDMbkYCfNYGBw43MP8AsUIWwgVKS6ye/BQUBlND+RW2wRtblA06oQtg5yf0BBG3cPysOwcDiSWGz8yhC2DKTX6efA4f/A/coGAwwPD/ACUIWwjvOX0oADQABQHJCELoJ//Z'],
            ['codigo' => 'REV-DEMO-006', 'nombre' => 'Imperial 560ml', 'precio' => 1.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgABAAMBAQEAAAAAAAAAAAAAAAMEBQIBBv/EACQQAAICAgIBAwUAAAAAAAAAAAABAhEDBBIhQSIxUTJSYXGh/8QAGAEBAQEBAQAAAAAAAAAAAAAAAAECAwT/xAAaEQEAAgMBAAAAAAAAAAAAAAAAAQIDETEh/9oADAMBAAIRAxEAPwD6kAADnJOOOPKbpfJ0QbUFkxcW67Cx31Jjywyq4StHZX08SxQcU2/2WATrfgAAgAAAAAEG26xonKm7Lx8Ik8apG7PdN3FotFDTnUo35bRfETuFyRqwACsAAAAAAZ2zK3kk/blRomFs3KHckvW27Zm3HbDXcrOJ+iTXimaadpP5MLWX11Lpxfk2dZ3r42/tRKtZq6lKADbzgAAAAAYexGcMkop2lJ9G4VdjTWbJyUuN+/Rm0bdcV4rPrNx87dPijY11xwQX4K0NCMX3NuP9LiVKkKxMdXLeLcegA04gAAAAAAAAAAAAAAAP/9k='],
            ['codigo' => 'REV-DEMO-007', 'nombre' => 'Michelada', 'precio' => 2.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgAAAgMBAQAAAAAAAAAAAAAAAAQCAwUBBv/EADAQAAIBAgMFBgUFAAAAAAAAAAECAAMRBCExBRJBUZETMlJhcXIVIjSBwSMkM0Kx/8QAGQEAAwEBAQAAAAAAAAAAAAAAAQIDBAAF/8QAIREAAgICAwACAwAAAAAAAAAAAAECEQMxBBIhE1EiMkH/2gAMAwEAAhEDEQA/ANx6+8WHC+UQxmI3N0AnPjwnKlW1RvUwp1FapulQ1wbXiym1G0GMU36cGHrORYrY2zaWrgypJFU3J4SFjuhd7ICwgpFp58uQ2zQoUQrYWray1MhzMoajXKlTu6ZG2seUidJFoFyf5R3RmZUojf7MFlqZanI+UjhGZapzIIHOaeJYU8M77oJGlxxmVQPzknUzZjn3jZKSSZ6XAVmq0LubkG1+cJVsr6dvdCWWhHsyKp/Vf3GRpPbEUvW0jWa1V/cZUWsytyN4GrVHLw0mOcpqYilRQGo4W+nMyyobEnhrPPsxr1C7ZknLyE87Fh7t2aZT6o102lhma2+R6i0bLiwN9TlPNVFUd1gZpbIrMUak2i5r6R8vHUV2QITt0x7Ht+2tzYRGkbExnHt8qDzvFFNpbjqsZOf7Hodjm+Hb3fiEjsM3wr+78QmlaJvZjVge1fI94/7K2BtpNh6Cl2zOsg+EpuAGLdYLOoXU71JTzExeyNIuCSGVrC5yM3KtMUiEW9gOMSxGGp4gneyYaMJihNY5u9F3HtFGZct4NOAmjstW+d2tbujL7ytNmoGu9QkcgLR+mqooVRYDQRs2ZSjSOhBp2ynFm9QDkIuRYTVGFpVAGctc8jAYOiNC3WWxuoJE5L8hnYP0r+/8QjOzqa06TBdN6EstCPYu194+s4YNk7A85EsBEGKcSNDFNCY1XYMotwixmHKqmy8H4EkshJKZJjja5KPSSGcqRw0sUjnN6XhnZoYHKm3rCGB/iJ84S8dE3snVw9Oqbm4PMSo4Ckf7P1hCdQCJ2ZRJ7z9Zz4VQ8T9YQiuEX60FSaOfCsP4qnWd+F0PE/WEIPih9B7y+zo2ZQGjP1khs+iDq5+8IR6QLGVUIoVRYDhCEIQH/9k='],
            ['codigo' => 'REV-DEMO-008', 'nombre' => 'Postre', 'precio' => 2.50, 'categoria' => 'Snacks / alimentos', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGQABAQEBAQEAAAAAAAAAAAAAAAECAwQG/8QAIBABAAIBBAMBAQAAAAAAAAAAAAECEQMTMmESMYEhQf/EABYBAQEBAAAAAAAAAAAAAAAAAAACAf/EABcRAQEBAQAAAAAAAAAAAAAAAAABETH/2gAMAwEAAhEDEQA/APobWm0oItaiJa0V9tGhInMZGCmUAd9O3lX9E0eP0TUOSKiljzzOZy9E+peZUbHekx4x+w1ExPqXndtOvjXtlhWgBjto8foaPH6IvUVyRUUsn1LzPTPp5lRsd9OI8YnDTEWitIy3ExaMwxgADto8foaPH6IvUVyRZ9opYxfTzOYbGjE0maxH9haV8YaAAAdtHj9DR4fRF6irbTracptV7BmhtV7NqvYN2mm1Xs2q9gbTTar2bVewNNbiMRiAGD//2Q=='],
            ['codigo' => 'REV-DEMO-009', 'nombre' => 'Powerade azul 600ml', 'precio' => 1.00, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgABAAMBAQEAAAAAAAAAAAAAAAIEBQYBA//EAC4QAAIBAwIEAwcFAAAAAAAAAAABAgMEEQUSITFRYRMiQQYUMjNygZEjNHGSof/EABkBAQADAQEAAAAAAAAAAAAAAAACAwQBBf/EAB4RAAMBAAICAwAAAAAAAAAAAAABAgMRMQQhEiJB/9oADAMBAAIRAxEAPwDqQAACjqNGhcRUK9RRX1YLF1WVCjKb+y7nPz3V6znUeW+pZEfIo22WZYo6bZUqqnC5y+80bkcbVh57nNzopLKL+k3Mo4pTeYvl2ZK8uFyivLyVb4ZrgApNYAAAAABk6zcRhWoUWm92ZcDIuLxUaqjsk89MFr2l/e230My538lcU4yhDGcZ6fz1LJ0crhFN4zb5ZYnfrb8uX+EbLVKSqSTjNNLPLoWHcWu3hUpqL9cJbcdvUx/E3ahUnTw/LwxyfAPWmcXj5ro7+Et0Iy6rJIhS+VD6UTKy8AAAAAAw/aCluuLao38OVjBiV7J1K6mqkV22nQ68v06UsZWWjKpvj6r7mjOJqeWY9taiuEU52U5Rwp0/6kLbTpKcpOrHOPRGlJNxfFNd0fO2peJUwnhvgibylIqne3+nU01inFdEiZGKxFLoiRkPRAAAAAAKeq0vFsp4xmPmOfjzwdPcR30KkU8Zi+Jy8U88DTg/XBi8pe0z7x+E90+Cne0o+m7P4GNtJn10hR97g5c8PBbb+rM2PuzfPTw9MJ6wAAAAABGcVODi+TWGUVpNuuUp/kA6qa6I1E12iT0ui1hyn+SVtp1G2qKcHNtLHmYB13T7ZFZQukXAARLAAAD/2Q=='],
            ['codigo' => 'REV-DEMO-010', 'nombre' => 'Powerade Grande 1000ml', 'precio' => 1.50, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgAAAgMBAQAAAAAAAAAAAAAAAAQCBQYDAf/EADEQAAIBAwIDBgUDBQAAAAAAAAECAwAEERIhBRMxFCJBUXGRFTJCYYFiobEGUnLB0f/EABgBAQEBAQEAAAAAAAAAAAAAAAACAQME/8QAHhEAAwACAwEBAQAAAAAAAAAAAAECAzETIVEREkH/2gAMAwEAAhEDEQA/ANTRRUJpUhiaWQ6UUZJoCM88dvEZJWwo/eqFZZrhy0QVQCcZOKreIcaku5dawkxA4QE1JOImNdMpSJ/FQpYj13rtDiV3s4ZFkb+Sh6aa6j2Yj8HNWXC79JgY5MJJnYedZ5rq6eQd2IwkaubvgD7/APK8hvRJJiMo58AMq3qM1VVjpfCIWWWbWikOG3pnHKmKiZVDYB+ZfOn6856gooooArN/1Tds7x2ER3bvP/qtJWNWKTiPEru4VkGG0qXbHXp/FAKxaeesSfQpKjzONvzUUkt1GCpONt4hnP33roOFzF1Ms8MLMxVFdtyQcbfmngJIkiWa1t5pHlMRbx1Dz2oDjFaPLYTOkblGdXA0jw64HtSSzW6Sbrp+/KGQfer3t12DPHyooxAVUnUcHPTG1QuY0JKSLaGfWAQDvkn0oCeSjQ3ZBDBQyuwIz4EbeYq/ikWaJJEOVcAiqg3bCApcxxSqraGMb50n08Kc4TKrwPGi6VibSoPlQD1FFFAQlOInP6TWO4bKY7SUrCJX5+rS3TYbH3rZsAylT0IxWQuo/hvEGto5QEfvEkdK2Um+zKbS6OmkXPZZrhJxNASSFUEMdWfOiOa5RrnNq5eaQtAMg6WII338qh26KI4dz+Fr0cTtuZEwmxpfJODsOldqiEvqZ5py5XSTXR7ctddhgVoDrWRDO6sDnGw8aavWlZmmZbgKjhzHoQ4APmDmqu2vYFjnXuQ65F+XJ1DO5rq1xardzXC3od5+4cIRoU9T7VwPUONzFguSbdohJNr14HfU9M77GrDgZ79wP8T+1Ul87StqhuLdl66lBDYHQHPWtDwa25Nqspcu8oBJoCwooooDldOyW0rp8yqSPWsjaxLdxST3TSyTayCRnptWyYBlIPQjFZQW13ayXEcTBQW1DetSb0Y2lsU7JC2dS3Gn+d6X02MZCyB9QGG67GmeVxTOFlXHqKXk4ZeSOWfQWP6qr8V4Ry41/QAsHkYKrBfA75pW5EKykQDKeBOaei4bMikNpB9a4vw2ckldGPWn4rwcsenKy5bOUkVT5VruBtKC0RLGEKCurfB8s1lobCZXycA+GDWt4HHMluxm6E930rHLW0UqVaZZ0UUVJQUpLYRSzc0lg32NFFam1oxpPZH4ZDn5n96PhsP9z+9FFVyV6c+HH4B4ZCfqf3qHwmDOdUnvRRTkr03ijwmOGwDxY+tNRoI0VF6AUUVLpvZSlTonRRRWFH//2Q=='],
            ['codigo' => 'REV-DEMO-011', 'nombre' => 'Powerade pequeño 350ml', 'precio' => 0.75, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgABAQACAwAAAAAAAAAAAAAAAAYDBQECBP/EAC8QAAIBAwIDBQcFAAAAAAAAAAABAgMEEQUhEjFRFDRBYXITJDJxgbHBIjNic5H/xAAYAQEBAQEBAAAAAAAAAAAAAAAAAQIEA//EAB4RAQEAAgICAwAAAAAAAAAAAAABAhEEMTJBEiEi/9oADAMBAAIRAxEAPwCpAAA0+u6ncafOiqEYNT5uSybgndev6cqsKFCUXNPL2TTFWTd0WOtXlfUKdCcKfBL4sLdIoiVsr1W97F3PBFSSSSS6+RUQnGpBShJSi+TRJdrlj8fbsACsgAAAADHX/Yqel/YkKt0u0zi6UW09uW68ywrLNKazj9LIK7oVO11HxRznPD4kreEnubbl0lso04Ry0lhZ3XX6YN1pS93n/Y/wRPs6zW9RQz4cXMtdIjw2STbznfPUu0uNj3AAMgAAAADgkNTgu31MLlj7FgSOqd+q/Qxn06uL5V5IwXEvJosrPu0H13I+HxL5osLLulL0mcO2+V4xnAB6uIAAAAAcN4RJalJSvajXjhlXW3ozX8WRN22r2rxN54jzzdXGn3a7QwpL5osLJ+7QXTYiajTaw/8ACx0vPZnxbty/CJh23yZ+Y9gAPVxAAAAAAYalpb1HmpQpyfVxQAN6dY2NpF5VtST9CM6SSwlhABbbXIACAAA//9k='],
            ['codigo' => 'REV-DEMO-012', 'nombre' => 'Sporade ponche de frutos 625ml', 'precio' => 0.75, 'categoria' => 'Bebidas', 'imagen_url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABkRExYTEBkWFBYcGxkeJT4pJSIiJUw3Oi0+WlBfXllQV1ZkcJB6ZGqIbFZXfap+iJSZoaKhYXiwva+cu5CeoZr/2wBDARscHCUhJUkpKUmaZ1dnmpqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampqampr/wAARCABIAEgDASIAAhEBAxEB/8QAGgABAQEBAQEBAAAAAAAAAAAAAAYFBAMCAf/EAC8QAAICAQIDBAkFAAAAAAAAAAECAAMRBBIFBjEhUWFxExYlMjNBkbHRUmKBocH/xAAZAQEAAwEBAAAAAAAAAAAAAAAAAQIDBAX/xAAfEQACAgICAwEAAAAAAAAAAAAAAQIDESEEMRIiMkH/2gAMAwEAAhEDEQA/AKmIiAJg8yWFkrrrOGBJLBsY8Jt3P6Ol3/SpMlrdJZq1V7Lu09wlZP8ACPLDWji0DtTq6rSzWqrZK7ustqbUuqWytgyMMgiSlPDLqXJR1APZ2gn/ACanLtlgOposbcFbcCfHr9oiWlNN6WDaiIliBERAEREA8tShs09qL1ZCB9JJ00AswqvsqdDhlJ6GVt6s9Nio21mUgHuMh9TXssaqxFV1bBI6585SZ0Ux8k1o9b7blYo+qswOuRgzW5WQk6i4bzW2FDMc7iM5k6a0PZtz5ym5X071UW2ZHo3ICqO8dTIjjJWyhx9mzdiImhiIiIAiIgH5I3io9p6gfvMs5G8WOeK34+TTOzo6+J9s5SNo8DKrgA9mIe9m+8lW6Sp5fOeFp4Mw/uVr7OjlrFZpxETY8wREQBERAPlmCKWboBkyJvsOo1NlwHxHJA+ctL+yizGPdPXykPgY8fKZWHfwo7bPRqbce4Zucs35qt056odw/mYBUYmvyycaq1RjBTt+spB7OjlRbreSkiInQeQIiIAiIgHy6b1KkkA90yhy/pR0sux3ZH4iJDSfZeFkofLwPV/S4+Jd9R+J06DhdOhdnqZyWGDuIiIUUiZXTksNndERJMxERAP/2Q=='],
        ];

        foreach ($productos as $item) {
            $categoriaId = DB::table('inventario.categorias_producto')->where('nombre', $item['categoria'])->value('id');

            DB::table('inventario.productos')->updateOrInsert(
                ['codigo' => $item['codigo']],
                [
                    'categoria_id' => $categoriaId,
                    'proveedor_id' => null,
                    'nombre' => $item['nombre'],
                    'descripcion' => 'Producto demo cargado desde el catálogo Revive Sports / Home para pruebas de desarrollo.',
                    'marca' => null,
                    'imagen_url' => $item['imagen_url'],
                    'unidad_medida' => 'UNIDAD',
                    'precio_costo' => 0,
                    'precio_venta' => $item['precio'],
                    'stock_actual' => 40,
                    'stock_minimo' => 5,
                    'controla_stock' => true,
                    'maneja_lotes' => false,
                    'activo' => true,
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ],
            );

            $productoId = DB::table('inventario.productos')->where('codigo', $item['codigo'])->value('id');

            foreach ([$home, $xpadel] as $sede) {
                DB::table('inventario.producto_precios_sede')->updateOrInsert(
                    ['producto_id' => $productoId, 'sede_id' => $sede->id],
                    [
                        'precio' => $item['precio'],
                        'activo' => true,
                        'updated_at' => $ahora,
                        'created_at' => $ahora,
                    ],
                );

                DB::table('inventario.producto_stock_sede')->updateOrInsert(
                    ['producto_id' => $productoId, 'sede_id' => $sede->id],
                    [
                        'stock_actual' => 20,
                        'stock_minimo' => 5,
                        'updated_at' => $ahora,
                        'created_at' => $ahora,
                    ],
                );
            }
        }
    }
}
