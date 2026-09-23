<?php

namespace Rizer\PlugNotas\Tests\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rizer\PlugNotas\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/plugnotas-install-'.uniqid();
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        $this->app->setBasePath($this->dir);

        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function env(): string
    {
        return file_get_contents($this->dir.'/.env');
    }

    public function test_adiciona_as_variaveis_com_sandbox_preenchida_e_producao_vazia(): void
    {
        file_put_contents($this->dir.'/.env', "APP_NAME=Teste\n");

        $this->artisan('plugnotas:install')
            ->expectsQuestion('Chave de API do PlugNotas para SANDBOX (deixe vazio para preencher depois)', 'chave-sandbox')
            ->expectsOutputToContain('Conexao com o sandbox do PlugNotas OK')
            ->doesntExpectOutputToContain('chave-sandbox')
            ->assertSuccessful();

        $env = $this->env();
        $this->assertStringStartsWith("APP_NAME=Teste\n", $env);
        $this->assertStringContainsString("PLUGNOTAS_API_KEY_SANDBOX=chave-sandbox\n", $env);
        $this->assertStringContainsString("PLUGNOTAS_API_KEY_PRODUCTION=\n", $env);
        $this->assertStringContainsString("PLUGNOTAS_WEBHOOK_IPS=\n", $env);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.sandbox.plugnotas.com.br/')
            && $request->hasHeader('x-api-key', 'chave-sandbox'));
    }

    public function test_nunca_sobrescreve_variavel_existente(): void
    {
        $original = "PLUGNOTAS_API_KEY_SANDBOX=ja-existia\nPLUGNOTAS_API_KEY_PRODUCTION=tambem\nPLUGNOTAS_WEBHOOK_IPS=192.0.2.10\n";
        file_put_contents($this->dir.'/.env', $original);

        $this->artisan('plugnotas:install', ['--no-check' => true])
            ->expectsOutputToContain('nada alterado')
            ->assertSuccessful();

        $this->assertSame($original, $this->env());
    }

    public function test_chave_vazia_nao_testa_conexao(): void
    {
        file_put_contents($this->dir.'/.env', '');

        $this->artisan('plugnotas:install')
            ->expectsQuestion('Chave de API do PlugNotas para SANDBOX (deixe vazio para preencher depois)', '')
            ->expectsOutputToContain('chave de sandbox vazia')
            ->assertSuccessful();

        $this->assertStringContainsString("PLUGNOTAS_API_KEY_SANDBOX=\n", $this->env());
        Http::assertNothingSent();
    }

    public function test_atualiza_env_example_sem_valores(): void
    {
        file_put_contents($this->dir.'/.env', '');
        file_put_contents($this->dir.'/.env.example', "APP_NAME=\n");

        $this->artisan('plugnotas:install', ['--no-check' => true])
            ->expectsQuestion('Chave de API do PlugNotas para SANDBOX (deixe vazio para preencher depois)', 'chave-sandbox')
            ->assertSuccessful();

        $example = file_get_contents($this->dir.'/.env.example');
        $this->assertStringContainsString("PLUGNOTAS_API_KEY_SANDBOX=\n", $example);
        $this->assertStringNotContainsString('chave-sandbox', $example);
    }

    public function test_falha_sem_arquivo_env(): void
    {
        $this->artisan('plugnotas:install', ['--no-check' => true])->assertFailed();
    }
}
