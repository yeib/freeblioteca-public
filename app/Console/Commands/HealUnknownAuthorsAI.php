<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;

class HealUnknownAuthorsAI extends Command
{
    protected $signature = 'books:heal-unknown-authors-ai {--limit=100} {--delay=0}';
    protected $description = 'Intenta descubrir el autor real de libros con autor desconocido. Usa título primero, luego descripción como fallback antes de rendirse.';

    public function handle()
    {
        $limit = $this->option('limit');
        $delay = (int) $this->option('delay');
        $this->info("Iniciando rescate de autores desconocidos (Límite: $limit, Delay: {$delay}s)...");

        $badAuthors = ['desconocido', 'autor', 'anónimo', 'anonimo', 'sin autor', 'unknown', 'varios', ''];

        $booksQuery = Book::processable()
            ->where(function ($query) use ($badAuthors) {
                $query->whereIn(\DB::raw('LOWER(author)'), $badAuthors)
                      ->orWhereNull('author');
            })
            ->whereNotNull('title')
            ->whereRaw('LENGTH(title) > 4')
            ->where(function ($q) {
                // Excluir solo los que ya fracasaron en AMBAS etapas
                $q->whereNull('classification_reason')
                  ->orWhere('classification_reason', 'not like', '%Autor rescatado%');
            });

        if ($limit > 0) {
            $booksQuery->limit($limit);
        }

        $totalAvailable = $booksQuery->count();
        
        if ($totalAvailable === 0) {
            $this->info('No hay autores desconocidos pendientes de rescatar.');
            return;
        }

        $targetCount = $limit > 0 ? min($totalAvailable, $limit) : $totalAvailable;
        
        $rescued   = 0;
        $failedOne = 0;
        $failedAll = 0;

        $bar = $this->output->createProgressBar($targetCount);
        $bar->start();

        // Usamos cursor() en lugar de get() para no reventar la memoria RAM
        $books = $booksQuery->cursor();

        foreach ($books as $book) {
            $originalAuthor = $book->author ?? 'Vacio';
            $title          = $book->title;
            $description    = trim($book->description ?? '');

            // ════════════════════════════════
            // ETAPA 1: Solo título
            // ════════════════════════════════
            $prompt1 = "Actúa como un bibliotecario experto e historiador de la literatura. " .
                       "Tengo un libro cuyo autor registrado es '{$originalAuthor}' o está vacío, pero su título es '{$title}'. " .
                       "Identifica al autor real de esta obra literaria.\n\n" .
                       "REGLAS:\n" .
                       "1. Devuelve EXCLUSIVAMENTE un JSON con el campo 'author'.\n" .
                       "2. El nombre del autor debe estar en formato 'Apellido, Nombre' (ej: 'García Márquez, Gabriel').\n" .
                       "3. Si la obra es históricamente anónima (ej: El Lazarillo de Tormes, La Biblia, Las mil y una noches), devuelve 'Anónimo'.\n" .
                       "4. Si detectas que la obra tiene claramente múltiples autores, devuelve 'Varios'.\n" .
                       "5. Si el título es muy genérico y no estás 100% seguro de a quién pertenece, devuelve null.\n" .
                       "6. Si es una entidad gubernamental o institucional, devuélvela normal.\n\n" .
                       "Respuesta JSON:";

            $result = $this->callAI($prompt1);

            if ($result && isset($result['author']) && !empty($result['author'])) {
                // ✅ Etapa 1 exitosa
                $newAuthor = $result['author'];
                $book->author = $newAuthor;
                $book->classification_reason = $this->appendReason($book->classification_reason, "Autor rescatado por IA/Título ($originalAuthor → $newAuthor)");
                $book->save();
                $rescued++;
                $bar->advance();
                if ($delay > 0) sleep($delay);
                continue;
            }

            // ════════════════════════════════
            // ETAPA 2: Título + Descripción (solo si hay descripción)
            // ════════════════════════════════
            if (!empty($description)) {
                $descSnippet = mb_substr($description, 0, 500);

                $prompt2 = "Actúa como un bibliotecario experto. " .
                           "Tengo un libro con título '{$title}' cuyo autor no está registrado. " .
                           "La descripción del libro es:\n\"{$descSnippet}\"\n\n" .
                           "Basándote en el título Y la descripción, identifica al autor real.\n\n" .
                           "REGLAS:\n" .
                           "1. Devuelve EXCLUSIVAMENTE un JSON con el campo 'author'.\n" .
                           "2. Formato: 'Apellido, Nombre' (ej: 'Neruda, Pablo').\n" .
                           "3. Si es anónimo históricamente, devuelve 'Anónimo'.\n" .
                           "4. Si hay múltiples autores claros, devuelve 'Varios'.\n" .
                           "5. Si aún con la descripción no puedes determinarlo con certeza, devuelve null.\n\n" .
                           "Respuesta JSON:";

                $result2 = $this->callAI($prompt2);

                if ($result2 && isset($result2['author']) && !empty($result2['author'])) {
                    // ✅ Etapa 2 exitosa
                    $newAuthor = $result2['author'];
                    $book->author = $newAuthor;
                    $book->classification_reason = $this->appendReason($book->classification_reason, "Autor rescatado por IA/Descripción ($originalAuthor → $newAuthor)");
                    $book->save();
                    $rescued++;
                    $bar->advance();
                    if ($delay > 0) sleep($delay);
                    continue;
                }

                $failedOne++;
            }

            // ❌ Ambas etapas fallaron — marcar como irrecuperable
            $failedAll++;
            $book->classification_reason = $this->appendReason($book->classification_reason, 'Autor rescatado por IA (Irrecuperable)');

            // Estandarizar a "Desconocido" si tenía basura
            $lower = strtolower(trim($originalAuthor));
            if (!in_array($lower, ['anónimo', 'anonimo', 'desconocido', 'varios'])) {
                $book->author = 'Desconocido';
            }
            $book->save();

            $bar->advance();
            if ($delay > 0) sleep($delay);
        }

        $bar->finish();
        $this->info("\n¡Rescate de autores finalizado!");
        $this->info("  ✅ Rescatados:     $rescued");
        $this->info("  ⚠️  Fallaron Etapa 1 (pasaron a Etapa 2): $failedOne");
        $this->info("  ❌ Irrecuperables: $failedAll");
    }

    /**
     * Llama a la IA y reintenta en caso de saturación.
     */
    private function callAI(string $prompt): ?array
    {
        $result = null;
        $retries = 0;
        while ($result === null && $retries < 3) {
            try {
                $result = \App\Services\ArchivalDeepDiveService::callAiHub($prompt, true);
                if ($result === null) {
                    $this->warn("\nRespuesta vacía o inválida de la IA. Reintentando...");
                    sleep(5);
                    $retries++;
                }
            } catch (\Exception $e) {
                $this->warn("\nAI Hub saturado (429/Error: " . $e->getMessage() . "). Reintentando en 15s...");
                sleep(15);
                $retries++;
            }
        }
        return $result;
    }

    /**
     * Añade una razón al campo classification_reason sin duplicar.
     */
    private function appendReason(?string $current, string $new): string
    {
        return $current ? $current . ' | ' . $new : $new;
    }
}
