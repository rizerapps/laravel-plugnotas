<?php

namespace Rizer\PlugNotas\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;

/**
 * Prepara o projeto para usar o pacote: acrescenta ao .env as variaveis que
 * ainda nao existem e testa a conexao com o sandbox.
 *
 * Nunca sobrescreve variavel existente e nunca exibe o valor de uma chave.
 */
class InstallCommand extends Command
{
    protected $signature = 'plugnotas:install
        {--publish-config : Copia config/plugnotas.php para o projeto (so e necessario para customizar)}
        {--no-check : Nao testa a conexao com o sandbox ao final}';

    protected $description = 'Configura as variaveis de ambiente do PlugNotas e testa a conexao com o sandbox';

    private const SANDBOX_KEY = 'PLUGNOTAS_API_KEY_SANDBOX';

    private const PRODUCTION_KEY = 'PLUGNOTAS_API_KEY_PRODUCTION';

    private const WEBHOOK_IPS = 'PLUGNOTAS_WEBHOOK_IPS';

    public function handle(Filesystem $files): int
    {
        if ($this->option('publish-config')) {
            $this->call('vendor:publish', ['--tag' => 'plugnotas-config']);
        }

        $envPath = $this->laravel->environmentFilePath();

        if (! $files->exists($envPath)) {
            $this->error("Arquivo .env nao encontrado em {$envPath}.");

            return self::FAILURE;
        }

        $env = $files->get($envPath);
        $sandboxKey = null;

        if (! $this->hasVariable($env, self::SANDBOX_KEY)) {
            $sandboxKey = trim((string) $this->secret('Chave de API do PlugNotas para SANDBOX (deixe vazio para preencher depois)'));
        }

        $added = $this->appendMissing($files, $envPath, $env, [
            self::SANDBOX_KEY => $sandboxKey ?? '',
            self::PRODUCTION_KEY => '',
            self::WEBHOOK_IPS => '',
        ]);

        $this->appendMissingToExample($files);

        foreach ($added as $name) {
            $this->line("  + {$name} adicionada ao .env");
        }

        if ($added === []) {
            $this->line('  Variaveis do PlugNotas ja existiam no .env — nada alterado.');
        }

        $this->comment('A chave de producao fica vazia: preencha somente quando for emitir de verdade.');

        if (! $this->option('no-check')) {
            $this->checkSandbox($sandboxKey);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $variables
     * @return list<string> nomes das variaveis adicionadas
     */
    private function appendMissing(Filesystem $files, string $path, string $content, array $variables): array
    {
        $lines = [];

        foreach ($variables as $name => $value) {
            if (! $this->hasVariable($content, $name)) {
                $lines[] = "{$name}={$value}";
            }
        }

        if ($lines === []) {
            return [];
        }

        $prefix = $content === '' || str_ends_with($content, "\n") ? '' : "\n";
        $files->append($path, $prefix."\n# PlugNotas\n".implode("\n", $lines)."\n");

        return array_map(fn (string $line) => strstr($line, '=', true), $lines);
    }

    /**
     * Mantem o .env.example em dia (sem valores), quando o projeto tiver um.
     */
    private function appendMissingToExample(Filesystem $files): void
    {
        $examplePath = $this->laravel->basePath('.env.example');

        if (! $files->exists($examplePath)) {
            return;
        }

        $this->appendMissing($files, $examplePath, $files->get($examplePath), [
            self::SANDBOX_KEY => '',
            self::PRODUCTION_KEY => '',
            self::WEBHOOK_IPS => '',
        ]);
    }

    private function hasVariable(string $content, string $name): bool
    {
        return (bool) preg_match('/^\s*'.preg_quote($name, '/').'\s*=/m', $content);
    }

    /**
     * A config ja carregada nao enxerga o que acabou de ser escrito no .env,
     * por isso a chave digitada (se houver) tem precedencia.
     */
    private function checkSandbox(?string $typedKey): void
    {
        $config = $this->laravel['config']->get('plugnotas', []);

        if ($typedKey !== null && $typedKey !== '') {
            $config['api_keys']['sandbox'] = $typedKey;
        }

        $credentials = PlugNotasCredentials::fromConfig($config, 'sandbox');

        if (! $credentials->isValid()) {
            $this->warn('Conexao nao testada: chave de sandbox vazia.');

            return;
        }

        if ((new PlugNotasClient($credentials))->healthCheck()) {
            $this->info('Conexao com o sandbox do PlugNotas OK.');

            return;
        }

        $this->error('Falha ao conectar no sandbox do PlugNotas. Confira a chave e a rede.');
    }
}
