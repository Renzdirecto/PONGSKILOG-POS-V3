<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

class GenerateVapidKeys extends Command
{
    protected $signature = 'pwa:vapid-keys
        {--show : Print a new pair for a secrets manager instead of writing .env}
        {--subject= : Contact written with a new pair (mailto: address or https URL)}';

    protected $description = 'Create a Web Push VAPID key pair in .env without ever overwriting an existing pair';

    /** Written with a new local pair when no subject is given; production must set a real, monitored contact. */
    private const PLACEHOLDER_SUBJECT = 'mailto:webpush@example.com';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();
        $environment = $this->option('show') ? '' : (is_file($path) ? (string) file_get_contents($path) : null);

        if ($environment === null) {
            $this->error('No .env file was found. Create it first (for example by copying .env.example).');

            return self::FAILURE;
        }

        if ($this->filled($environment, 'VAPID_PUBLIC_KEY') || $this->filled($environment, 'VAPID_PRIVATE_KEY')) {
            $this->info('A VAPID key pair is already configured in .env. It was left unchanged.');

            return self::SUCCESS;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable) {
            $this->error('PHP could not create a P-256 key. On Windows, set the OPENSSL_CONF environment variable to the openssl.cnf shipped with PHP (for example C:\php\extras\ssl\openssl.cnf) and run the command again.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
            $this->warn('Store this pair only in the secrets of ONE environment. Never commit or share the private key.');

            return self::SUCCESS;
        }

        $subject = $this->option('subject');
        $environment = $this->set($environment, 'VAPID_PUBLIC_KEY', $keys['publicKey']);
        $environment = $this->set($environment, 'VAPID_PRIVATE_KEY', $keys['privateKey']);
        if (! $this->filled($environment, 'VAPID_SUBJECT')) {
            $environment = $this->set($environment, 'VAPID_SUBJECT', is_string($subject) && $subject !== '' ? $subject : self::PLACEHOLDER_SUBJECT);
        }
        file_put_contents($path, $environment);

        $this->info('Created a VAPID key pair in .env. The private key was written only to that file.');
        $this->line('Public key: '.$keys['publicKey']);
        $this->line('If configuration is cached, run `php artisan config:clear`.');

        return self::SUCCESS;
    }

    private function filled(string $environment, string $key): bool
    {
        return preg_match('/^'.$key.'=["\']?[^"\'\s#]+/m', $environment) === 1;
    }

    private function set(string $environment, string $key, string $value): string
    {
        $pattern = '/^'.$key.'=[^\r\n]*/m';

        if (preg_match($pattern, $environment) === 1) {
            return (string) preg_replace_callback($pattern, fn (): string => $key.'='.$value, $environment, 1);
        }

        return rtrim($environment, "\r\n").PHP_EOL.$key.'='.$value.PHP_EOL;
    }
}
