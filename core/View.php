<?php

declare(strict_types=1);

namespace Core;

use Core\View\BladeCompiler;
use RuntimeException;
use Throwable;

/**
 * Renders templates from resources/views. Files ending in .blade.php use Blade syntax
 * ({{ }}, @if, @foreach, @extends ...); plain .php views are supported too.
 *
 *   view('posts.index', ['posts' => $posts])   ->  resources/views/posts/index.blade.php
 */
final class View
{
    /** @var array<string, mixed> Data available to every view. */
    private static array $shared = [];

    /** @var array<string, array<int, callable>> view name pattern => composers */
    private static array $composers = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var string[] */
    private array $sectionStack = [];

    /** @var array<string, string[]> */
    private array $pushes = [];

    /** @var string[] */
    private array $pushStack = [];

    /** @var array<int, array{view: string, data: array<string, mixed>, slots: array<string, string>}> */
    private array $componentStack = [];

    /** @var string[] */
    private array $slotStack = [];

    private ?string $lastSection = null;

    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $directory, private readonly string $compiledDirectory = '')
    {
    }

    /** Share a value with every view: View::share('company', 'INB IT Firm') */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** Add data whenever a view renders: View::composer('layouts.*', fn (array $data) => ['menu' => ...]) */
    public static function composer(string $pattern, callable $callback): void
    {
        self::$composers[$pattern][] = $callback;
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        // A fresh instance per render keeps sections/layouts from leaking between renders.
        $instance = new self($this->directory, $this->compiledDirectory);
        $instance->data = array_merge(self::$shared, $data);

        $content = $instance->renderFile($view, $instance->data);

        while ($instance->layout !== null) {
            $layout = $instance->layout;
            $instance->layout = null;
            $instance->sections['content'] ??= $content;
            $instance->data = array_merge($instance->data, $instance->layoutData);
            $content = $instance->renderFile($layout, $instance->data);
        }

        return $content;
    }

    public function exists(string $view): bool
    {
        try {
            return $this->path($view) !== null;
        } catch (RuntimeException) {
            return false;
        }
    }

    // ------------------------------------------------------------------ used inside templates

    /** @param array<string, mixed> $data Extra data for the layout (e.g. page title). */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function end(): void
    {
        $name = array_pop($this->sectionStack) ?? throw new RuntimeException('@endsection without @section.');
        $this->sections[$name] = (string) ob_get_clean();
        $this->lastSection = $name;
    }

    public function lastSection(): string
    {
        return (string) $this->lastSection;
    }

    /** @section('title', 'Home') — the value is escaped. */
    public function setSection(string $name, mixed $content): void
    {
        $this->sections[$name] = e($content);
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? e($default);
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    public function startPush(string $name): void
    {
        $this->pushStack[] = $name;
        ob_start();
    }

    public function stopPush(): void
    {
        $name = array_pop($this->pushStack) ?? throw new RuntimeException('@endpush without @push.');
        $this->pushes[$name][] = (string) ob_get_clean();
    }

    public function stack(string $name): string
    {
        return implode('', $this->pushes[$name] ?? []);
    }

    /** Render a partial, sharing the current view's data. @param array<string, mixed> $data */
    public function include(string $view, array $data = []): string
    {
        return $this->renderFile($view, array_merge($this->data, $data));
    }

    /** @param array<string, mixed> $data */
    public function includeIf(string $view, array $data = []): string
    {
        return $this->exists($view) ? $this->include($view, $data) : '';
    }

    /** @param array<string, mixed> $data */
    public function includeWhen(bool $condition, string $view, array $data = []): string
    {
        return $condition ? $this->include($view, $data) : '';
    }

    /** @param array<string, mixed> $data */
    public function startComponent(string $view, array $data = []): void
    {
        $this->componentStack[] = ['view' => $view, 'data' => $data, 'slots' => []];
        ob_start();
    }

    public function renderComponent(): string
    {
        $component = array_pop($this->componentStack) ?? throw new RuntimeException('@endcomponent without @component.');
        $slot = new Support\HtmlString(trim((string) ob_get_clean()));

        return $this->include($component['view'], array_merge($component['data'], $component['slots'], ['slot' => $slot]));
    }

    public function slot(string $name): void
    {
        $this->slotStack[] = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        $name = array_pop($this->slotStack) ?? throw new RuntimeException('@endslot without @slot.');
        $last = count($this->componentStack) - 1;
        $this->componentStack[$last]['slots'][$name] = new Support\HtmlString(trim((string) ob_get_clean()));
    }

    /** @class(['btn', 'active' => $isActive]) helper. @param array<int|string, mixed> $classes */
    public static function classes(array $classes): string
    {
        $list = [];
        foreach ($classes as $key => $value) {
            if (is_int($key)) {
                $list[] = (string) $value;
            } elseif ($value) {
                $list[] = $key;
            }
        }

        return implode(' ', $list);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $__data */
    private function renderFile(string $view, array $__data): string
    {
        $path = $this->path($view) ?? throw new RuntimeException("View [$view] not found in [{$this->directory}].");
        $__data = $this->applyComposers($view, $__data);
        $__file = str_ends_with($path, '.blade.php') ? $this->compiled($path) : $path;

        $level = ob_get_level();
        ob_start();

        try {
            (function () use ($__file, $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyComposers(string $view, array $data): array
    {
        $name = str_replace('/', '.', $view);
        foreach (self::$composers as $pattern => $callbacks) {
            if (Support\Str::is($pattern, $name)) {
                foreach ($callbacks as $callback) {
                    $data = array_merge($data, (array) $callback($data));
                }
            }
        }

        return $data;
    }

    /** Compile a Blade file once; recompile only when the source changes. */
    private function compiled(string $path): string
    {
        $dir = $this->compiledDirectory !== '' ? $this->compiledDirectory : sys_get_temp_dir();
        $compiled = $dir . '/' . sha1($path) . '.php';

        if (!is_file($compiled) || filemtime($compiled) < filemtime($path)) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $php = (new BladeCompiler())->compile((string) file_get_contents($path));
            file_put_contents($compiled, "<?php /* {$path} */ ?>\n" . $php, LOCK_EX);
        }

        return $compiled;
    }

    private function path(string $view): ?string
    {
        $view = (string) preg_replace('/(\.blade)?\.php$/', '', $view);
        $view = str_replace('.', '/', $view);

        if (!preg_match('#^[A-Za-z0-9_/-]+$#', $view)) {
            throw new RuntimeException("Invalid view name [$view].");
        }

        $base = $this->directory . '/' . trim($view, '/');
        foreach (['.blade.php', '.php'] as $extension) {
            if (is_file($base . $extension)) {
                return $base . $extension;
            }
        }

        return null;
    }
}
