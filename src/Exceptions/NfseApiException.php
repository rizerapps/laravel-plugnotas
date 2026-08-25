<?php

namespace Rizer\PlugNotas\Exceptions;

use Exception;

/**
 * Exception for NFS-e API errors.
 */
class NfseApiException extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        public readonly ?array $response = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Check if this is a rejection from the prefeitura.
     */
    public function isPrefeituraRejection(): bool
    {
        return $this->code >= 100 && $this->code < 1000;
    }

    /**
     * Get the prefeitura error code if available.
     */
    public function getPrefeituraCode(): ?string
    {
        return $this->response['codigo_erro'] ?? $this->response['error_code'] ?? null;
    }

    /**
     * Convert to array for logging.
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'prefeitura_code' => $this->getPrefeituraCode(),
            'response' => $this->response,
        ];
    }
}
