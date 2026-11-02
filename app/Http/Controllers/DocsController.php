<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Core\Cache;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Core\Support\Markdown;

/**
 * Serves the framework documentation from resources/docs/*.md at /docs.
 * Only pages listed in config/docs.php can be opened (no file paths come from the URL).
 */
final class DocsController extends Controller
{
    public function index(): Response
    {
        $this->ensureEnabled();

        return $this->redirect(route('docs.show', ['page' => (string) array_key_first($this->pages())]));
    }

    public function show(string $page): Response
    {
        $this->ensureEnabled();
        $pages = $this->pages();

        if (!isset($pages[$page])) {
            throw new HttpException(404, 'This documentation page does not exist.');
        }

        $doc = $this->load($page);
        $slugs = array_keys($pages);
        $position = (int) array_search($page, $slugs, true);

        return $this->view('docs.page', [
            'page' => $page,
            'title' => $doc['title'] !== '' ? $doc['title'] : $pages[$page],
            'html' => $doc['html'],
            'toc' => $doc['toc'],
            'navigation' => (array) config('docs.navigation', []),
            'previous' => $slugs[$position - 1] ?? null,
            'next' => $slugs[$position + 1] ?? null,
            'pages' => $pages,
        ]);
    }

    public function search(Request $request): Response
    {
        $this->ensureEnabled();
        $query = mb_substr(trim($request->string('q')), 0, 80);
        $results = [];

        if (mb_strlen($query) >= 2) {
            foreach ($this->pages() as $slug => $title) {
                $text = (string) file_get_contents($this->path($slug));
                $position = mb_stripos($text, $query);
                $inTitle = mb_stripos($title, $query) !== false;
                if ($position === false && !$inTitle) {
                    continue;
                }

                $start = max(0, (int) $position - 80);
                $snippet = $position === false ? mb_substr($text, 0, 160) : mb_substr($text, $start, 200);
                $snippet = trim((string) preg_replace('/[#*`>|\[\]]+|\s+/', ' ', $snippet));
                $results[] = ['slug' => $slug, 'title' => $title, 'snippet' => $snippet, 'score' => ($inTitle ? 100 : 0) + substr_count(mb_strtolower($text), mb_strtolower($query))];
            }
            usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);
        }

        return $this->view('docs.search', [
            'query' => $query,
            'results' => $results,
            'navigation' => (array) config('docs.navigation', []),
            'page' => '',
        ]);
    }

    /** @return array{html: string, toc: array<int, array{level: int, id: string, text: string}>, title: string} */
    private function load(string $page): array
    {
        $file = $this->path($page);
        if (!is_file($file)) {
            throw new HttpException(404, 'This documentation page has not been written yet.');
        }

        // Rendered HTML is cached until the .md file changes.
        return Cache::remember('docs.' . $page . '.' . filemtime($file), 86400, static fn () => Markdown::render((string) file_get_contents($file)));
    }

    /** @return array<string, string> slug => title, in navigation order */
    private function pages(): array
    {
        $pages = [];
        foreach ((array) config('docs.navigation', []) as $group) {
            foreach ((array) $group as $slug => $title) {
                $pages[(string) $slug] = (string) $title;
            }
        }

        return $pages;
    }

    private function path(string $slug): string
    {
        return resource_path('docs/' . $slug . '.md');
    }

    private function ensureEnabled(): void
    {
        if (!config('docs.enabled', true)) {
            throw new HttpException(404);
        }
    }
}
