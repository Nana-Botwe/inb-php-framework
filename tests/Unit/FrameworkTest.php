<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Core\Support\Collection;
use Core\Validator;
use Core\View\BladeCompiler;
use Tests\TestCase;

final class FrameworkTest extends TestCase
{
    public function test_collections(): void
    {
        $c = collect([['n' => 'a', 'v' => 3], ['n' => 'b', 'v' => 1], ['n' => 'c', 'v' => 2]]);

        $this->assertSame(['b', 'c', 'a'], $c->sortBy('v')->pluck('n')->values()->all());
        $this->assertSame(6, $c->sum('v'));
        $this->assertSame(['a'], $c->where('v', '>', 2)->pluck('n')->values()->all());
        $this->assertInstanceOf(Collection::class, $c->groupBy('n')['a']);
    }

    public function test_query_builder_and_relations(): void
    {
        $user = $this->createUser();
        $this->assertSame(1, User::count());
        $this->assertSame($user->email, User::where('id', $user->id)->value('email'));

        $page = User::query()->paginate(10);
        $this->assertSame(1, $page->total());

        \Core\DB::table('payments')->insert([
            'user_id' => $user->id, 'gateway' => 'paystack', 'reference' => 'R1', 'gateway_reference' => 'R1',
            'amount_minor' => 100, 'currency' => 'GHS', 'status' => 'success',
        ]);

        $this->assertCount(1, $user->payments);
        $loaded = User::with('payments')->get()->first();
        $this->assertTrue($loaded->relationLoaded('payments'));
        $this->assertSame($user->id, $user->payments->first()->user->id);
    }

    public function test_validation_rules(): void
    {
        $v = Validator::make(
            ['age' => '15', 'site' => 'javascript:alert(1)', 'name' => '<b>x</b>', 'pw' => 'password'],
            ['age' => 'required|integer|min:18', 'site' => 'nullable|url', 'name' => 'no_html', 'pw' => 'strong_password', 'bio' => 'nullable|max:5'],
        );

        $this->assertSame(['age', 'site', 'name', 'pw'], array_keys($v->errors()));
        $this->assertSame('The age must be at least 18.', $v->errors()['age'][0]);
    }

    public function test_blade_compiler(): void
    {
        $php = (new BladeCompiler())->compile('@if($a) {{ $b }} @else {!! $c !!} @endif @csrf <style>@media (max-width:1px){}</style> me@example.com');

        $this->assertStringContainsString('<?php if ($a): ?>', $php);
        $this->assertStringContainsString('<?= e($b) ?>', $php);
        $this->assertStringContainsString('<?= $c ?>', $php);
        $this->assertStringContainsString('csrf_field()', $php);
        $this->assertStringContainsString('@media', $php);
        $this->assertStringContainsString('me@example.com', $php);
    }

    public function test_blade_loops_provide_loop_variable(): void
    {
        $html = $this->app->view->render('errors.error', ['status' => 404, 'message' => '', 'errorId' => null]);
        $this->assertStringContainsString('Page not found', $html);

        $file = resource_path('views/_test_loop.blade.php');
        file_put_contents($file, '@foreach ($items as $item){{ $loop->iteration }}:{{ $item }}@if($loop->last)!@endif @endforeach');
        try {
            $out = trim($this->app->view->render('_test_loop', ['items' => ['a', 'b']]));
            $this->assertSame('1:a 2:b!', preg_replace('/\s+/', ' ', $out));
        } finally {
            @unlink($file);
        }
    }

    public function test_routes_resolve_names_and_parameters(): void
    {
        $this->assertSame('/reset-password/abc?email=a%40b.com', $this->app->router->url('password.reset', ['token' => 'abc', 'email' => 'a@b.com']));
        $this->get('/does-not-exist')->assertNotFound()->assertSee('Page not found');
        $this->call('DELETE', '/login')->assertStatus(405);
    }

    public function test_cache_and_events(): void
    {
        $calls = 0;
        $value = \Core\Cache::remember('t', 60, function () use (&$calls) {
            $calls++;

            return 'v';
        });
        \Core\Cache::remember('t', 60, function () use (&$calls) {
            $calls++;

            return 'v';
        });
        $this->assertSame('v', $value);
        $this->assertSame(1, $calls);
        \Core\Cache::forget('t');

        $got = null;
        \Core\Event::listen('test.event', function ($x) use (&$got) {
            $got = $x;
        });
        event('test.event', [42]);
        $this->assertSame(42, $got);
    }
}
