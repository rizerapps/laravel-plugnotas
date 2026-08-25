<?php

namespace Rizer\PlugNotas\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;
use Rizer\PlugNotas\Exceptions\NFeApiException;

/**
 * HTTP client for TecnoSpeed PlugNotas API.
 *
 * This client handles all communication with TecnoSpeed PlugNotas for NF-e operations.
 * Supports both sandbox and production environments.
 *
 * Recebe as credenciais **ja resolvidas** (PlugNotasCredentials) — nao conhece
 * Model, container nem config da aplicacao host. Quem resolve credenciais por
 * empresa/tenant e o projeto consumidor, que tipicamente estende esta classe
 * para adicionar suas proprias fachadas de construcao.
 *
 * @see https://docs.plugnotas.com.br
 */
class PlugNotasClient implements NFeClientInterface
{
    private const PROVIDER = 'tecnospeed';

    private ?PendingRequest $client = null;

    private PlugNotasCredentials $credentials;

    /**
     * As credenciais sao obrigatorias aqui: o pacote nao le .env nem config do
     * host. Projetos que queiram um default (ex.: credencial global do .env)
     * estendem esta classe e sobrescrevem o construtor.
     */
    public function __construct(PlugNotasCredentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * Replace this instance's credentials, discarding the memoized HTTP client.
     *
     * @return $this
     */
    public function withCredentials(PlugNotasCredentials $credentials): static
    {
        $this->credentials = $credentials;
        $this->client = null;

        return $this;
    }

    public function getCredentials(): PlugNotasCredentials
    {
        return $this->credentials;
    }

    /**
     * Get the tenant this client is configured for.
     */
    public function getCompanyId(): ?int
    {
        return $this->credentials->tenantKey === null ? null : (int) $this->credentials->tenantKey;
    }

    /**
     * Check if the client has valid configuration.
     */
    public function hasValidConfig(): bool
    {
        return $this->credentials->isValid();
    }

    /**
     * {@inheritdoc}
     */
    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironment(): string
    {
        return $this->credentials->environment;
    }

    /**
     * {@inheritdoc}
     */
    public function isProduction(): bool
    {
        return $this->credentials->isProduction();
    }

    /**
     * Get the HTTP client, initializing it if needed.
     * Uses lazy initialization to avoid failures during app bootstrap.
     *
     * @throws \RuntimeException if API key is not configured
     */
    private function getClient(): PendingRequest
    {
        if ($this->client === null) {
            $this->initializeClient();
        }

        return $this->client;
    }

    /**
     * Initialize the HTTP client with authentication and retry configuration.
     */
    private function initializeClient(): void
    {
        if (!$this->credentials->isValid()) {
            throw new \RuntimeException('Token PlugNotas nao configurado. Defina TECNOSPEED_API_KEY no .env do servidor.');
        }

        $this->client = Http::baseUrl($this->getBaseUrl())
            ->withHeaders([
                'x-api-key' => $this->credentials->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->timeout($this->credentials->timeout)
            ->retry(
                $this->credentials->retryTimes,
                $this->credentials->retryDelay,
                fn (\Exception $e) => $this->shouldRetry($e)
            );
    }

    /**
     * Get the API base URL based on environment.
     */
    private function getBaseUrl(): string
    {
        return $this->credentials->baseUrl();
    }

    /**
     * Determine if a request should be retried.
     */
    private function shouldRetry(\Exception $exception): bool
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            // Retry on server errors and rate limiting
            return in_array($status, [429, 500, 502, 503, 504]);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): bool
    {
        try {
            // Use /empresa endpoint to check connectivity
            // This endpoint lists registered companies and validates API key
            $response = $this->getClient()->get('/empresa');

            // 200 = success, has companies
            // 404 = no companies registered yet (but API is accessible)
            // 401/403 = invalid credentials
            if (in_array($response->status(), [200, 404])) {
                return true;
            }

            if (in_array($response->status(), [401, 403])) {
                Log::warning('TecnoSpeed health check: invalid credentials', [
                    'status' => $response->status(),
                    'company_id' => $this->credentials->tenantKey,
                ]);

                return false;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('TecnoSpeed health check failed', [
                'error' => $e->getMessage(),
                'company_id' => $this->credentials->tenantKey,
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createNFe(string $ref, array $data): array
    {
        try {
            // Add external reference to data
            $data['idIntegracao'] = $ref;

            // Extract CNPJ from emitente for the x-empresa-cnpj header
            // TecnoSpeed requires this header to identify which registered company is emitting
            // Field name is 'cpfCnpj' in TecnoSpeed format
            $cnpj = $data['emitente']['cpfCnpj'] ?? $data['emitente']['cnpj'] ?? null;

            if (empty($cnpj)) {
                throw NFeApiException::validationError(
                    self::PROVIDER,
                    'CNPJ do emitente é obrigatório para emissão de NF-e'
                );
            }

            // Remove non-numeric characters from CNPJ
            $cnpj = preg_replace('/\D/', '', $cnpj);

            // TecnoSpeed PlugNotas expects an array of documents
            $payload = [$data];

            $this->logRequest('POST', '/nfe', $payload);

            // Add x-empresa-cnpj header to identify the emitting company
            $response = $this->getClient()
                ->withHeaders(['x-empresa-cnpj' => $cnpj])
                ->post('/nfe', $payload);

            // Handle the response (TecnoSpeed returns array of results)
            $result = $this->handleCreateNFeResponse($response, $ref);

            return $result;
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'createNFe');
        }
    }

    /**
     * Handle createNFe response specifically.
     * TecnoSpeed returns an array of document results.
     */
    private function handleCreateNFeResponse(Response $response, string $ref): array
    {
        $data = $response->json() ?? [];

        $this->logResponse('createNFe', $response->status(), ['raw' => $data]);

        // Handle HTTP errors
        if ($response->failed()) {
            Log::error('TecnoSpeed createNFe error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $data,
                'company_id' => $this->credentials->tenantKey,
            ]);

            // Check for specific error messages
            $errorMessage = $data['error']['message'] ?? $data['mensagem'] ?? null;

            // Company not registered in TecnoSpeed
            if ($response->status() === 404 && $errorMessage && str_contains($errorMessage, 'Empresa')) {
                throw NFeApiException::validationError(
                    self::PROVIDER,
                    'Empresa nao cadastrada no servico de emissao fiscal. Acesse Configuracao Fiscal e registre a empresa com o certificado digital antes de emitir notas.',
                    $data
                );
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw NFeApiException::authenticationFailed(self::PROVIDER, $data);
            }

            if ($response->status() === 422) {
                // Validation error - extract message from response
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $data);
            }

            throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $data);
        }

        // TecnoSpeed returns array of documents: [{id, idIntegracao, situacao, ...}]
        // Get the first document result
        $docResult = $data[0] ?? $data;

        // Add ref for consistency
        if (!isset($docResult['ref'])) {
            $docResult['ref'] = $ref;
        }

        // Normalize the response
        $docResult = $this->normalizeResponse($docResult);

        // Check for document-level errors
        $situacao = $docResult['situacao'] ?? $docResult['status'] ?? '';
        if (in_array($situacao, ['Erro', 'Rejeitada', 'erro_autorizacao', 'rejeitado'])) {
            $sefazCode = $docResult['status_sefaz'] ?? $docResult['codigoStatus'] ?? 'UNKNOWN';
            $sefazMessage = $docResult['mensagem_sefaz'] ?? $docResult['motivo'] ?? $docResult['mensagem'] ?? 'Erro desconhecido';
            throw NFeApiException::sefazRejection(self::PROVIDER, $sefazCode, $sefazMessage, $docResult);
        }

        return $docResult;
    }

    /**
     * {@inheritdoc}
     */
    public function queryNFe(string $ref): array
    {
        try {
            $this->logRequest('GET', "/nfe/{$ref}/resumo");

            $response = $this->getClient()->get("/nfe/{$ref}/resumo");

            return $this->handleResponse($response, 'queryNFe', $ref);
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'queryNFe');
        }
    }

    /**
     * Search for an NF-e by its idIntegracao (provider_ref).
     *
     * Returns the PlugNotas internal document ID (plugnotas_id) if found, or null.
     * Used to recover invoices that were sent but whose plugnotas_id was never saved.
     */
    public function findPlugnotasIdByRef(string $ref): ?string
    {
        try {
            $this->logRequest('GET', "/nfe?idIntegracao={$ref}");

            $response = $this->getClient()->get('/nfe', ['idIntegracao' => $ref]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            // Response is an array of documents
            $doc = is_array($data) ? ($data[0] ?? null) : null;

            return $doc['id'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('TecnoSpeedClient findPlugnotasIdByRef failed', [
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function queryByAccessKey(string $accessKey): array
    {
        try {
            $this->logRequest('GET', "/nfe/{$accessKey}");

            $response = $this->getClient()->get("/nfe/{$accessKey}");

            return $this->handleResponse($response, 'queryByAccessKey');
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'queryByAccessKey');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancelNFe(string $ref, string $justification): array
    {
        if (strlen($justification) < 15) {
            throw NFeApiException::validationError(
                self::PROVIDER,
                'Justificativa de cancelamento deve ter no minimo 15 caracteres'
            );
        }

        try {
            $this->logRequest('POST', "/nfe/{$ref}/cancelamento", ['justificativa' => $justification]);

            $response = $this->getClient()->post("/nfe/{$ref}/cancelamento", [
                'justificativa' => $justification,
            ]);

            return $this->handleResponse($response, 'cancelNFe', $ref);
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'cancelNFe');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createCCe(string $ref, string $correction): array
    {
        if (strlen($correction) < 15 || strlen($correction) > 1000) {
            throw NFeApiException::validationError(
                self::PROVIDER,
                'Texto de correcao deve ter entre 15 e 1000 caracteres'
            );
        }

        try {
            // First, get the NF-e ID from the reference
            $nfeData = $this->queryNFe($ref);
            $nfeId = $nfeData['id'] ?? null;

            if (!$nfeId) {
                throw NFeApiException::notFound(self::PROVIDER, $ref);
            }

            $this->logRequest('POST', "/nfe/{$nfeId}/cce", ['correcao' => $correction]);

            $response = $this->getClient()->post("/nfe/{$nfeId}/cce", [
                'correcao' => $correction,
            ]);

            return $this->handleResponse($response, 'createCCe', $ref);
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'createCCe');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function inutilizar(array $data): array
    {
        $required = ['cnpj', 'serie', 'numero_inicial', 'numero_final', 'justificativa'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw NFeApiException::validationError(
                    self::PROVIDER,
                    "Campo obrigatorio ausente para inutilizacao: {$field}"
                );
            }
        }

        if (strlen($data['justificativa']) < 15) {
            throw NFeApiException::validationError(
                self::PROVIDER,
                'Justificativa de inutilizacao deve ter no minimo 15 caracteres'
            );
        }

        try {
            $payload = [
                'cnpj' => preg_replace('/\D/', '', $data['cnpj']),
                'serie' => (string) $data['serie'],
                'numeroInicial' => (int) $data['numero_inicial'],
                'numeroFinal' => (int) $data['numero_final'],
                'justificativa' => $data['justificativa'],
            ];

            $this->logRequest('POST', '/nfe/inutilizacao', $payload);

            $response = $this->getClient()->post('/nfe/inutilizacao', $payload);

            return $this->handleResponse($response, 'inutilizar');
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'inutilizar');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function downloadDanfe(string $ref): string
    {
        try {
            // First, get the NF-e data from the reference
            $nfeData = $this->queryNFe($ref);
            $nfeId = $nfeData['id'] ?? null;

            if (!$nfeId) {
                throw NFeApiException::notFound(self::PROVIDER, $ref);
            }

            $this->logRequest('GET', "/nfe/{$nfeId}/pdf");

            $response = $this->getClient()->get("/nfe/{$nfeId}/pdf");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadDanfe');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function downloadXml(string $ref): string
    {
        try {
            // First, get the NF-e data from the reference
            $nfeData = $this->queryNFe($ref);
            $nfeId = $nfeData['id'] ?? null;

            if (!$nfeId) {
                throw NFeApiException::notFound(self::PROVIDER, $ref);
            }

            $this->logRequest('GET', "/nfe/{$nfeId}/xml");

            $response = $this->getClient()->get("/nfe/{$nfeId}/xml");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadXml');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function downloadCancellationXml(string $ref): string
    {
        try {
            $this->logRequest('GET', "/nfe/{$ref}/cancelamento/xml");

            $response = $this->getClient()->get("/nfe/{$ref}/cancelamento/xml");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadCancellationXml');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function downloadCCeXml(string $ref, int $sequenceNumber = 1): string
    {
        try {
            // First, get the NF-e data from the reference
            $nfeData = $this->queryNFe($ref);
            $nfeId = $nfeData['id'] ?? null;

            if (!$nfeId) {
                throw NFeApiException::notFound(self::PROVIDER, $ref);
            }

            $this->logRequest('GET', "/nfe/{$nfeId}/xml/cce/{$sequenceNumber}");

            $response = $this->getClient()->get("/nfe/{$nfeId}/xml/cce/{$sequenceNumber}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadCCeXml');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        // If no secret configured, fail in production for security
        if (empty($this->credentials->webhookSecret)) {
            $context = $this->credentials->tenantKey ? " (company_id: {$this->credentials->tenantKey})" : '';
            Log::warning("TecnoSpeed webhook secret not configured{$context} - skipping signature validation");

            /*
             * Criterio e o ambiente do PlugNotas (credenciais), nao o APP_ENV do
             * host: uma nota emitida contra a API de producao e real e vinculante
             * independente de como a aplicacao que a emitiu esta configurada.
             */
            if ($this->credentials->isProduction()) {
                Log::error("TecnoSpeed webhook rejected: secret not configured in production{$context}");

                return false;
            }

            // Allow in non-production environments for easier testing
            return true;
        }

        if (empty($signature)) {
            return false;
        }

        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $this->credentials->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Validate webhook signature for a specific company.
     *
     * This method loads the webhook secret from the company's settings
     * and validates the signature.
     */
    public function validateWebhookSignatureForCompany(string $payload, string $signature, mixed $company = null): bool
    {
        // Webhook secret is system-level — delegate to global validation.
        return $this->validateWebhookSignature($payload, $signature);
    }

    /**
     * Handle API response and check for errors.
     *
     * @throws NFeApiException
     */
    private function handleResponse(Response $response, string $operation, ?string $ref = null): array
    {
        $data = $response->json() ?? [];

        /*
         * Alguns endpoints (ex: GET /nfe/{ref}/resumo) retornam um array JSON
         * com um unico documento (ex: [{"id":...,"status":"CONCLUIDO",...}])
         * em vez de um objeto plano. Como sempre consultamos por uma referencia
         * especifica, desembrulhamos o primeiro item para manter o restante do
         * codigo trabalhando com um objeto associativo.
         */
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        // Add ref to response for consistency
        if ($ref && !isset($data['ref'])) {
            $data['ref'] = $ref;
        }

        // Normalize TecnoSpeed response to internal format
        $data = $this->normalizeResponse($data);

        $this->logResponse($operation, $response->status(), $data);

        // Handle HTTP errors
        if ($response->failed()) {
            // Log detailed error info for debugging
            Log::error('TecnoSpeed API response error', [
                'operation' => $operation,
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $data,
                'company_id' => $this->credentials->tenantKey,
            ]);

            if ($response->status() === 401 || $response->status() === 403) {
                throw NFeApiException::authenticationFailed(self::PROVIDER, $data);
            }

            if ($response->status() === 404) {
                throw NFeApiException::notFound(self::PROVIDER, $ref ?? 'unknown');
            }

            if ($response->status() === 429) {
                throw NFeApiException::rateLimited(self::PROVIDER);
            }

            throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $data);
        }

        // Check for SEFAZ rejections in successful responses
        $status = $data['status'] ?? '';
        if (in_array($status, ['erro_autorizacao', 'rejeitado', 'Rejeitada'])) {
            $sefazCode = $data['status_sefaz'] ?? $data['codigoStatus'] ?? 'UNKNOWN';
            $sefazMessage = $data['mensagem_sefaz'] ?? $data['motivo'] ?? 'Erro desconhecido';
            throw NFeApiException::sefazRejection(self::PROVIDER, $sefazCode, $sefazMessage, $data);
        }

        return $data;
    }

    /**
     * Normalize TecnoSpeed response to internal format.
     *
     * This method maps TecnoSpeed-specific fields to our internal field names
     * for consistency across providers.
     */
    private function normalizeResponse(array $data): array
    {
        /*
         * Unifica os dois vocabularios de status que o PlugNotas ja usou
         * (TecnoSpeed v1, Title Case: "Autorizada"; PlugNotas atual, CAIXA ALTA:
         * "CONCLUIDO") numa unica tabela case-insensitive. A versao anterior
         * tinha dois blocos mutuamente exclusivos (o segundo so rodava se
         * "situacao" estivesse ausente) - se a resposta trouxesse "situacao"
         * com um token do vocabulario novo, ele nao era traduzido e o status
         * caia sem mapeamento, resultando em "erro" mesmo com a nota concluida.
         */
        $statusMap = [
            'autorizada' => 'autorizado',
            'autorizado' => 'autorizado',
            'concluido' => 'autorizado',
            'processando' => 'processando',
            'aguardando_processamento' => 'processando',
            'rejeitada' => 'erro_autorizacao',
            'rejeitado' => 'erro_autorizacao',
            'cancelada' => 'cancelado',
            'cancelado' => 'cancelado',
            'erro' => 'erro',
            'inutilizada' => 'inutilizado',
            'inutilizado' => 'inutilizado',
        ];

        $rawStatus = $data['situacao'] ?? $data['status'] ?? null;

        if ($rawStatus !== null) {
            $normalizedKey = mb_strtolower((string) $rawStatus);
            $data['status'] = $statusMap[$normalizedKey] ?? $rawStatus;
        }

        // Map TecnoSpeed fields to internal fields
        $fieldMap = [
            'chaveAcesso' => 'chave_nfe',
            'chave' => 'chave_nfe',
            'protocolo' => 'protocolo',
            'numero' => 'numero',
            'serie' => 'serie',
            'codigoStatus' => 'status_sefaz',
            'cStat' => 'status_sefaz',
            'motivo' => 'mensagem_sefaz',
            'xMotivo' => 'mensagem_sefaz',
            'idIntegracao' => 'ref',
            'dataAutorizacao' => 'data_autorizacao',
            'urlDanfe' => 'caminho_danfe',
            'pdf' => 'caminho_danfe',
            'urlXml' => 'caminho_xml_nota_fiscal',
            'xml' => 'caminho_xml_nota_fiscal',
            'id' => 'plugnotas_id',
        ];

        foreach ($fieldMap as $tecnoSpeedField => $internalField) {
            if (isset($data[$tecnoSpeedField]) && !isset($data[$internalField])) {
                $data[$internalField] = $data[$tecnoSpeedField];
            }
        }

        return $data;
    }

    /**
     * Handle request exceptions.
     */
    private function handleRequestException(RequestException $e, string $operation): NFeApiException
    {
        $status = $e->response?->status() ?? 0;
        $body = $e->response?->body() ?? '';
        $data = $e->response?->json() ?? [];

        $logLevel = $status === 404 ? 'warning' : 'error';
        Log::{$logLevel}("TecnoSpeed API error on {$operation}", [
            'status' => $status,
            'response_body' => $body,
            'response_json' => $data,
            'exception' => $e->getMessage(),
            'company_id' => $this->credentials->tenantKey,
            'environment' => $this->credentials->environment,
        ]);

        if ($status === 0) {
            return NFeApiException::connectionFailed(self::PROVIDER, $e);
        }

        if ($status === 401 || $status === 403) {
            return NFeApiException::authenticationFailed(self::PROVIDER, $data);
        }

        if ($status === 404) {
            return NFeApiException::fromResponse(self::PROVIDER, $status, $data);
        }

        if ($status === 429) {
            return NFeApiException::rateLimited(self::PROVIDER);
        }

        return NFeApiException::fromResponse(self::PROVIDER, $status, $data);
    }

    private function logRequest(string $method, string $endpoint, ?array $data = null): void {}

    private function logResponse(string $operation, int $status, array $data): void {}

    // =========================================================================
    // Certificate Methods
    // =========================================================================

    /**
     * Upload a digital certificate (A1 - .pfx file).
     */
    public function uploadCertificate(string $filePath, string $password, ?string $originalName = null): array
    {
        try {
            $this->logRequest('POST', '/certificado');

            $filename = $originalName ?? basename($filePath);

            $response = Http::baseUrl($this->getBaseUrl())
                ->withHeaders(['x-api-key' => $this->credentials->apiKey])
                ->timeout($this->credentials->timeout)
                ->attach('arquivo', file_get_contents($filePath), $filename)
                ->post('/certificado', ['senha' => $password]);

            if ($response->failed()) {
                $json = $response->json() ?? [];
                Log::error('TecnoSpeed uploadCertificate error', [
                    'status' => $response->status(),
                    'error' => $json['error']['message'] ?? 'Unknown error',
                    'company_id' => $this->credentials->tenantKey,
                ]);
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $json);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'uploadCertificate');
        }
    }

    /**
     * List certificates registered in PlugNotas.
     */
    public function listCertificates(): array
    {
        try {
            $this->logRequest('GET', '/certificado');
            $response = $this->getClient()->get('/certificado');

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'listCertificates');
        }
    }

    /**
     * Get certificate details by ID.
     */
    public function getCertificate(string $id): array
    {
        try {
            $this->logRequest('GET', "/certificado/{$id}");
            $response = $this->getClient()->get("/certificado/{$id}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'getCertificate');
        }
    }

    /**
     * Delete a certificate.
     */
    public function deleteCertificate(string $id): bool
    {
        try {
            $this->logRequest('DELETE', "/certificado/{$id}");
            $response = $this->getClient()->delete("/certificado/{$id}");

            return $response->successful();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'deleteCertificate');
        }
    }

    // =========================================================================
    // Company Methods
    // =========================================================================

    /**
     * Register a company in PlugNotas.
     */
    public function registerCompany(array $data): array
    {
        try {
            $this->logRequest('POST', '/empresa', $data);
            $response = $this->getClient()->post('/empresa', $data);

            if ($response->failed()) {
                Log::error('TecnoSpeed registerCompany error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $this->credentials->tenantKey,
                ]);

                        throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'registerCompany');
        }
    }

    /**
     * Get company data from PlugNotas.
     */
    public function getCompany(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $this->logRequest('GET', "/empresa/{$cnpj}");
            $response = $this->getClient()->get("/empresa/{$cnpj}");

            // Qualquer resposta 4xx significa empresa não encontrada — trata como não cadastrada
            if ($response->failed()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            // ->retry() lança excecao mesmo em 404 antes do failed() acima ser avaliado
            if ($e->response?->status() === 404) {
                return [];
            }

            throw $this->handleRequestException($e, 'getCompany');
        }
    }

    /**
     * Update company data in PlugNotas.
     */
    public function updateCompany(string $cnpj, array $data): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $this->logRequest('PATCH', "/empresa/{$cnpj}", $data);
            $response = $this->getClient()->patch("/empresa/{$cnpj}", $data);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'updateCompany');
        }
    }

    // =========================================================================
    // NFS-e Methods
    // =========================================================================

    /**
     * Create/emit NFS-e.
     * PlugNotas expects an array of NFS-e documents.
     */
    public function createNfse(array $data): array
    {
        try {
            $payload = is_array($data) && isset($data[0]) ? $data : [$data];

            $this->logRequest('POST', '/nfse', $payload);

            $response = $this->getClient()->post('/nfse', $payload);

            if ($response->failed()) {
                Log::error('TecnoSpeed createNfse error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $this->credentials->tenantKey,
                ]);
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            $result = $response->json() ?? [];

            return $result['documents'][0] ?? $result[0] ?? $result;
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'createNfse');
        }
    }

    /**
     * Query NFS-e status by ID.
     */
    public function queryNfse(string $id): array
    {
        try {
            $this->logRequest('GET', "/nfse/{$id}");
            $response = $this->getClient()->get("/nfse/{$id}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'queryNfse');
        }
    }

    /**
     * Query NFS-e by integration ID.
     */
    public function queryNfseByIntegration(string $ref): array
    {
        try {
            $this->logRequest('GET', "/nfse/integracao/{$ref}");
            $response = $this->getClient()->get("/nfse/integracao/{$ref}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'queryNfseByIntegration');
        }
    }

    /**
     * Cancel NFS-e.
     */
    public function cancelNfse(string $id, ?string $motivo = null): array
    {
        try {
            $body = $motivo ? ['motivo' => $motivo] : [];
            $this->logRequest('POST', "/nfse/cancelar/{$id}", $body);
            $response = $this->getClient()->post("/nfse/cancelar/{$id}", $body);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'cancelNfse');
        }
    }

    /**
     * Download NFS-e PDF.
     */
    public function downloadNfsePdf(string $id): string
    {
        try {
            $this->logRequest('GET', "/nfse/pdf/{$id}");
            $response = $this->getClient()->get("/nfse/pdf/{$id}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadNfsePdf');
        }
    }

    /**
     * Download NFS-e XML.
     */
    public function downloadNfseXml(string $id): string
    {
        try {
            $this->logRequest('GET', "/nfse/xml/{$id}");
            $response = $this->getClient()->get("/nfse/xml/{$id}");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->body();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'downloadNfseXml');
        }
    }

    // =========================================================================
    // Webhook Methods
    // =========================================================================

    /**
     * Get the organization-level webhook currently registered in PlugNotas.
     *
     * @see https://docs.plugnotas.com.br/ (tag Webhook, GET /webhook)
     */
    public function getWebhook(): array
    {
        try {
            $this->logRequest('GET', '/webhook');
            $response = $this->getClient()->get('/webhook');

            // 4xx significa que nenhum webhook está cadastrado — trata como ausente
            if ($response->failed()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return [];
            }

            throw $this->handleRequestException($e, 'getWebhook');
        }
    }

    /**
     * Register the organization-level webhook in PlugNotas.
     *
     * @see https://docs.plugnotas.com.br/ (tag Webhook, POST /webhook)
     */
    public function createWebhook(string $url, array $options = []): array
    {
        try {
            $payload = array_filter([
                'url' => $url,
                // PlugNotas exige o verbo HTTP que ele deve usar para chamar a URL
                // configurada; nosso endpoint receptor (/api/webhooks/fiscal) so aceita POST.
                'method' => 'POST',
                'email' => $options['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $this->logRequest('POST', '/webhook', $payload);
            $response = $this->getClient()->post('/webhook', $payload);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'createWebhook');
        }
    }

    /**
     * Update the organization-level webhook in PlugNotas.
     *
     * PlugNotas recomenda usar PUT para alterar sem perder notificações.
     *
     * @see https://docs.plugnotas.com.br/ (tag Webhook, PUT /webhook)
     */
    public function updateWebhook(string $url, array $options = []): array
    {
        try {
            $payload = array_filter([
                'url' => $url,
                'method' => 'POST',
                'email' => $options['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $this->logRequest('PUT', '/webhook', $payload);
            $response = $this->getClient()->put('/webhook', $payload);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'updateWebhook');
        }
    }

    /**
     * Remove the organization-level webhook from PlugNotas.
     *
     * @see https://docs.plugnotas.com.br/ (tag Webhook, DELETE /webhook)
     */
    public function deleteWebhook(): array
    {
        try {
            $this->logRequest('DELETE', '/webhook');
            $response = $this->getClient()->delete('/webhook');

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'deleteWebhook');
        }
    }

    /**
     * Trigger a verification ping for the registered webhook.
     *
     * Retorna `{ "message": "Operação realizada com sucesso", "data": {} }`.
     *
     * @see https://docs.plugnotas.com.br/ (tag Webhook, POST /webhook/verify)
     */
    public function verifyWebhook(): array
    {
        try {
            $this->logRequest('POST', '/webhook/verify');
            $response = $this->getClient()->post('/webhook/verify');

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'verifyWebhook');
        }
    }

    // =========================================================================
    // Per-CNPJ Webhook Methods (/empresa/{cnpj}/webhook)
    // =========================================================================

    /**
     * Get the webhook registered for a specific company (CNPJ).
     *
     * @see https://docs.plugnotas.com.br/ (tag Empresa, GET /empresa/{cnpj}/webhook)
     */
    public function getCompanyWebhook(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $this->logRequest('GET', "/empresa/{$cnpj}/webhook");
            $response = $this->getClient()->get("/empresa/{$cnpj}/webhook");

            if ($response->failed()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            // 404 = empresa não encontrada; 400 = sem webhook configurado — ambos são estados normais
            if (in_array($e->response?->status(), [400, 404], true)) {
                return [];
            }

            throw $this->handleRequestException($e, 'getCompanyWebhook');
        }
    }

    /**
     * Register a webhook for a specific company (CNPJ).
     *
     * @see https://docs.plugnotas.com.br/ (tag Empresa, POST /empresa/{cnpj}/webhook)
     */
    public function createCompanyWebhook(string $cnpj, string $url, array $options = []): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $payload = array_filter([
                'url'    => $url,
                'method' => 'POST',
                'email'  => $options['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $this->logRequest('POST', "/empresa/{$cnpj}/webhook", $payload);
            $response = $this->getClient()->post("/empresa/{$cnpj}/webhook", $payload);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'createCompanyWebhook');
        }
    }

    /**
     * Update the webhook for a specific company (CNPJ).
     *
     * @see https://docs.plugnotas.com.br/ (tag Empresa, PUT /empresa/{cnpj}/webhook)
     */
    public function updateCompanyWebhook(string $cnpj, string $url, array $options = []): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $payload = array_filter([
                'url'    => $url,
                'method' => 'POST',
                'email'  => $options['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $this->logRequest('PUT', "/empresa/{$cnpj}/webhook", $payload);
            $response = $this->getClient()->put("/empresa/{$cnpj}/webhook", $payload);

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'updateCompanyWebhook');
        }
    }

    /**
     * Remove the webhook for a specific company (CNPJ).
     *
     * @see https://docs.plugnotas.com.br/ (tag Empresa, DELETE /empresa/{cnpj}/webhook)
     */
    public function deleteCompanyWebhook(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $this->logRequest('DELETE', "/empresa/{$cnpj}/webhook");
            $response = $this->getClient()->delete("/empresa/{$cnpj}/webhook");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'deleteCompanyWebhook');
        }
    }

    /**
     * Trigger a verification ping for the per-CNPJ webhook.
     *
     * @see https://docs.plugnotas.com.br/ (tag Empresa, POST /empresa/{cnpj}/webhook/verify)
     */
    public function verifyCompanyWebhook(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        try {
            $this->logRequest('POST', "/empresa/{$cnpj}/webhook/verify");
            $response = $this->getClient()->post("/empresa/{$cnpj}/webhook/verify");

            if ($response->failed()) {
                throw NFeApiException::fromResponse(self::PROVIDER, $response->status(), $response->json() ?? []);
            }

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, 'verifyCompanyWebhook');
        }
    }

    // =========================================================================
    // Logging Helpers
    // =========================================================================

    /**
     * Sanitize data for logging (remove sensitive info).
     */
    private function sanitizeLogData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sensitiveFields = [
            'cpf', 'cnpj', 'cpfDestinatario', 'cnpjDestinatario',
            'cpfEmitente', 'cnpjEmitente', 'inscricaoEstadual',
            'cpf_destinatario', 'cnpj_destinatario', 'cpf_emitente', 'cnpj_emitente',
        ];

        $sanitized = $data;

        foreach ($sensitiveFields as $field) {
            if (isset($sanitized[$field])) {
                $sanitized[$field] = '***REDACTED***';
            }
        }

        // Also sanitize nested emitente/destinatario objects
        if (isset($sanitized['emitente']) && is_array($sanitized['emitente'])) {
            foreach ($sensitiveFields as $field) {
                if (isset($sanitized['emitente'][$field])) {
                    $sanitized['emitente'][$field] = '***REDACTED***';
                }
            }
        }

        if (isset($sanitized['destinatario']) && is_array($sanitized['destinatario'])) {
            foreach ($sensitiveFields as $field) {
                if (isset($sanitized['destinatario'][$field])) {
                    $sanitized['destinatario'][$field] = '***REDACTED***';
                }
            }
        }

        return $sanitized;
    }
}
