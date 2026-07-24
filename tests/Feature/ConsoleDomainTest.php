<?php

declare(strict_types=1);

use App\Models\User;

/*
 * The sysadmin console is no longer a separate host — it lives on the admin
 * host under the `/system` prefix, gated by `auth` + `admin`. Surface and
 * access follow the user's role, not the host.
 */

it('redirects guests from the sysadmin console to login', function (): void {
    $this->get('https://admin.kraite.test/system/dashboard')
        ->assertRedirect();
});

it('forbids non-admin users from the sysadmin console', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertForbidden();
});

it('lets admins reach the sysadmin console on the admin host', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertSuccessful()
        ->assertDontSee('Trader view')
        ->assertDontSee('title="Notifications"', false);
});

it('uses one full-width footer for the rail status and footer content', function (): void {
    $admin = User::factory()->create([
        'email' => 'shell-layout-'.uniqid().'@example.test',
        'is_admin' => true,
    ]);

    $html = (string) $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertSuccessful()
        ->getContent();

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $shell = $xpath->query('//*[@data-shell-grid]')->item(0);
    $footer = $xpath->query('//*[@data-shell-footer]')->item(0);
    $status = $xpath->query('//*[@data-shell-footer]/*[@data-rail-status]')->item(0);
    $railScroll = $xpath->query('//*[@data-rail]/*[@data-rail-scroll]')->item(0);

    expect($shell)->not->toBeNull()
        ->and($shell->getAttribute('class'))->toContain('grid-rows-[minmax(0,1fr)_auto]')
        ->and($footer)->not->toBeNull()
        ->and($footer->getAttribute('class'))->toContain('col-span-full')
        ->and($footer->getAttribute('class'))->toContain('border-t')
        ->and($status)->not->toBeNull()
        ->and($status->getAttribute('class'))->not->toContain('border-t')
        ->and($railScroll)->not->toBeNull()
        ->and($railScroll->getAttribute('class'))->toContain('overflow-y-auto')
        ->and($xpath->query('//*[@data-rail]//*[@data-rail-status]')->length)->toBe(0);
});

it('omits the retired revenue link from sysadmin navigation', function (): void {
    $admin = User::factory()->create([
        'email' => 'shell-navigation-'.uniqid().'@example.test',
        'is_admin' => true,
    ]);

    $html = (string) $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertSuccessful()
        ->getContent();

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-id="revenue"]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-id="billing"]')->length)->toBe(2);
});

it('sends sysadmin logins to the system dashboard', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://admin.kraite.test/login', [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://admin.kraite.test/system/dashboard');
});

it('sends trader logins to the trader dashboard', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://admin.kraite.test/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://admin.kraite.test/dashboard');
});
