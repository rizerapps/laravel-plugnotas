<?php

namespace Rizer\PlugNotas\Exceptions;

use Exception;
use Throwable;

/**
 * Exception for NF-e API errors.
 *
 * Thrown when communication with NF-e providers fails or returns errors.
 * Contains provider-specific error details for debugging and logging.
 */
class NFeApiException extends Exception
{
    /**
     * Create a new NFeApiException.
     *
     * @param string $message Human-readable error message
     * @param string $provider Provider identifier (tecnospeed, etc.)
     * @param string $errorCode Provider-specific error code
     * @param array|null $response Full provider response for debugging
     * @param int $code Exception code (default: 0)
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $errorCode,
        public readonly ?array $response = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for connection failure.
     */
    public static function connectionFailed(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            message: 'Falha na conexao com o servico de emissao fiscal. Verifique sua conexao.',
            provider: $provider,
            errorCode: 'CONNECTION_FAILED',
            response: null,
            code: 503,
            previous: $previous,
        );
    }

    /**
     * Create exception for authentication failure.
     */
    public static function authenticationFailed(string $provider, ?array $response = null): self
    {
        return new self(
            message: "Falha na autenticacao com o servico de emissao fiscal. Verifique o token da API.",
            provider: $provider,
            errorCode: 'AUTHENTICATION_FAILED',
            response: $response,
            code: 401,
        );
    }

    /**
     * Create exception for validation error.
     */
    public static function validationError(string $provider, string $details, ?array $response = null): self
    {
        return new self(
            message: "Erro de validacao: {$details}",
            provider: $provider,
            errorCode: 'VALIDATION_ERROR',
            response: $response,
            code: 422,
        );
    }

    /**
     * Create exception for SEFAZ rejection.
     */
    public static function sefazRejection(string $provider, string $sefazCode, string $sefazMessage, ?array $response = null): self
    {
        return new self(
            message: "NF-e rejeitada pela SEFAZ: [{$sefazCode}] {$sefazMessage}",
            provider: $provider,
            errorCode: "SEFAZ_{$sefazCode}",
            response: $response,
            code: 422,
        );
    }

    /**
     * Create exception for NF-e not found.
     */
    public static function notFound(string $provider, ?string $reference = null): self
    {
        $message = $reference
            ? "Documento nao encontrado no servico de emissao fiscal: {$reference}"
            : "Documento nao encontrado no servico de emissao fiscal";

        return new self(
            message: $message,
            provider: $provider,
            errorCode: 'NOT_FOUND',
            response: null,
            code: 404,
        );
    }

    /**
     * Create exception for rate limiting.
     */
    public static function rateLimited(string $provider, ?int $retryAfter = null): self
    {
        $message = "Limite de requisicoes excedido no servico de emissao fiscal.";
        if ($retryAfter) {
            $message .= " Tente novamente em {$retryAfter} segundos.";
        }

        return new self(
            message: $message,
            provider: $provider,
            errorCode: 'RATE_LIMITED',
            response: ['retry_after' => $retryAfter],
            code: 429,
        );
    }

    /**
     * Create exception for provider internal error.
     */
    public static function providerError(string $provider, ?string $details = null, ?array $response = null): self
    {
        $message = "Erro interno no servico de emissao fiscal.";
        if ($details) {
            $message .= " Detalhes: {$details}";
        }

        return new self(
            message: $message,
            provider: $provider,
            errorCode: 'PROVIDER_ERROR',
            response: $response,
            code: 502,
        );
    }

    /**
     * Create exception for cancellation error.
     */
    public static function cancellationError(string $provider, string $details, ?array $response = null): self
    {
        return new self(
            message: "Erro ao cancelar documento fiscal: {$details}",
            provider: $provider,
            errorCode: 'CANCELLATION_ERROR',
            response: $response,
            code: 422,
        );
    }

    /**
     * Create exception for CCe error.
     */
    public static function cceError(string $provider, string $details, ?array $response = null): self
    {
        return new self(
            message: "Erro ao registrar carta de correcao: {$details}",
            provider: $provider,
            errorCode: 'CCE_ERROR',
            response: $response,
            code: 422,
        );
    }

    /**
     * Create exception for inutilizacao error.
     */
    public static function inutilizacaoError(string $provider, string $details, ?array $response = null): self
    {
        return new self(
            message: "Erro ao inutilizar numeracao: {$details}",
            provider: $provider,
            errorCode: 'INUTILIZACAO_ERROR',
            response: $response,
            code: 422,
        );
    }

    /**
     * Create exception from provider HTTP response.
     */
    public static function fromResponse(string $provider, int $httpCode, array $response): self
    {
        $errorCode = $response['codigo']
            ?? $response['status_sefaz']
            ?? $response['codigoStatus']
            ?? $response['status']
            ?? 'UNKNOWN';

        // PlugNotas format: {"error": {"message": "...", "data": {"fields": {"campo": "detalhe"}}}}
        if (isset($response['error']) && is_array($response['error'])) {
            $errorObj = $response['error'];
            $mainMsg = $errorObj['message'] ?? null;
            if ($mainMsg && isset($errorObj['data']) && is_array($errorObj['data'])) {
                $dataBlock = $errorObj['data'];
                // PlugNotas may nest field errors under "fields"
                $fieldErrors = $dataBlock['fields'] ?? $dataBlock;
                $parts = [];
                foreach ($fieldErrors as $field => $detail) {
                    if (is_string($detail)) {
                        $parts[] = "{$field}: {$detail}";
                    }
                }
                $details = implode('. ', $parts);
                $message = $details ? "{$mainMsg}: {$details}" : $mainMsg;
            } else {
                $message = $mainMsg ?? 'Erro desconhecido (HTTP '.$httpCode.')';
            }
        } else {
            // TecnoSpeed can return error messages in various fields
            $message = self::extractStringValue($response, 'mensagem')
                ?? self::extractStringValue($response, 'mensagem_sefaz')
                ?? self::extractStringValue($response, 'message')
                ?? self::extractStringValue($response, 'motivo')
                ?? self::extractStringValue($response, 'erro')
                ?? self::extractStringValue($response, 'descricao')
                ?? self::extractErrorFromArray($response)
                ?? 'Erro desconhecido (HTTP '.$httpCode.')';
        }

        return new self(
            message: $message,
            provider: $provider,
            errorCode: (string) $errorCode,
            response: $response,
            code: $httpCode,
        );
    }

    /**
     * Extract a string value from response, handling arrays.
     */
    private static function extractStringValue(array $response, string $key): ?string
    {
        if (!isset($response[$key])) {
            return null;
        }

        $value = $response[$key];

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            // If it's an array, try to get the first string value or json encode it
            $first = reset($value);
            if (is_string($first)) {
                return $first;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Try to extract error message from nested arrays (TecnoSpeed sometimes returns errors in arrays).
     */
    private static function extractErrorFromArray(array $response): ?string
    {
        // Check for 'erros' array (TecnoSpeed format)
        if (isset($response['erros']) && is_array($response['erros'])) {
            $firstError = reset($response['erros']);
            if (is_string($firstError)) {
                return $firstError;
            }
            if (is_array($firstError)) {
                return $firstError['mensagem'] ?? $firstError['message'] ?? $firstError['erro'] ?? null;
            }
        }

        // Check for 'errors' array
        if (isset($response['errors']) && is_array($response['errors'])) {
            $firstError = reset($response['errors']);
            if (is_string($firstError)) {
                return $firstError;
            }
            if (is_array($firstError)) {
                return $firstError['message'] ?? $firstError['mensagem'] ?? null;
            }
        }

        // Check for nested 'data.erro' or 'data.message'
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data']['erro'] ?? $response['data']['message'] ?? $response['data']['mensagem'] ?? null;
        }

        return null;
    }

    /**
     * Get error details for logging.
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'provider' => $this->provider,
            'error_code' => $this->errorCode,
            'http_code' => $this->getCode(),
            'response' => $this->response,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }

    /**
     * Check if error is retryable.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, [
            'CONNECTION_FAILED',
            'RATE_LIMITED',
            'PROVIDER_ERROR',
        ], true);
    }

    /**
     * Get recommended retry delay in seconds.
     */
    public function getRetryDelay(): int
    {
        if ($this->errorCode === 'RATE_LIMITED' && isset($this->response['retry_after'])) {
            return (int) $this->response['retry_after'];
        }

        return match ($this->errorCode) {
            'CONNECTION_FAILED' => 5,
            'RATE_LIMITED' => 60,
            'PROVIDER_ERROR' => 30,
            default => 0,
        };
    }

    /**
     * Check if this is a SEFAZ rejection.
     */
    public function isSefazRejection(): bool
    {
        return str_starts_with($this->errorCode, 'SEFAZ_');
    }

    /**
     * Get SEFAZ rejection code if applicable.
     */
    public function getSefazCode(): ?string
    {
        if ($this->isSefazRejection()) {
            return str_replace('SEFAZ_', '', $this->errorCode);
        }

        return null;
    }
}
