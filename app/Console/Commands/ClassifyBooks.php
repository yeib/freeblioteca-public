<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ClassifyBooks extends Command
{
    protected $signature = 'books:classify {--limit=100 : Número de libros a procesar} {--force : Forzar la reclasificación incluso si ya tiene dewey_suggested}';

    protected $description = 'Clasifica libros usando Gemini y Groq';

    public function handle()
    {
        $limit = $this->option('limit');
        $force = $this->option('force');

        $this->info('Iniciando clasificación híbrida (Gemini -> Groq)...');

        $processedCount = 0;
        $classifier = new \App\Services\DeweyClassifierService();

        Book::processable()->where('is_paused', false)
            ->where(function ($q) use ($force) {
                if (! $force) {
                    $q->whereNull('dewey_suggested');
                }
            })
            ->where(function ($q) {
                $q->whereNull('dewey_code')
                    ->orWhere('dewey_code', '')
                    ->orWhere('dewey_code', '800')
                    ->orWhere('dewey_code', '000');
            })
            ->chunkById(500, function ($books) use (&$processedCount, $limit, $classifier) {
                $this->info("\nProcesando bloque de base de datos (".count($books).' libros)...');
                
                $booksToProcess = [];
                foreach ($books as $book) {
                    if ($limit > 0 && $processedCount >= $limit) break;
                    $booksToProcess[] = $book;
                    $processedCount++;
                }

                // Separar libros por estrategia: Local (Diccionario) o IA (Deep Rescue)
                $batchToApi = [];
                $localRescued = $classifier->classifyBatchLocally($booksToProcess);

                foreach ($booksToProcess as $book) {
                    // Si ya se clasificó localmente, saltar
                    if (str_starts_with($book->classification_confidence ?? '', 'auto')) {
                        continue;
                    }

                    $batchToApi[] = [
                        'id' => $book->id,
                        'title' => $book->title,
                        'author' => $book->author ?? 'Desconocido',
                        'description' => $book->description, // Para Deep Rescue
                        'is_zero' => ($book->dewey_code === '000' || empty($book->dewey_code))
                    ];
                }

                if (! empty($batchToApi)) {
                    // Si el lote tiene muchos libros en 000, usamos el rescate profundo
                    $classifier->processDeepRescue($batchToApi, $this);
                }

                if ($limit > 0 && $processedCount >= $limit) {
                    return false;
                }
            });

        $this->info("\nProceso masivo finalizado. Total procesados: {$processedCount}");
    }


}
