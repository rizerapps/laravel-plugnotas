<?php

namespace Rizer\PlugNotas\Tests\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;
use Rizer\PlugNotas\Tests\TestCase;

/**
 * Trava o metodo HTTP e o caminho chamados por cada operacao.
 *
 * Os bugs reais do pacote ate aqui foram endpoints errados (rotas que a API
 * nao tem mais), que nao lancam erro de codigo — so aparecem contra a API. Uma
 * mudanca de caminho precisa ser intencional e quebrar este teste.
 *
 * Nao toca a API real: tudo com Http::fake().
 */
class EndpointContractTest extends TestCase
{
    private const DOCUMENT_ID = 'doc123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['id' => self::DOCUMENT_ID, 'situacao' => 'CONCLUIDO'], 200),
        ]);
    }

    private function client(): PlugNotasClient
    {
        return new PlugNotasClient(new PlugNotasCredentials(
            apiKey: 'chave-de-teste',
            environment: 'sandbox',
            cnpj: '11.222.333/0001-81',
            retryTimes: 1,
        ));
    }

    /**
     * @return list<string> "METODO /caminho?query" de cada requisicao, em ordem
     */
    private function requestsSent(): array
    {
        return array_map(function (array $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $query = parse_url($request->url(), PHP_URL_QUERY);

            return $request->method().' '.parse_url($request->url(), PHP_URL_PATH).($query ? "?{$query}" : '');
        }, Http::recorded()->all());
    }

    public static function nfeOperations(): array
    {
        $id = self::DOCUMENT_ID;

        return [
            'createNFe' => [fn (PlugNotasClient $c) => $c->createNFe('ref1', ['emitente' => ['cpfCnpj' => '11222333000181']]), ['POST /nfe']],
            'queryNFe' => [fn (PlugNotasClient $c) => $c->queryNFe('ref1'), ['GET /nfe/ref1/resumo']],
            'findPlugnotasIdByRef' => [fn (PlugNotasClient $c) => $c->findPlugnotasIdByRef('ref1'), ['GET /nfe?idIntegracao=ref1']],
            'queryByAccessKey' => [fn (PlugNotasClient $c) => $c->queryByAccessKey(str_repeat('1', 44)), ['GET /nfe/'.str_repeat('1', 44)]],
            'cancelNFe' => [fn (PlugNotasClient $c) => $c->cancelNFe('ref1', 'Justificativa suficientemente longa'), ['POST /nfe/ref1/cancelamento']],
            'createCCe' => [fn (PlugNotasClient $c) => $c->createCCe('ref1', 'Correcao do endereco do destinatario'), ['GET /nfe/ref1/resumo', "POST /nfe/{$id}/cce"]],
            'downloadDanfe' => [fn (PlugNotasClient $c) => $c->downloadDanfe('ref1'), ['GET /nfe/ref1/resumo', "GET /nfe/{$id}/pdf"]],
            'downloadXml' => [fn (PlugNotasClient $c) => $c->downloadXml('ref1'), ['GET /nfe/ref1/resumo', "GET /nfe/{$id}/xml"]],
            'downloadCancellationXml' => [fn (PlugNotasClient $c) => $c->downloadCancellationXml('ref1'), ['GET /nfe/ref1/cancelamento/xml']],
            'downloadCCeXml' => [fn (PlugNotasClient $c) => $c->downloadCCeXml('ref1', 2), ['GET /nfe/ref1/resumo', "GET /nfe/{$id}/xml/cce/2"]],
        ];
    }

    public static function nfseOperations(): array
    {
        return [
            'createNfse' => [fn (PlugNotasClient $c) => $c->createNfse(['idIntegracao' => 'ref1']), ['POST /nfse']],
            'queryNfse' => [fn (PlugNotasClient $c) => $c->queryNfse('id1'), ['GET /nfse/id1']],
            'queryNfseByIntegration' => [fn (PlugNotasClient $c) => $c->queryNfseByIntegration('ref1'), ['GET /nfse?prestador=11222333000181&idIntegracao=ref1&quantidade=1']],
            'cancelNfse' => [fn (PlugNotasClient $c) => $c->cancelNfse('id1', 'motivo'), ['POST /nfse/cancelar/id1']],
            'downloadNfsePdf' => [fn (PlugNotasClient $c) => $c->downloadNfsePdf('id1'), ['GET /nfse/pdf/id1']],
            'downloadNfseXml' => [fn (PlugNotasClient $c) => $c->downloadNfseXml('id1'), ['GET /nfse/xml/id1']],
        ];
    }

    #[DataProvider('nfeOperations')]
    #[DataProvider('nfseOperations')]
    public function test_operacao_chama_o_endpoint_esperado(callable $operation, array $expected): void
    {
        $operation($this->client());

        $this->assertSame($expected, $this->requestsSent());
    }

    public function test_usa_o_host_do_sandbox_e_autentica_por_header(): void
    {
        $this->client()->queryNfse('id1');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.sandbox.plugnotas.com.br/')
            && $request->hasHeader('x-api-key', 'chave-de-teste'));
    }

    public function test_create_nfe_envia_lista_com_id_integracao_e_cnpj_do_emitente_no_header(): void
    {
        $this->client()->createNFe('ref1', ['emitente' => ['cpfCnpj' => '11.222.333/0001-81']]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return array_is_list($body)
                && ($body[0]['idIntegracao'] ?? null) === 'ref1'
                && $request->hasHeader('x-empresa-cnpj', '11222333000181');
        });
    }
}
