<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Cache;
use App\Models\Book;

class SEOController extends Controller
{
    public function robots()
    {
        $domain = request()->getHost();

        if (str_contains($domain, 'freeblioteca')) {
            $content = "User-agent: *\n";
            $content .= "Disallow: /books/*/download\n";
            $content .= "Disallow: /books/*/read\n";
            $content .= "Disallow: /books/*/stream-pdf\n";
            $content .= "Disallow: /books/*/stream-epub\n";
            $content .= "Allow: /\n\n";
            $content .= "Sitemap: https://{$domain}/sitemap.xml";
        } else {
            $content = "User-agent: *\n";
            $content .= 'Disallow: /';
        }

        return Response::make($content, 200, ['Content-Type' => 'text/plain']);
    }

    public function sitemap()
    {
        $domain = request()->getHost();
        $now = now()->toAtomString();

        $chunks = ceil(Book::count() / 40000);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= "\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        // El sitemap principal estático (home, infantil, catalog)
        $xml .= "\n  <sitemap>";
        $xml .= "\n    <loc>https://{$domain}/sitemap-static.xml</loc>";
        $xml .= "\n    <lastmod>{$now}</lastmod>";
        $xml .= "\n  </sitemap>";

        // Sitemaps de libros
        for ($i = 1; $i <= $chunks; $i++) {
            $xml .= "\n  <sitemap>";
            $xml .= "\n    <loc>https://{$domain}/sitemap-books-{$i}.xml</loc>";
            $xml .= "\n    <lastmod>{$now}</lastmod>";
            $xml .= "\n  </sitemap>";
        }

        $xml .= "\n".'</sitemapindex>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function sitemapStatic()
    {
        $domain = request()->getHost();
        $now = now()->toAtomString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= "\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        $urls = [
            '/',
            '/infantil',
            '/catalog',
        ];

        foreach ($urls as $url) {
            $xml .= "\n  <url>";
            $xml .= "\n    <loc>https://{$domain}{$url}</loc>";
            $xml .= "\n    <lastmod>{$now}</lastmod>";
            $xml .= "\n    <priority>1.0</priority>";
            $xml .= "\n  </url>";
        }

        $xml .= "\n".'</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function sitemapBooks($page)
    {
        $domain = request()->getHost();
        $limit = 40000;
        $offset = ($page - 1) * $limit;

        $xml = Cache::remember("sitemap_books_page_{$page}", 86400, function () use ($domain, $limit, $offset) {
            $books = \Illuminate\Support\Facades\DB::table('books')
                ->select('id', 'updated_at')
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit)
                ->cursor();

            $output = '<?xml version="1.0" encoding="UTF-8"?>';
            $output .= "\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            foreach ($books as $book) {
                $date = $book->updated_at ? \Carbon\Carbon::parse($book->updated_at)->toAtomString() : now()->toAtomString();
                $output .= "\n  <url>";
                $output .= "\n    <loc>https://{$domain}/books/{$book->id}</loc>";
                $output .= "\n    <lastmod>{$date}</lastmod>";
                $output .= "\n    <changefreq>monthly</changefreq>";
                $output .= "\n    <priority>0.8</priority>";
                $output .= "\n  </url>";
            }

            $output .= "\n".'</urlset>';
            return $output;
        });

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
