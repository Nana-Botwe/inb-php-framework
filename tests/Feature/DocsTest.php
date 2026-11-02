<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Support\Markdown;
use Tests\TestCase;

final class DocsTest extends TestCase
{
    public function test_docs_index_redirects_to_the_first_page(): void
    {
        $this->get('/docs')->assertRedirect('/docs/installation');
    }

    public function test_every_documentation_page_renders(): void
    {
        foreach ((array) config('docs.navigation') as $group) {
            foreach ((array) $group as $slug => $title) {
                $this->assertTrue(is_file(resource_path("docs/$slug.md")), "Missing resources/docs/$slug.md");
                $this->get("/docs/$slug")->assertOk()->assertSee($title);
            }
        }
    }

    public function test_unknown_pages_and_traversal_are_rejected(): void
    {
        $this->get('/docs/not-a-page')->assertNotFound();
        $this->get('/docs/..%2F..%2F.env')->assertForbidden();   // stopped by the firewall before routing
        $this->get('/docs/Installation')->assertNotFound();      // slugs are a strict whitelist
    }

    public function test_search_finds_pages(): void
    {
        $this->get('/docs/search?q=Paystack')->assertOk()->assertSee('Payment gateways');
    }

    public function test_docs_can_be_disabled(): void
    {
        \Core\Config::set('docs.enabled', false);
        $this->get('/docs/installation')->assertNotFound();
    }

    public function test_markdown_is_rendered_safely(): void
    {
        $out = Markdown::render("# Title\n\n<script>alert(1)</script> **bold** [x](javascript:alert(1)) [ok](https://a.com)\n\n```php\n\$a = '<b>';\n```")['html'];

        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertFalse(str_contains($out, 'javascript:'));
        $this->assertStringContainsString('href="https://a.com"', $out);
        $this->assertStringContainsString('&lt;b&gt;', $out);
    }
}
