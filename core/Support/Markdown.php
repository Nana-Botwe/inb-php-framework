<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Small, safe Markdown renderer (used for the /docs pages).
 * All raw HTML in the source is escaped; links with javascript:/data: URLs are dropped.
 *
 * Supports: # headings (with anchors), paragraphs, **bold**, *italic*, `code`, ```fenced code```,
 * [links](url), ![images](url), - / 1. lists (nested by indentation), > blockquotes and
 * > **Note** / **Tip** / **Warning** callouts, tables, --- rules.
 */
final class Markdown
{
    /** @var array<int, array{level: int, id: string, text: string}> */
    private array $toc = [];

    /** @var array<string, int> */
    private array $ids = [];

    /** @return array{html: string, toc: array<int, array{level: int, id: string, text: string}>, title: string} */
    public static function render(string $markdown): array
    {
        $parser = new self();
        $html = $parser->blocks(preg_split('/\r\n|\r|\n/', $markdown) ?: []);
        $title = '';
        foreach ($parser->toc as $entry) {
            if ($entry['level'] === 1) {
                $title = $entry['text'];
                break;
            }
        }

        return ['html' => $html, 'toc' => array_values(array_filter($parser->toc, static fn ($e) => $e['level'] > 1)), 'title' => $title];
    }

    /** One line of inline Markdown (`code`, **bold**, [links](url)) as safe HTML — no <p> wrapper. */
    public static function inlineText(string $text): HtmlString
    {
        return new HtmlString((new self())->inline($text));
    }

