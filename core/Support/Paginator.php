<?php

declare(strict_types=1);

namespace Core\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Result of ->paginate(). Loop over it like a collection and print links with {!! $users->links() !!}.
 *
 * @implements IteratorAggregate<int|string, mixed>
 */
final class Paginator implements Countable, IteratorAggregate, JsonSerializable
{
    public function __construct(
        private readonly Collection $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
        private readonly string $pageName = 'page',
    ) {
    }

    public function items(): Collection
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPages(): bool
    {
        return $this->lastPage() > 1;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function firstItem(): ?int
    {
        return $this->total === 0 ? null : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function lastItem(): ?int
    {
        return $this->total === 0 ? null : min($this->total, $this->currentPage * $this->perPage);
    }

    /** URL of a page, keeping the other query-string values. */
    public function url(int $page): string
    {
        $request = request();
        $query = $request !== null ? $request->queryAll() : [];
        $query[$this->pageName] = $page;

        return url(($request?->path() ?? '/') . '?' . http_build_query($query));
    }

    public function previousPageUrl(): ?string
    {
        return $this->onFirstPage() ? null : $this->url($this->currentPage - 1);
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    /** Bootstrap 5 pagination markup (trusted HTML, so {{ $items->links() }} prints it unescaped). */
    public function links(): HtmlString
    {
        return new HtmlString($this->renderLinks());
    }

    private function renderLinks(): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $html = '<nav aria-label="Pagination"><ul class="pagination">';
        $html .= $this->item('&laquo;', $this->previousPageUrl(), false, 'Previous');

        foreach ($this->window() as $page) {
            $html .= $page === null
                ? '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'
                : $this->item((string) $page, $this->url($page), $page === $this->currentPage);
        }

        $html .= $this->item('&raquo;', $this->nextPageUrl(), false, 'Next');

        return $html . '</ul></nav>';
    }

    /** @return array<int, int|null> Page numbers to show, null = gap. */
    private function window(): array
    {
        $last = $this->lastPage();
        $pages = array_unique(array_filter(
            [1, 2, $this->currentPage - 1, $this->currentPage, $this->currentPage + 1, $last - 1, $last],
            static fn ($p) => $p >= 1 && $p <= $last,
        ));
        sort($pages);

        $result = [];
        $previous = 0;
        foreach ($pages as $page) {
            if ($page - $previous > 1) {
                $result[] = null;
            }
            $result[] = $page;
            $previous = $page;
        }

        return $result;
    }

    private function item(string $label, ?string $url, bool $active, ?string $aria = null): string
    {
        $aria = $aria !== null ? ' aria-label="' . e($aria) . '"' : '';

        if ($active) {
            return '<li class="page-item active" aria-current="page"><span class="page-link">' . $label . '</span></li>';
        }
        if ($url === null) {
            return '<li class="page-item disabled"><span class="page-link"' . $aria . '>' . $label . '</span></li>';
        }

        return '<li class="page-item"><a class="page-link" href="' . e($url) . '"' . $aria . '>' . $label . '</a></li>';
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items->all());
    }

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items->toArray(),
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }
}
