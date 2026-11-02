<?php

declare(strict_types=1);

namespace Core\View;

/**
 * Compiles Blade-style templates into plain PHP.
 *
 * Echo:        {{ $var }} (escaped)   {!! $html !!} (raw)   @{{ literal }}   {{-- comment --}}
 * Control:     @if @elseif @else @endif  @unless  @isset  @empty  @switch @case @default @endswitch
 * Loops:       @foreach @forelse/@empty/@endforelse @for @while @break @continue  ($loop is available)
 * Layouts:     @extends('layouts.app')  @section('x') ... @endsection  @section('title', 'Home')  @yield('x')
 *              @include('partials.nav', [...])  @includeIf  @includeWhen  @push('scripts') @endpush  @stack('scripts')
 * Components:  @component('components.alert', ['type' => 'danger']) ... @endcomponent   ($slot inside)
 * Forms:       @csrf  @method('PUT')  @error('email') {{ $message }} @enderror  @checked() @selected() @disabled()
 * Auth/env:    @auth @endauth  @guest @endguest  @role('admin') @endrole  @env('local') @endenv  @production
 * Misc:        @json($data)  @nonce  @class([...])  @lang('auth.failed')  @session('status') @endsession
 *              @php ... @endphp  @verbatim ... @endverbatim  @dump($x)
 */
final class BladeCompiler
{
    /** @var array<string, string> */
    private array $placeholders = [];

    /** @var array<int, array{id: int, emptied: bool}> */
    private array $forelseStack = [];

    private int $counter = 0;

    public function compile(string $source): string
    {
        $this->placeholders = [];
        $this->forelseStack = [];

        $source = $this->storeVerbatim($source);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = $this->storePhpBlocks($source);
        $source = $this->compileEchos($source);
        $source = $this->compileDirectives($source);

        // PHP allows no output between "switch (...):" and the first "case", so join them.
        $source = (string) preg_replace('/<\?php switch (\(.*?\)): \?>\s*<\?php case /s', '<?php switch $1: case ', $source);

        return strtr($source, $this->placeholders);
    }

    private function placeholder(string $content): string
    {
        // Control-character delimiters: can't appear in templates and can't merge with a
        // directive name (e.g. "@endif{{ $x }}" must stay "@endif" + echo).
        $key = "\x01" . count($this->placeholders) . "\x01";
        $this->placeholders[$key] = $content;

        return $key;
    }

    private function storeVerbatim(string $source): string
    {
        return (string) preg_replace_callback('/@verbatim(.*?)@endverbatim/s', fn ($m) => $this->placeholder($m[1]), $source);
    }

    private function storePhpBlocks(string $source): string
    {
        return (string) preg_replace_callback(
            '/(?<!@)@php(?!\s*\()(.*?)@endphp/s',
            fn ($m) => $this->placeholder('<?php' . $m[1] . '?>'),
            $source,
        );
    }

    private function compileEchos(string $source): string
    {
        // Escaped literal: @{{ ... }}
        $source = (string) preg_replace_callback('/@(\{\{.*?\}\})/s', fn ($m) => $this->placeholder($m[1]), $source);

        // Raw: {!! $html !!}
        $source = (string) preg_replace_callback('/\{!!\s*(.+?)\s*!!\}(\r?\n)?/s', function ($m) {
            return $this->placeholder('<?= ' . $m[1] . ' ?>' . $this->keepNewline($m[2] ?? ''));
        }, $source);

        // Escaped: {{ $value }}
        return (string) preg_replace_callback('/\{\{\s*(.+?)\s*\}\}(\r?\n)?/s', function ($m) {
            return $this->placeholder('<?= e(' . $m[1] . ') ?>' . $this->keepNewline($m[2] ?? ''));
        }, $source);
    }

    /** PHP swallows the newline right after "?>", so output it twice to keep one. */
    private function keepNewline(string $newline): string
    {
        return $newline === '' ? '' : $newline . $newline;
    }

    private function compileDirectives(string $source): string
    {
        $pattern = '/\B@(@?\w+)([ \t]*)(\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|(?3))*\))?/s';

        return (string) preg_replace_callback($pattern, function (array $m): string {
            $name = $m[1];
            $args = isset($m[3]) && $m[3] !== '' ? substr($m[3], 1, -1) : null;

            if (str_starts_with($name, '@')) {
                return substr($m[0], 1); // @@if -> literal @if
            }

            $compiled = $this->directive($name, $args);
            if ($compiled === null) {
                return $m[0]; // not a Blade directive (e.g. CSS @media) — leave untouched
            }

            return $compiled . ($args === null ? $m[2] : '');
        }, $source);
    }