    /** @param string[] $lines */
    private function blocks(array $lines): string
    {
        $html = '';
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // fenced code
            if (preg_match('/^\s*```\s*([\w+-]*)\s*$/', $line, $m)) {
                $code = [];
                $i++;
                while ($i < $count && !preg_match('/^\s*```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $lang = $m[1] !== '' ? $m[1] : 'text';
                $html .= '<div class="code-block"><div class="code-lang">' . $this->e($lang) . '</div><pre><code class="language-' . $this->e($lang) . '">'
                    . $this->e(implode("\n", $code)) . '</code></pre></div>' . "\n";
                continue;
            }

            // heading
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = $m[2];
                $id = $this->id($text);
                $this->toc[] = ['level' => $level, 'id' => $id, 'text' => strip_tags($this->inline($text))];
                $anchor = $level > 1 ? '<a class="anchor" href="#' . $id . '" aria-label="Link to this section">#</a>' : '';
                $html .= "<h$level id=\"$id\">" . $this->inline($text) . "$anchor</h$level>\n";
                $i++;
                continue;
            }

            // horizontal rule
            if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }

            // blockquote / callout
            if (preg_match('/^\s*>/', $line)) {
                $quote = [];
                while ($i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }
                $class = '';
                if (isset($quote[0]) && preg_match('/^\*\*(Note|Tip|Warning|Danger|Security)\*\*:?\s*(.*)$/i', $quote[0], $c)) {
                    $class = ' class="callout callout-' . strtolower($c[1]) . '"';
                    $quote[0] = '**' . ucfirst(strtolower($c[1])) . ':** ' . $c[2];
                }
                $html .= "<blockquote$class>" . $this->blocks($quote) . "</blockquote>\n";
                continue;
            }

            // table
            if (str_contains($line, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                $headers = $this->cells($line);
                $i += 2;
                $rows = [];
                while ($i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = $this->cells($lines[$i]);
                    $i++;
                }
                $html .= '<div class="table-wrap"><table><thead><tr>';
                foreach ($headers as $cell) {
                    $html .= '<th>' . $this->inline($cell) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($headers as $index => $unused) {
                        $html .= '<td>' . $this->inline($row[$index] ?? '') . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= "</tbody></table></div>\n";
                continue;
            }

            // list
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                $block = [];
                while ($i < $count && (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i]) || (trim($lines[$i]) !== '' && preg_match('/^\s{2,}\S/', $lines[$i])))) {
                    $block[] = $lines[$i];
                    $i++;
                }
                $html .= $this->renderList($block);
                continue;
            }

            // paragraph
            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i], $lines[$i + 1] ?? '')) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            if ($paragraph === []) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            $html .= '<p>' . $this->inline(implode(' ', $paragraph)) . "</p>\n";
        }

        return $html;
    }

    private function startsBlock(string $line, string $next): bool
    {
        return (bool) preg_match('/^\s*(```|#{1,6}\s|>|(-{3,}|\*{3,})\s*$|([-*+]|\d+[.)])\s+)/', $line)
            || (str_contains($line, '|') && preg_match('/^\s*\|?\s*:?-{2,}/', $next));
    }

    /** @param string[] $lines */
    private function renderList(array $lines): string
    {
        preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[0], $m);
        $baseIndent = strlen($m[1]);
        $ordered = ctype_digit(rtrim($m[2], '.)'));
        $items = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm) && strlen($mm[1]) <= $baseIndent) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $current = ['text' => $mm[3], 'children' => []];
            } elseif ($current !== null) {
                $current['children'][] = substr($line, min(strlen($line) - strlen(ltrim($line)), $baseIndent + 2));
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        $tag = $ordered ? 'ol' : 'ul';
        $html = "<$tag>";
        foreach ($items as $item) {
            $children = array_values(array_filter($item['children'], static fn ($l) => trim($l) !== ''));
            // Text lines continue the item; from the first nested marker on, lines form a sub-list.
            $nested = '';
            $extra = [];
            foreach ($children as $child) {
                if ($nested !== '' || preg_match('/^\s*([-*+]|\d+[.)])\s+/', $child)) {
                    $nested .= $child . "\n";
                } else {
                    $extra[] = trim($child);
                }
            }
            $text = $this->inline(trim($item['text'] . ' ' . implode(' ', $extra)));
            $html .= '<li>' . $text . ($nested !== '' ? $this->renderList(explode("\n", rtrim($nested))) : '') . '</li>';
        }

        return $html . "</$tag>\n";
    }

    /** @return string[] */
    private function cells(string $line): array
    {
        $line = trim(trim($line), '|');
        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(static fn ($c) => str_replace('\|', '|', trim($c)), $cells);
    }

    private function inline(string $text): string
    {
        $codes = [];
        // Protect inline code first.
        $text = (string) preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes): string {
            $codes[] = '<code>' . $this->e($m[1]) . '</code>';

            return "\x02" . (count($codes) - 1) . "\x02";
        }, $text);

        $text = $this->e($text);

        // images & links
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn ($m) => $this->safeUrl($m[2]) === null ? $m[1]
            : '<img src="' . $this->safeUrl($m[2]) . '" alt="' . $m[1] . '" loading="lazy">', $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m): string {
            $url = $this->safeUrl($m[2]);
            if ($url === null) {
                return $m[1];
            }
            $external = preg_match('#^https?://#i', html_entity_decode($url)) === 1;

            return '<a href="' . $url . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
        }, $text);

        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);

        return (string) preg_replace_callback('/\x02(\d+)\x02/', static fn ($m) => $codes[(int) $m[1]] ?? '', $text);
    }

    /** Only http(s), mailto, relative paths and #anchors. Input is already HTML-escaped. */
    private function safeUrl(string $url): ?string
    {
        $raw = html_entity_decode($url, ENT_QUOTES);
        if (preg_match('/^\s*([a-z][a-z0-9+.-]*):/i', $raw, $m) && !in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true)) {
            return null;
        }

        return $this->e($raw);
    }

    private function id(string $text): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($text))), '-') ?: 'section';
        $this->ids[$base] = ($this->ids[$base] ?? 0) + 1;

        return $this->ids[$base] === 1 ? $base : $base . '-' . $this->ids[$base];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
