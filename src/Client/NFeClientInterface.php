<?php

namespace Rizer\PlugNotas\Client;

/**
 * Interface for NF-e API clients.
 *
 * This interface defines the contract for NF-e provider integrations.
 * Implementations must handle all communication with the fiscal provider.
 *
 * Supported provider: TecnoSpeed PlugNotas
 */
interface NFeClientInterface
{
    /**
     * Get the provider identifier.
     *
     * @return string Provider name (e.g., 'tecnospeed')
     */
    public function getProvider(): string;

    /**
     * Get the current environment.
     *
     * @return string Environment ('sandbox' or 'production')
     */
    public function getEnvironment(): string;

    /**
     * Check if running in production environment.
     */
    public function isProduction(): bool;

    /**
     * Check if the API is accessible.
     */
    public function healthCheck(): bool;

    /**
     * Create and authorize a new NF-e.
     *
     * @param string $ref Unique reference for this NF-e (used to track the document)
     * @param array $data NF-e data according to the provider API specification
     * @return array Provider response
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function createNFe(string $ref, array $data): array;

    /**
     * Query NF-e status by reference.
     *
     * @param string $ref NF-e reference
     * @return array Provider response with current status
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function queryNFe(string $ref): array;

    /**
     * Query NF-e status by access key.
     *
     * @param string $accessKey 44-digit access key
     * @return array Provider response
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function queryByAccessKey(string $accessKey): array;

    /**
     * Cancel an authorized NF-e.
     *
     * @param string $ref NF-e reference
     * @param string $justification Cancellation justification (min 15 chars)
     * @return array Provider response
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function cancelNFe(string $ref, string $justification): array;

    /**
     * Create a Carta de Correcao Eletronica (CCe).
     *
     * @param string $ref NF-e reference
     * @param string $correction Correction text (15-1000 chars)
     * @return array Provider response
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function createCCe(string $ref, string $correction): array;

    /**
     * Inutilizar a range of invoice numbers.
     *
     * @param array $data Inutilizacao data (cnpj, serie, numero_inicial, numero_final, justificativa)
     * @return array Provider response
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function inutilizar(array $data): array;

    /**
     * Download DANFE PDF.
     *
     * @param string $ref NF-e reference
     * @return string PDF binary content
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function downloadDanfe(string $ref): string;

    /**
     * Download authorized NF-e XML.
     *
     * @param string $ref NF-e reference
     * @return string XML content
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function downloadXml(string $ref): string;

    /**
     * Download cancellation event XML.
     *
     * @param string $ref NF-e reference
     * @return string XML content
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function downloadCancellationXml(string $ref): string;

    /**
     * Download CCe event XML.
     *
     * @param string $ref NF-e reference
     * @param int $sequenceNumber CCe sequence number (1-20)
     * @return string XML content
     *
     * @throws \App\Domains\Tax\Exceptions\NFeApiException
     */
    public function downloadCCeXml(string $ref, int $sequenceNumber = 1): string;

    /**
     * Validate webhook signature.
     *
     * @param string $payload Raw payload from webhook
     * @param string $signature Signature from webhook header
     */
    public function validateWebhookSignature(string $payload, string $signature): bool;
}
