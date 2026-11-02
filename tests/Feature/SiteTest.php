<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SiteTest extends TestCase
{
    public function test_welcome_cards_link_to_feature_pages(): void
    {
        $html = $this->get('/')->assertOk()->content();

        foreach (['security', 'payments-sms', 'developer-tools'] as $slug) {
            $this->assertStringContainsString('href="/features/' . $slug . '"', $html);
        }
        $this->assertStringContainsString('href="/docs"', $html);
    }

    public function test_feature_pages_show_full_details(): void
    {
        $this->get('/features/security')->assertOk()->assertSee('Request firewall')->assertSee('One-command security audit')->assertSee('Security overview');
        $this->get('/features/payments-sms')->assertOk()->assertSee('Paystack')->assertSee('Arkesel')->assertSee('Webhook verification');
        $this->get('/features/developer-tools')->assertOk()->assertSee('Every major database')->assertSee('PostgreSQL')->assertSee('Testing built in');
        $this->get('/features/unknown')->assertNotFound();
    }

    public function test_theme_toggle_is_on_every_layout(): void
    {
        $pages = ['/', '/features/security', '/docs/installation', '/login'];
        foreach ($pages as $page) {
            $html = $this->get($page)->content();
            $this->assertStringContainsString('data-theme-toggle', $html, "No theme toggle on $page");
            $this->assertStringContainsString('assets/js/theme.js', $html, "theme.js missing on $page");
            $this->assertStringContainsString("localStorage.getItem('inb-theme')", $html, "theme-head missing on $page");
        }

        $this->actingAs($this->createUser());
        $this->assertStringContainsString('data-theme-toggle', $this->get('/dashboard')->content());
        $this->assertStringContainsString('data-theme-toggle', $this->get('/nope')->content(), 'Error pages have a toggle too');
    }

    public function test_guest_pages_load_bootstrap_js_so_alerts_can_be_closed(): void
    {
        $this->assertStringContainsString('bootstrap.bundle.min.js', $this->get('/login')->content());
    }

    public function test_docs_logo_goes_to_the_homepage(): void
    {
        $html = $this->get('/docs/installation')->content();
        $this->assertTrue((bool) preg_match('#<a class="brand" href="/"#', $html), 'The docs logo should link to the homepage');
    }
}
