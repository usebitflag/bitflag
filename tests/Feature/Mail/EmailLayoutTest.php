<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccountEmailContent;
use App\Shared\Mail\EmailMessage;
use App\Shared\Mail\EmailTheme;

function drawn(EmailMessage $message): array
{
    $mail = $message->toMailMessage();

    return [
        'html' => view($mail->view['html'], $mail->viewData)->render(),
        'text' => view($mail->view['text'], $mail->viewData)->render(),
    ];
}

function content(AccountEmailTemplate $template, array $details = [], ?User $user = null): EmailMessage
{
    $user ??= User::factory()->create(['name' => 'Maya Santos', 'email' => 'maya@acme.dev', 'timezone' => 'Asia/Manila', 'pending_email' => 'new@acme.dev']);

    return app(AccountEmailContent::class)->for($template, $user, 'https://bitflag.test/the-link', $details, 'maya@acme.dev', new DateTimeImmutable('2026-10-06 09:42:00', new DateTimeZone('UTC')));
}

it('draws every template as HTML and as plain text, each with its button link', function (AccountEmailTemplate $template) {
    $message = content($template, ['device_label' => 'Chrome on macOS', 'location' => 'Manila, Philippines', 'codes_left' => 9, 'new_email' => 'maya.santos@acme.dev']);
    ['html' => $html, 'text' => $text] = drawn($message);

    expect($html)->toContain('<!doctype html>')->toContain('href="https://bitflag.test/the-link"')->toContain($message->title)
        ->and($text)->toContain('https://bitflag.test/the-link')->toContain($message->title)->not->toContain('<')
        ->and($message->subject)->not->toBe('')->and($message->preheader)->not->toBe('');
})->with(AccountEmailTemplate::cases());

it('greets by first name and states the time in the person\'s zone', function () {
    ['text' => $text] = drawn(content(AccountEmailTemplate::PasswordChanged, ['device_label' => 'Chrome on macOS', 'location' => 'Manila, Philippines']));

    expect($text)->toContain('Hi Maya,')->toContain('When: 6 Oct 2026, 17:42 UTC+8 (09:42 UTC)')->toContain('Device: Chrome on macOS')
        ->toContain('Location: Manila, Philippines (approximate)');
});

it('leaves out facts it was not given', function () {
    ['text' => $text] = drawn(content(AccountEmailTemplate::PasswordChanged));

    expect($text)->not->toContain('Device:')->not->toContain('Location:');
});

it('escapes what a person controls, in the HTML and not in the text', function () {
    $user = User::factory()->create(['name' => '<script>alert(1)</script> "Mallory"', 'email' => 'mallory@acme.dev']);
    ['html' => $html, 'text' => $text] = drawn(content(AccountEmailTemplate::NewSignIn, ['device_label' => '<img src=x onerror=alert(1)>'], $user));

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x')->toContain('&lt;script&gt;')
        ->and($text)->toContain('<script>alert(1)</script>');
});

it('hides a preview line in the HTML and names the recipient in the footer', function () {
    ['html' => $html, 'text' => $text] = drawn(content(AccountEmailTemplate::VerifyEmail));

    expect($html)->toContain('display:none')->toContain('Confirm this address to finish setting up your account.')
        ->and($html)->toContain('Sent to maya@acme.dev')->and($text)->toContain('Sent to maya@acme.dev');
});

it('uses the colors of the light theme', function () {
    $tokens = json_decode((string) file_get_contents(base_path('design/bitflag/tokens.json')), true);
    $light = $tokens['themes']['light']['colors'] ?? $tokens['light']['colors'] ?? null;

    expect($light)->not->toBeNull()
        ->and(EmailTheme::BACKGROUND)->toBe($light['hover'])
        ->and(EmailTheme::SURFACE)->toBe($light['surface'])
        ->and(EmailTheme::TEXT)->toBe($light['text'])
        ->and(EmailTheme::MUTED)->toBe($light['muted'])
        ->and(EmailTheme::DIVIDER)->toBe($light['divider'])
        ->and(EmailTheme::PRIMARY)->toBe($light['primary']);
});
