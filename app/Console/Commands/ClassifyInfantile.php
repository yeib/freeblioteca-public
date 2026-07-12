<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\ArchivalDeepDiveService;
use Illuminate\Console\Command;

class ClassifyInfantile extends Command
{
    protected $signature = 'books:classify-infantile {--limit=100 : Cantidad de libros a clasificar} {--force : Re-clasificar libros ya marcados}';

    protected $description = 'Usa IA para determinar si un libro es infantil/juvenil o para adultos';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $force = $this->option('force');

        $query = Book::processable()->where('is_paused', false);

        if (! $force) {
            $query->where('is_infantil', false)
                ->where(function ($q) {
                    $q->whereNull('classification_reason')
                        ->orWhere('classification_reason', 'NOT LIKE', '%Infantil Evaluado%');
                });
        }

        $total = $query->count();
        $count = $limit > 0 ? min($limit, $total) : $total;

        if ($count === 0) {
            $this->info('No hay libros pendientes de clasificación.');

            return;
        }

        $this->info("Clasificando {$count} libros de un total de {$total} con IA (Modo Batch Lento)...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Chunks más grandes ahora que usamos el balanceador central y modelos con mayor contexto
        $query->chunkById(40, function ($books) use ($bar, &$count) {
            if ($count <= 0) {
                return false;
            }

            $prompt = "Actúa como un bibliotecario especializado en literatura infantil y juvenil.\n".
                      "Determina si los siguientes libros son para NIÑOS o JÓVENES (infantil/juvenil) o si son para ADULTOS/GENERAL.\n".
                      "Criterios para INFANTIL: Cuentos infantiles, fábulas, libros de texto escolares básicos, literatura infantil (ej: Papelucho, Condorito, Disney), o juvenil (ej: Harry Potter).\n".
                      "Devuelve EXCLUSIVAMENTE un JSON ARRAY de objetos con: 'id' (integer) e 'is_infantil' (boolean).\n\n".
                      "Lista de libros:\n";

            foreach ($books as $book) {
                $prompt .= "- ID: {$book->id}, Título: '{$book->title}', Autor: '{$book->author}'\n";
            }

            try {
                // Usamos el API Hub Central unificado para aprovechar los workers vivos
                $classifications = ArchivalDeepDiveService::callAiHub($prompt, true);

                if (is_array($classifications)) {
                    $updated = 0;
                    foreach ($classifications as $c) {
                        if (isset($c['id']) && isset($c['is_infantil'])) {
                            $book = Book::find($c['id']);
                            if ($book) {
                                $book->is_infantil = (bool) $c['is_infantil'];

                                // Guardamos una marca para saber que ya lo evaluamos y no volver a procesarlo
                                $reason = $book->classification_reason ?? '';
                                if (! str_contains($reason, 'Infantil Evaluado')) {
                                    $book->classification_reason = trim($reason.' | Infantil Evaluado');
                                }

                                $book->save();
                                $updated++;
                            }
                        }
                    }
                    $this->info("\n   Lote procesado. {$updated} actualizados.");
                }
            } catch (\Exception $e) {
                $this->error("\n   Error en el hub: ".$e->getMessage());
            }

            $batchCount = $books->count();
            $bar->advance($batchCount);
            $count -= $batchCount;

            // Delay de seguridad (Timer) para cuidar las llaves gratuitas y workers
            $this->line("\n   Esperando 15 segundos para no saturar los workers...");
            sleep(15);
        });

        $bar->finish();
        $this->info("\nClasificación infantil completada.");
    }
}
