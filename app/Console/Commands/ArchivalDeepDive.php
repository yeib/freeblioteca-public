<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Jobs\ProcessArchivalBookJob;
use App\Services\ArchivalDeepDiveService;
use Illuminate\Console\Command;

class ArchivalDeepDive extends Command
{
    protected $signature = 'books:archival-deep-dive {--limit=10 : Cantidad de libros a investigar} {--source=all : Filtrar por fuente}';
    protected $description = 'Entra en los archivos (PDF) para extraer metadatos reales cuando el título es ilegible';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $source = $this->option('source');

        $this->info("Despachando inmersión en archivos a la cola...");

        $query = Book::processable()->where('is_locally_archived', false);

        // Si el usuario quiere un barrido total, incluimos pausados para rescate
        // $query->where('is_paused', false); 


        // MODO BARRIDO TOTAL: Procesamos absolutamente todo lo que no esté archivado
        /*
        $query->where(function($q) {
            $q->where('title', 'regexp', '^[A-Z0-9\-\s\.]+$') 
              ->orWhere('title', 'like', '%.pdf%')
              ->orWhere('title', 'regexp', '^[0-9]+$') 
              ->orWhereNull('author')
              ->orWhere('author', 'Unknown')
              ->orWhere('title', '');
        });
        */

        if ($source !== 'all') {
            $query->where('source', $source);
        }

        $total = $query->count();
        $count = $limit > 0 ? min($limit, $total) : $total;
        
        if ($count === 0) {
            $this->info("No hay libros que requieran inmersión profunda.");
            return;
        }

        $this->info("Despachando {$count} libros a la cola de un total de {$total} detectados...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Usamos chunkById para ser eficientes con bases grandes
        $query->orderBy('id', 'desc')->chunkById(500, function($books) use ($bar, &$count) {
            foreach ($books as $book) {
                if ($count <= 0) return false;
                
                ProcessArchivalBookJob::dispatch($book);
                
                $bar->advance();
                $count--;
            }
            // Respiro generoso para la base de datos
            usleep(500000); // 0.5 segundos cada 500
        });

        $bar->finish();
        $this->info("\nDespacho finalizado. Asegúrate de tener trabajadores ejecutándose.");
    }
}
