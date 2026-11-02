<?php

declare(strict_types=1);

namespace Core\Console;

use Core\App;
use Core\Testing\AssertionFailed;
use Core\Testing\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Minimal test runner: finds tests/**\/*Test.php, runs every public test_* / test* method.
 */
final class TestRunner
{
    public function __construct(private readonly App $app)
    {
    }

    public function run(?string $filter = null): int
    {
        $files = $this->files($this->app->basePath . '/tests');
        foreach ($files as $file) {
            require_once $file;
        }

        $classes = array_filter(get_declared_classes(), static fn (string $c) => is_subclass_of($c, TestCase::class) && !(new ReflectionClass($c))->isAbstract());
        sort($classes);

        $passed = 0;
        $failed = [];
        $assertions = 0;
        $start = microtime(true);

        Output::title('INB PHP Framework — running tests');
        foreach ($classes as $class) {
            $methods = array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m) => str_starts_with($m->getName(), 'test') && $m->getDeclaringClass()->getName() === $class,
            );

            foreach ($methods as $method) {
                $label = class_basename($class) . ' › ' . str_replace('_', ' ', preg_replace('/^test_?/', '', $method->getName()) ?? '');
                if ($filter !== null && stripos($class . '::' . $method->getName(), $filter) === false) {
                    continue;
                }

                /** @var TestCase $test */
                $test = new $class();
                try {
                    try {
                        $test->setUpTest($this->app);
                        $test->{$method->getName()}();
                    } finally {
                        $test->tearDownTest();   // always: rolls back the real-database transaction
                    }
                    $passed++;
                    $assertions += $test->assertionCount();
                    Output::line('  ' . Output::color('32', '✓') . ' ' . $label);
                } catch (Throwable $e) {
                    $assertions += $test->assertionCount();
                    $failed[] = [$label, $e];
                    Output::line('  ' . Output::color('31', '✗') . ' ' . $label);
                }
            }
        }

        foreach ($failed as [$label, $e]) {
            Output::line();
            Output::error('FAILED  ' . $label);
            Output::line('  ' . ($e instanceof AssertionFailed ? '' : $e::class . ': ') . $e->getMessage());
            $frame = $this->testFrame($e);
            if ($frame !== null) {
                Output::line(Output::muted('  at ' . $frame));
            }
        }

        $time = round(microtime(true) - $start, 2);
        Output::line();
        $summary = sprintf('Tests: %d passed, %d failed (%d assertions) in %ss', $passed, count($failed), $assertions, $time);
        Output::line($failed === [] ? Output::color('42;30', ' PASS ') . ' ' . $summary : Output::color('41;97', ' FAIL ') . ' ' . $summary);

        return $failed === [] ? 0 : 1;
    }

    /** @return string[] */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function testFrame(Throwable $e): ?string
    {
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (str_contains($file, '/tests/')) {
                return substr($file, strpos($file, '/tests/') + 1) . ':' . ($frame['line'] ?? 0);
            }
        }

        return null;
    }
}
