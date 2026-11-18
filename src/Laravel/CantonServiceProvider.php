<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Laravel;

use Illuminate\Support\ServiceProvider;
use RantaiLabs\Canton\ClientCredentials;
use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\SharedSecretToken;

final class CantonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/canton.php', 'canton');

        $this->app->singleton(LedgerClient::class, function ($app) {
            $config = $app['config']['canton'];

            $token = match ($config['auth']) {
                'shared-secret' => SharedSecretToken::create($config['user'], $config['audience'], $config['secret']),
                'oauth2' => new ClientCredentials(
                    $config['token_url'],
                    $config['client_id'],
                    $config['client_secret'],
                    $config['audience'],
                    $config['scope'],
                    // share tokens between requests instead of fetching one per request
                    $app['cache.store'],
                ),
            };

            return new LedgerClient($config['url'], $token, (float) $config['timeout']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/canton.php' => config_path('canton.php'),
        ], 'canton-config');
    }
}
