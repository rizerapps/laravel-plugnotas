<?php

namespace Rizer\PlugNotas\Client;

use Rizer\PlugNotas\Exceptions\NFeApiException;

/**
 * Contrato das operacoes de NFS-e expostas pelo client PlugNotas.
 *
 * Permite que o projeto consumidor dependa da interface (e a substitua por um
 * fake em teste) em vez da implementacao concreta.
 */
interface NfseClientInterface
{
    /**
     * Emite uma NFS-e. Aceita um documento ou uma lista de documentos no
     * formato PlugNotas e retorna o primeiro documento da resposta.
     *
     * @throws NFeApiException
     */
    public function createNfse(array $data): array;

    /**
     * Consulta uma NFS-e pelo id devolvido pelo PlugNotas na emissao.
     *
     * @throws NFeApiException
     */
    public function queryNfse(string $id): array;

    /**
     * Consulta uma NFS-e pelo idIntegracao. Exige o CNPJ do prestador em
     * PlugNotasCredentials::$cnpj.
     *
     * @throws NFeApiException
     * @throws \RuntimeException se as credenciais nao tiverem CNPJ
     */
    public function queryNfseByIntegration(string $ref): array;

    /**
     * Solicita o cancelamento de uma NFS-e.
     *
     * @throws NFeApiException
     */
    public function cancelNfse(string $id, ?string $motivo = null): array;

    /**
     * Retorna o conteudo binario do PDF da NFS-e.
     *
     * @throws NFeApiException
     */
    public function downloadNfsePdf(string $id): string;

    /**
     * Retorna o conteudo do XML da NFS-e.
     *
     * @throws NFeApiException
     */
    public function downloadNfseXml(string $id): string;
}
