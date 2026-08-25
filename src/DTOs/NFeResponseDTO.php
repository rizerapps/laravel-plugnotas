<?php

namespace Rizer\PlugNotas\DTOs;

/**
 * Data Transfer Object for NF-e response from NF-e API providers.
 *
 * This DTO represents the standardized response from NF-e operations.
 * Supports TecnoSpeed PlugNotas provider.
 */
class NFeResponseDTO
{
    /**
     * Possible status values.
     */
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $accessKey = null,
        public readonly ?string $protocol = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $providerRef = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $series = null,
        public readonly ?string $xmlPath = null,
        public readonly ?string $pdfPath = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $plugnotasId = null,
    ) {}

    /**
     * Create DTO from provider API response.
     *
     * This method normalizes responses from TecnoSpeed into a standard format.
     */
    public static function fromProviderResponse(array $response, ?string $providerRef = null): self
    {
        return self::fromTecnoSpeedResponse($response, $providerRef);
    }

    /**
     * Create DTO from TecnoSpeed PlugNotas API response.
     */
    public static function fromTecnoSpeedResponse(array $response, ?string $providerRef = null): self
    {
        $rawStatus = $response['situacao'] ?? $response['status'] ?? '';

        /*
         * O POST /nfe retorna apenas a confirmacao de recebimento (sem "situacao"/"status"),
         * identificada pela presenca de "protocol"/"documents". A decisao real (autorizada/
         * rejeitada) so chega depois via webhook ou polling. Sem essa checagem, essa resposta
         * cai no "default => STATUS_ERROR" do mapTecnoSpeedStatus() e a emissao falha na hora.
         */
        if ($rawStatus === '' && (isset($response['protocol']) || isset($response['documents']))) {
            $status = self::STATUS_PROCESSING;
        } else {
            $status = self::mapTecnoSpeedStatus($rawStatus);
        }

        $cStat = $response['cStat'] ?? null;

        return new self(
            status: $status,
            message: $response['xMotivo'] ?? $response['mensagem_sefaz'] ?? $response['mensagemRetorno'] ?? $response['motivo'] ?? $response['mensagem'] ?? $response['message'] ?? 'Operacao realizada',
            accessKey: $response['chave_nfe'] ?? $response['chave'] ?? $response['chaveAcesso'] ?? null,
            protocol: $response['protocolo'] ?? null,
            errorCode: $cStat !== null ? (string) $cStat : ($response['status_sefaz'] ?? $response['codigoStatus'] ?? null),
            providerRef: $providerRef ?? $response['ref'] ?? $response['idIntegracao'] ?? null,
            invoiceNumber: isset($response['numero']) ? (string) $response['numero'] : null,
            series: isset($response['serie']) ? (string) $response['serie'] : null,
            xmlPath: $response['caminho_xml_nota_fiscal'] ?? $response['xml'] ?? $response['urlXml'] ?? null,
            pdfPath: $response['caminho_danfe'] ?? $response['pdf'] ?? $response['urlDanfe'] ?? null,
            rawResponse: $response,
            plugnotasId: $response['id'] ?? $response['plugnotas_id'] ?? null,
        );
    }

    /**
     * Map TecnoSpeed status to internal status.
     */
    private static function mapTecnoSpeedStatus(string $tecnoSpeedStatus): string
    {
        // Case-insensitive: o PlugNotas ja retornou esse status com casing
        // diferente em pontos distintos da API (Title Case, lowercase, CAIXA ALTA).
        return match (mb_strtolower($tecnoSpeedStatus)) {
            'autorizada', 'autorizado', 'concluido' => self::STATUS_AUTHORIZED,
            'processando', 'processando_autorizacao', 'aguardando_processamento' => self::STATUS_PROCESSING,
            'rejeitada', 'erro_autorizacao', 'rejeitado' => self::STATUS_REJECTED,
            'cancelada', 'cancelado' => self::STATUS_CANCELLED,
            'inutilizada', 'inutilizado' => self::STATUS_AUTHORIZED, // Inutilizacao is successful
            'erro' => self::STATUS_ERROR,
            default => self::STATUS_ERROR,
        };
    }

    /**
     * Create a processing response.
     */
    public static function processing(string $message, ?string $providerRef = null): self
    {
        return new self(
            status: self::STATUS_PROCESSING,
            message: $message,
            providerRef: $providerRef,
        );
    }

    /**
     * Create an authorized response.
     */
    public static function authorized(
        string $message,
        string $accessKey,
        string $protocol,
        ?string $invoiceNumber = null,
        ?string $series = null
    ): self {
        return new self(
            status: self::STATUS_AUTHORIZED,
            message: $message,
            accessKey: $accessKey,
            protocol: $protocol,
            invoiceNumber: $invoiceNumber,
            series: $series,
        );
    }

    /**
     * Create a rejected response.
     */
    public static function rejected(string $message, string $errorCode, ?array $rawResponse = null): self
    {
        return new self(
            status: self::STATUS_REJECTED,
            message: $message,
            errorCode: $errorCode,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Create a cancelled response.
     */
    public static function cancelled(string $message, string $protocol): self
    {
        return new self(
            status: self::STATUS_CANCELLED,
            message: $message,
            protocol: $protocol,
        );
    }

    /**
     * Create an error response.
     */
    public static function error(string $message, ?string $errorCode = null, ?array $rawResponse = null): self
    {
        return new self(
            status: self::STATUS_ERROR,
            message: $message,
            errorCode: $errorCode,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Check if the operation was successful (authorized or cancelled).
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_AUTHORIZED, self::STATUS_CANCELLED]);
    }

    /**
     * Check if the operation is still processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the operation was rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the operation had an error.
     */
    public function hasError(): bool
    {
        return in_array($this->status, [self::STATUS_REJECTED, self::STATUS_ERROR]);
    }

    /**
     * Check if the NF-e is authorized.
     */
    public function isAuthorized(): bool
    {
        return $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Check if the NF-e is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Get SEFAZ status code if available.
     */
    public function getSefazCode(): ?string
    {
        if ($this->rawResponse && isset($this->rawResponse['status_sefaz'])) {
            return (string) $this->rawResponse['status_sefaz'];
        }

        return $this->errorCode;
    }

    /**
     * Check if error is retryable based on SEFAZ code.
     */
    public function isRetryable(): bool
    {
        $nonRetryableCodes = [
            '204', // Duplicidade de NF-e
            '215', // CNPJ invalido
            '225', // NCM invalido
            '301', // Emitente irregular
            '302', // Destinatario irregular
            '539', // Item duplicado
        ];

        $sefazCode = $this->getSefazCode();

        if ($sefazCode && in_array($sefazCode, $nonRetryableCodes)) {
            return false;
        }

        // Provider errors might be retryable
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Convert DTO to array.
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'access_key' => $this->accessKey,
            'protocol' => $this->protocol,
            'error_code' => $this->errorCode,
            'provider_ref' => $this->providerRef,
            'invoice_number' => $this->invoiceNumber,
            'series' => $this->series,
            'xml_path' => $this->xmlPath,
            'pdf_path' => $this->pdfPath,
            'plugnotas_id' => $this->plugnotasId,
        ];
    }

    /**
     * Get user-friendly message for display.
     */
    public function getUserMessage(): string
    {
        return match ($this->status) {
            self::STATUS_AUTHORIZED => "NF-e autorizada com sucesso. Protocolo: {$this->protocol}",
            self::STATUS_PROCESSING => 'NF-e enviada para processamento. Aguardando resposta da SEFAZ.',
            self::STATUS_REJECTED => "NF-e rejeitada pela SEFAZ: {$this->message}",
            self::STATUS_CANCELLED => "NF-e cancelada com sucesso. Protocolo: {$this->protocol}",
            self::STATUS_ERROR => "Erro ao processar NF-e: {$this->message}",
            default => $this->message,
        };
    }
}
