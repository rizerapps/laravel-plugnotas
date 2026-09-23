<?php

namespace Rizer\PlugNotas;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Rizer\PlugNotas\Client\NFeClientInterface;
use Rizer\PlugNotas\Client\NfseClientInterface;
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Console\InstallCommand;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;
use Rizer\PlugNotas\Http\Middleware\VerifyPlugNotasIp;

class PlugNotasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plugnotas.php', 'plugnotas');

        // bindIf: um bind feito pelo projeto (ex.: client por empresa) prevalece.
        $this->app->bindIf(NFeClientInterface::class, fn ($app) => $this->makeDefaultClient($app));
        $this->app->bindIf(NfseClientInterface::class, fn ($app) => $this->makeDefaultClient($app));
    }

    public function boot(Router $router): void
    {
        // Nao sobrescreve um alias que o projeto ja registrou: trocar o middleware
        // em silencio mudaria de onde vem a lista de IPs e poderia desligar a protecao.
        if (! array_key_exists('plugnotas.ip', $router->getMiddleware())) {
            $router->aliasMiddleware('plugnotas.ip', VerifyPlugNotasIp::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/plugnotas.php' => config_path('plugnotas.php'),
            ], 'plugnotas-config');

            $this->commands([InstallCommand::class]);
        }
    }

    /**
     * Client com as credenciais da config, no ambiente padrao. Projetos
     * multiempresa montam as credenciais pelo emissor e usam withCredentials().
     */
    private function makeDefaultClient($app): PlugNotasClient
    {
        return new PlugNotasClient(PlugNotasCredentials::fromConfig($app['config']->get('plugnotas', [])));
    }
}
