<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\ArchivalDeepDiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateByTitle extends Command
{
    protected $signature = 'books:deduplicate-by-title {--limit=0}';
    protected $description = 'Deduplicate books by title and similar author names using AI';

    public function handle()
    {
        $this->info("Iniciando deduplicación por título y similitud de autor...");

        $dupes = Book::processable()->where('is_paused', false)
            ->whereNull('parent_id')
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->select('title', DB::raw('count(*) as total'))
            ->groupBy('title')
            ->having('total', '>', 1)
            ->get();

        $suspiciousGroups = [];
        foreach ($dupes as $d) {
            $authors = Book::processable()->where('title', $d->title)
                ->where('is_paused', false)
                ->whereNull('parent_id')
                ->distinct()
                ->pluck('author')
                ->toArray();

            if (count($authors) < 2 || count($authors) > 10) continue;

            $isSuspicious = false;
            for ($i = 0; $i < count($authors); $i++) {
                for ($j = $i + 1; $j < count($authors); $j++) {
                    $a1 = strtolower($authors[$i] ?? '');
                    $a2 = strtolower($authors[$j] ?? '');
                    $words1 = array_filter(explode(' ', str_replace([',', '.'], ' ', $a1)), fn($w) => strlen($w) > 3);
                    $words2 = array_filter(explode(' ', str_replace([',', '.'], ' ', $a2)), fn($w) => strlen($w) > 3);
                    if (!empty(array_intersect($words1, $words2))) {
                        $isSuspicious = true;
                        break 2;
                    }
                }
            }

            if ($isSuspicious) {
                $suspiciousGroups[] = ['title' => $d->title, 'authors' => $authors];
            }
        }

        $total = count($suspiciousGroups);
        $this->info("Detectados {$total} grupos sospechosos.");

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $suspiciousGroups = array_slice($suspiciousGroups, 0, $limit);
        }

        $chunks = array_chunk($suspiciousGroups, 15);
        $bar = $this->output->createProgressBar(count($suspiciousGroups));

        foreach ($chunks as $chunk) {
            $prompt = "Actúa como bibliotecario experto. Analiza estos títulos y decide si los diferentes nombres de autores listados se refieren a la MISMA PERSONA.\n" .
                      "Responde EXCLUSIVAMENTE con un JSON donde la llave sea el Título y el valor sea un array de grupos de nombres que son la misma persona.\n" .
                      "Ejemplo: {\"El Quijote\": [[\"Cervantes, Miguel\", \"Miguel de Cervantes\"]], \"Otro Libro\": []}\n\n" .
                      "Títulos a analizar:\n";

            foreach ($chunk as $item) {
                $prompt .= "- Título: '{$item['title']}', Autores: " . json_encode($item['authors']) . "\n";
            }

            try {
                $results = ArchivalDeepDiveService::callAiHub($prompt, true);
                if (is_array($results)) {
                    foreach ($results as $title => $groups) {
                        if (!is_array($groups)) continue;

                        // Si la IA devolvió un array plano de strings (ej: ["A1", "A2"]) en lugar de [["A1", "A2"]]
                        $isFlatArray = count(array_filter($groups, 'is_string')) > 0;
                        $authorGroups = $isFlatArray ? [$groups] : $groups;

                        foreach ($authorGroups as $authorGroup) {
                            if (!is_array($authorGroup) || count($authorGroup) < 2) continue;
                            
                            // El primer autor del grupo de la IA será el referente
                            $primaryAuthor = $authorGroup[0];
                            $secondaryAuthors = array_slice($authorGroup, 1);


                            $parentBook = Book::processable()->where('title', $title)
                                ->where('author', $primaryAuthor)
                                ->where('is_paused', false)
                                ->whereNull('parent_id')
                                ->orderBy('id', 'asc')
                                ->first();

                            if ($parentBook) {
                                Book::processable()->where('title', $title)
                                    ->whereIn('author', $secondaryAuthors)
                                    ->where('id', '!=', $parentBook->id)
                                    ->where('is_paused', false)
                                    ->whereNull('parent_id')
                                    ->update([
                                        'parent_id' => $parentBook->id,
                                        'classification_reason' => 'Fusionado por Título+Similitud de Autor (IA)'
                                    ]);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("\nError en AI Hub: " . $e->getMessage());
            }

            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->info("\nDeduplicación por título finalizada.");
    }
}
