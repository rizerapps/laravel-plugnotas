<?php

namespace Rizer\PlugNotas\Tests;

use Illuminate\Support\Facades\Artisan;
use Rizer\PlugNotas\Client\NFeClientInterface;
use Rizer\PlugNotas\Client\NfseClientInterface;
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;
use Rizer\PlugNotas\Http\Middleware\VerifyPlugNotasIp;

class PlugNotasServiceProviderTest extends TestCase
{
    public function test_carrega_a_config_padrao(): void
    {
        $this->assertSame('sandbox', config('plugnotas.default_environment'));
        $this->assertSame([], config('plugnotas.webhook_ips'));
        $this->assertFalse(config('plugnotas.webhook_trust_cloudflare'));
        $this->assertArrayHasKey('sandbox', config('plugnotas.api_keys'));
        $this->assertArrayHasKey('production', config('plugnotas.api_keys'));
    }

    public function test_resolve_as_interfaces_com_o_client_em_sandbox(): void
    {
        config(['plugnotas.api_keys.sandbox' => 'chave-de-teste']);

        $nfe = $this->app->make(NFeClientInterface::class);
        $nfse = $this->app->make(NfseClientInterface::class);

        $this->assertInstanceOf(PlugNotasClient::class, $nfe);
        $this->assertInstanceOf(PlugNotasClient::class, $nfse);
        $this->assertSame('sandbox', $nfe->getEnvironment());
        $this->assertTrue($nfe->hasValidConfig());
    }

    public function test_bind_do_projeto_prevalece(): void
    {
        $custom = new PlugNotasClient(new PlugNotasCredentials(apiKey: 'outra'));
        $this->app->instance(NFeClientInterface::class, $custom);

        $this->assertSame($custom, $this->app->make(NFeClientInterface::class));
    }

    public function test_registra_alias_do_middleware_e_comando(): void
    {
        $this->assertSame(
            VerifyPlugNotasIp::class,
            $this->app['router']->getMiddleware()['plugnotas.ip'] ?? null
        );
        $this->assertArrayHasKey('plugnotas:install', Artisan::all());
    }
}
