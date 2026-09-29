<?php

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Mail\Transport\ResendTransport;
use Tests\TestCase;

uses(TestCase::class);

test('the configured default mailer resolves without sending mail', function (string $mailer, string $transport): void {
    config([
        'mail.default' => $mailer,
        'services.resend.key' => 're_test_placeholder_not_a_real_key',
    ]);

    $manager = app(MailManager::class);

    expect($manager->mailer()->getSymfonyTransport())->toBeInstanceOf($transport);
})->with([
    'production Resend' => ['resend', ResendTransport::class],
    'local log' => ['log', LogTransport::class],
    'test array' => ['array', ArrayTransport::class],
]);
