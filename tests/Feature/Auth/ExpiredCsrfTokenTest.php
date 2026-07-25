<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A sign-in page left open outlives its session, so the form it carries posts a
 * dead CSRF token. That must land the visitor back on the sign-in page with a
 * plain explanation — never on the bare "419 PAGE EXPIRED" wall.
 *
 * The framework maps TokenMismatchException to a 419 HttpException before the
 * render callbacks registered in bootstrap/app.php run, which is exactly what
 * these tests pin: match on the mapped status, not the original class.
 */
function renderExpiredToken(Request $request): Response
{
    app()->instance('request', $request);
    URL::setRequest($request);
    $request->setLaravelSession(app('session.store'));

    return app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));
}

it('bounces an expired sign-in back to the login page with the email kept and the password dropped', function (): void {
    $request = Request::create('https://admin.kraite.test/login', 'POST', [
        'email' => 'trader@kraite.com',
        'password' => 'super-secret',
        '_token' => 'a-dead-token',
    ]);
    $request->headers->set('referer', 'https://admin.kraite.test/login');

    $response = renderExpiredToken($request);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('https://admin.kraite.test/login')
        ->and(session('status'))->toBe('Your session expired for security. Please try again.')
        ->and(session()->getOldInput('email'))->toBe('trader@kraite.com')
        ->and(session()->getOldInput('password'))->toBeNull()
        ->and(session()->getOldInput('_token'))->toBeNull();
});

it('falls back to the login route when the expired submission carries no referer', function (): void {
    $request = Request::create('https://admin.kraite.test/forgot-password', 'POST', [
        'email' => 'trader@kraite.com',
    ]);

    $response = renderExpiredToken($request);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('login'));
});

it('answers an expired API caller with a readable message instead of an HTML page', function (): void {
    $request = Request::create('https://admin.kraite.test/login', 'POST', ['email' => 'trader@kraite.com']);
    $request->headers->set('accept', 'application/json');

    $response = renderExpiredToken($request);

    expect($response->getStatusCode())->toBe(419)
        ->and(json_decode((string) $response->getContent(), true))
        ->toBe(['message' => 'Your session expired. Reload the page and try again.']);
});

it('leaves every other http error untouched', function (): void {
    $request = Request::create('https://admin.kraite.test/nope', 'GET');
    app()->instance('request', $request);
    URL::setRequest($request);
    $request->setLaravelSession(app('session.store'));

    $response = app(ExceptionHandler::class)->render($request, new NotFoundHttpException);

    expect($response->getStatusCode())->toBe(404);
});

it('refreshes a sign-in page that has been sitting open, unless something is typed into it', function (): void {
    $view = file_get_contents(resource_path('views/components/auth-layout.blade.php'));

    expect($view)
        ->toContain('data-session-lifetime="{{ (int) config(\'session.lifetime\') }}"')
        ->toContain('const staleAfterMs = Math.max(60000, lifetimeMinutes * 60000 - 120000);')
        ->toContain('if (document.hidden || ! isUntouched()) return;')
        ->toContain('if (event.persisted && isUntouched()) window.location.reload();');
});

it('never bounces the visitor to a site that is not ours', function (): void {
    $request = Request::create('https://admin.kraite.test/login', 'POST', [
        'email' => 'trader@kraite.com',
    ]);
    $request->headers->set('referer', 'https://evil.example.com/phishing');

    $response = renderExpiredToken($request);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('login'));
});
