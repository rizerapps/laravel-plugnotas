<?php

namespace Rizer\PlugNotas\Credentials;

/**
 * Credenciais de acesso a API PlugNotas, sem nenhuma dependencia de Model
 * nem do container/config da aplicacao host.
 *
 * Quem monta as credenciais (resolver por tenant, config, .env) fica fora do
 * pacote — cada projeto consumidor implementa a sua propria resolucao e passa
 * o resultado pronto para o client.
 */
final class PlugNotasCredentials
{
    private const PRODUCTION_URL = 'https://api.plugnotas.com.br';

    private const SANDBOX_URL = 'https://api.sandbox.plugnotas.com.br';

    public function __construct(
        public readonly ?string $apiKey = null,
        public readonly string $environment = 'sandbox',
        /**
         * CNPJ do prestador (só dígitos ou formatado — os métodos que o usam
         * removem não-dígitos antes de enviar). Opcional na maioria das
         * operações, mas obrigatório para `PlugNotasClient::queryNfseByIntegration()`
         * — sem ele, o método lança `\RuntimeException` em vez de chamar a API.
         * Quem resolve credenciais por tenant deve propagar o CNPJ da empresa
         * até aqui se pretende consultar NFS-e por referência de integração.
         */
        public readonly ?string $cnpj = null,
        public readonly int $timeout = 30,
        public readonly int $retryTimes = 3,
        public readonly int $retryDelay = 1000,
        public readonly ?string $webhookSecret = null,
        public readonly ?string $tenantKey = null,
    ) {}

    /**
     * Monta as credenciais a partir de um array de config.
     *
     * Aceita tanto chaves snake_case quanto camelCase, para acomodar formatos
     * de config diferentes entre projetos consumidores.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: $config['api_key'] ?? $config['apiKey'] ?? null,
            environment: $config['environment'] ?? 'sandbox',
            cnpj: $config['cnpj'] ?? null,
            timeout: (int) ($config['timeout'] ?? 30),
            retryTimes: (int) ($config['retry_times'] ?? $config['retryTimes'] ?? 3),
            retryDelay: (int) ($config['retry_delay'] ?? $config['retryDelay'] ?? 1000),
            webhookSecret: $config['webhook_secret'] ?? $config['webhookSecret'] ?? null,
            tenantKey: isset($config['tenant_key']) ? (string) $config['tenant_key'] : ($config['tenantKey'] ?? null),
        );
    }

    /**
     * Monta as credenciais a partir da config do pacote (`config('plugnotas')`),
     * escolhendo a chave de API do ambiente informado.
     *
     * O ambiente deve vir da configuracao do emissor (banco de dados); sem ele,
     * vale `default_environment`. Qualquer valor diferente de `production` e
     * tratado como sandbox — errar para o lado seguro.
     */
    public static function fromConfig(array $config, ?string $environment = null, ?string $cnpj = null): self
    {
        $environment = $environment ?: ($config['default_environment'] ?? 'sandbox');
        $environment = $environment === 'production' ? 'production' : 'sandbox';

        return new self(
            apiKey: ($config['api_keys'][$environment] ?? null) ?: null,
            environment: $environment,
            cnpj: $cnpj,
            timeout: (int) ($config['timeout'] ?? 30),
            retryTimes: (int) ($config['retry_times'] ?? 3),
            retryDelay: (int) ($config['retry_delay'] ?? 1000),
        );
    }

    public function withEnvironment(string $environment): self
    {
        return new self(
            apiKey: $this->apiKey,
            environment: $environment,
            cnpj: $this->cnpj,
            timeout: $this->timeout,
            retryTimes: $this->retryTimes,
            retryDelay: $this->retryDelay,
            webhookSecret: $this->webhookSecret,
            tenantKey: $this->tenantKey,
        );
    }

    public function withApiKey(?string $apiKey): self
    {
        return new self(
            apiKey: $apiKey,
            environment: $this->environment,
            cnpj: $this->cnpj,
            timeout: $this->timeout,
            retryTimes: $this->retryTimes,
            retryDelay: $this->retryDelay,
            webhookSecret: $this->webhookSecret,
            tenantKey: $this->tenantKey,
        );
    }

    public function withCnpj(?string $cnpj): self
    {
        return new self(
            apiKey: $this->apiKey,
            environment: $this->environment,
            cnpj: $cnpj,
            timeout: $this->timeout,
            retryTimes: $this->retryTimes,
            retryDelay: $this->retryDelay,
            webhookSecret: $this->webhookSecret,
            tenantKey: $this->tenantKey,
        );
    }

    public function baseUrl(): string
    {
        return $this->isProduction() ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isValid(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Identificador estavel do conjunto de credenciais, para cache de instancia.
     * Nao inclui a api key em claro.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->environment,
            $this->apiKey === null ? '' : hash('sha256', $this->apiKey),
            $this->cnpj ?? '',
            $this->tenantKey ?? '',
        ]));
    }
}