    private function directive(string $name, ?string $args): ?string
    {
        return match ($name) {
            // conditionals
            'if' => "<?php if ($args): ?>",
            'elseif' => "<?php elseif ($args): ?>",
            'else' => '<?php else: ?>',
            'endif', 'endunless', 'endisset', 'endauth', 'endguest', 'endenv', 'endproduction', 'endrole', 'enderror', 'endsession' => '<?php endif; ?>',
            'unless' => "<?php if (!($args)): ?>",
            'isset' => "<?php if (isset($args)): ?>",
            'empty' => $this->compileEmpty($args),
            'endempty' => '<?php endif; ?>',
            'switch' => "<?php switch ($args): ?>",
            'case' => "<?php case ($args): ?>",
            'default' => '<?php default: ?>',
            'endswitch' => '<?php endswitch; ?>',

            // loops
            'foreach' => $this->compileForeach((string) $args),
            'endforeach' => '<?php endforeach; $loop = array_pop($__loopStack); ?>',
            'forelse' => $this->compileForelse((string) $args),
            'endforelse' => $this->compileEndForelse(),
            'for' => "<?php for ($args): ?>",
            'endfor' => '<?php endfor; ?>',
            'while' => "<?php while ($args): ?>",
            'endwhile' => '<?php endwhile; ?>',
            'break' => $args === null ? '<?php break; ?>' : "<?php if ($args) break; ?>",
            'continue' => $args === null ? '<?php continue; ?>' : "<?php if ($args) continue; ?>",

            // layouts
            'extends' => "<?php \$this->layout($args); ?>",
            'section' => $this->compileSection((string) $args),
            'endsection', 'stop' => '<?php $this->end(); ?>',
            'show' => '<?php $this->end(); echo $this->section($this->lastSection()); ?>',
            'yield' => "<?= \$this->section($args) ?>",
            'hasSection' => "<?php if (\$this->hasSection($args)): ?>",
            'include' => "<?= \$this->include($args) ?>",
            'includeIf' => "<?= \$this->includeIf($args) ?>",
            'includeWhen' => "<?= \$this->includeWhen($args) ?>",
            'push' => "<?php \$this->startPush($args); ?>",
            'endpush' => '<?php $this->stopPush(); ?>',
            'stack' => "<?= \$this->stack($args) ?>",
            'component' => "<?php \$this->startComponent($args); ?>",
            'endcomponent' => '<?= $this->renderComponent() ?>',
            'slot' => "<?php \$this->slot($args); ?>",
            'endslot' => '<?php $this->endSlot(); ?>',

            // forms
            'csrf' => '<?= csrf_field() ?>',
            'method' => "<?= method_field($args) ?>",
            'error' => "<?php if ((\$message = error($args)) !== null): ?>",
            'checked' => "<?php if ($args): echo 'checked'; endif; ?>",
            'selected' => "<?php if ($args): echo 'selected'; endif; ?>",
            'disabled' => "<?php if ($args): echo 'disabled'; endif; ?>",
            'readonly' => "<?php if ($args): echo 'readonly'; endif; ?>",
            'required' => "<?php if ($args): echo 'required'; endif; ?>",
            'class' => "class=\"<?= e(\\Core\\View::classes($args)) ?>\"",

            // auth / environment
            'auth' => '<?php if (\\Core\\Auth::check()): ?>',
            'guest' => '<?php if (\\Core\\Auth::guest()): ?>',
            'role' => "<?php if (\\Core\\Auth::check() && \\Core\\Auth::user()->hasRole($args)): ?>",
            'can' => "<?php if (\\Core\\Auth\\Gate::allows($args)): ?>",
            'cannot' => "<?php if (\\Core\\Auth\\Gate::denies($args)): ?>",
            'endcan', 'endcannot' => '<?php endif; ?>',
            'env' => "<?php if (in_array(config('app.env'), (array) ($args), true)): ?>",
            'production' => "<?php if (config('app.env') === 'production'): ?>",
            'session' => "<?php if ((\$value = session($args)) !== null): ?>",

            // misc
            'json' => "<?= json_encode($args, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>",
            'nonce' => '<?= e(csp_nonce()) ?>',
            'lang' => "<?= e(__($args)) ?>",
            'php' => $args === null ? null : "<?php $args; ?>",
            'dump' => "<?php dump($args); ?>",
            'dd' => "<?php dd($args); ?>",
            default => null,
        };
    }

    private function compileSection(string $args): string
    {
        return count($this->splitArguments($args)) > 1
            ? "<?php \$this->setSection($args); ?>"
            : "<?php \$this->start($args); ?>";
    }

    private function compileForeach(string $args): string
    {
        if (!preg_match('/^(.+)\s+as\s+(.+)$/is', trim($args), $m)) {
            return "<?php \$__loopStack[] = \$loop ?? null; \$loop = null; foreach ($args): ?>";
        }

        $var = '$__items' . (++$this->counter);

        return "<?php \$__loopStack[] = \$loop ?? null; $var = {$m[1]}; "
            . "\$loop = new \\Core\\View\\Loop(is_countable($var) ? count($var) : null, end(\$__loopStack) ?: null); "
            . "foreach ($var as {$m[2]}): \$loop->tick(); ?>";
    }

    private function compileForelse(string $args): string
    {
        $id = ++$this->counter;
        $this->forelseStack[] = ['id' => $id, 'emptied' => false];
        $foreach = $this->compileForeach($args);

        return "<?php \$__empty_$id = true; ?>" . str_replace('$loop->tick(); ?>', "\$__empty_$id = false; \$loop->tick(); ?>", $foreach);
    }

    private function compileEmpty(?string $args): string
    {
        if ($args !== null) {
            return "<?php if (empty($args)): ?>";
        }

        $last = count($this->forelseStack) - 1;
        if ($last < 0) {
            return '<?php else: ?>';
        }
        $this->forelseStack[$last]['emptied'] = true;
        $id = $this->forelseStack[$last]['id'];

        return "<?php endforeach; \$loop = array_pop(\$__loopStack); if (\$__empty_$id): ?>";
    }

    private function compileEndForelse(): string
    {
        $frame = array_pop($this->forelseStack);

        return ($frame['emptied'] ?? false)
            ? '<?php endif; ?>'
            : '<?php endforeach; $loop = array_pop($__loopStack); ?>';
    }

    /** Split "a, [b, c], 'd,e'" at top-level commas. @return string[] */
    private function splitArguments(string $args): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $current .= $char . ($args[++$i] ?? '');
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
