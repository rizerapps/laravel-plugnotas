<?php

namespace Rizer\PlugNotas\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;

class PlugNotasCredentialsFromConfigTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'api_keys' => ['sandbox' => 'chave-sandbox', 'production' => 'chave-producao'],
            'default_environment' => 'sandbox',
            'timeout' => 15,
            'retry_times' => 2,
            'retry_delay' => 500,
        ], $overrides);
    }

    public function test_usa_a_chave_do_ambiente_informado(): void
    {
        $production = PlugNotasCredentials::fromConfig($this->config(), 'production');
        $sandbox = PlugNotasCredentials::fromConfig($this->config(), 'sandbox');

        $this->assertSame('chave-producao', $production->apiKey);
        $this->assertTrue($production->isProduction());
        $this->assertSame('https://api.plugnotas.com.br', $production->baseUrl());

        $this->assertSame('chave-sandbox', $sandbox->apiKey);
        $this->assertFalse($sandbox->isProduction());
        $this->assertSame('https://api.sandbox.plugnotas.com.br', $sandbox->baseUrl());
    }

    public function test_sem_ambiente_usa_o_padrao_da_config(): void
    {
        $credentials = PlugNotasCredentials::fromConfig($this->config());

        $this->assertSame('sandbox', $credentials->environment);
        $this->assertSame('chave-sandbox', $credentials->apiKey);
    }

    /**
     * Valor vazio ou desconhecido vindo do banco nunca pode virar producao.
     */
    public function test_ambiente_vazio_ou_desconhecido_vira_sandbox(): void
    {
        foreach (['', null, 'homologation', 'PRODUCTION'] as $environment) {
            $credentials = PlugNotasCredentials::fromConfig($this->config(), $environment);

            $this->assertSame('sandbox', $credentials->environment, var_export($environment, true));
            $this->assertSame('chave-sandbox', $credentials->apiKey);
        }
    }

    public function test_producao_sem_chave_fica_invalida_e_nao_usa_a_de_sandbox(): void
    {
        $credentials = PlugNotasCredentials::fromConfig(
            $this->config(['api_keys' => ['production' => '']]),
            'production'
        );

        $this->assertNull($credentials->apiKey);
        $this->assertFalse($credentials->isValid());
    }

    public function test_repassa_cnpj_e_parametros_de_transporte(): void
    {
        $credentials = PlugNotasCredentials::fromConfig($this->config(), 'sandbox', '11.222.333/0001-81');

        $this->assertSame('11.222.333/0001-81', $credentials->cnpj);
        $this->assertSame(15, $credentials->timeout);
        $this->assertSame(2, $credentials->retryTimes);
        $this->assertSame(500, $credentials->retryDelay);
    }
}
