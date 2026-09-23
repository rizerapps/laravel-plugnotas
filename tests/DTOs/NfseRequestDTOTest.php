<?php

namespace Rizer\PlugNotas\Tests\DTOs;

use PHPUnit\Framework\TestCase;
use Rizer\PlugNotas\DTOs\NfseRequestDTO;

/**
 * Trava o formato do payload NFS-e (municipal e nacional) contra a API do
 * PlugNotas. Formato validado contra emissoes reais de NFS-e.
 */
class NfseRequestDTOTest extends TestCase
{
    private function dto(array $overrides = []): NfseRequestDTO
    {
        $defaults = [
            'providerRef' => 'nfse_1_1_teste',
            'rpsNumber' => '42',
            'rpsSeries' => 'RPS',
            'rpsType' => '1',
            'companyCnpj' => '11222333000181',
            'companyInscricaoMunicipal' => '12345',
            'companySimplesNacional' => true,
            'customerDocument' => '52998224725',
            'customerName' => 'Cliente Teste',
            'customerEmail' => 'cliente@teste.com',
            'customerPhone' => '11988887777',
            'customerAddressStreet' => 'Rua Teste',
            'customerAddressNumber' => '100',
            'customerAddressComplement' => null,
            'customerAddressNeighborhood' => 'Centro',
            'customerAddressCityCode' => '3550308',
            'customerAddressCityName' => 'São Paulo',
            'customerAddressState' => 'SP',
            'customerAddressPostalCode' => '01001000',
            'serviceCode' => '0107',
            'cnae' => null,
            'serviceDescription' => 'Serviço de manutenção',
            'serviceValue' => 1000.00,
            'deductionsValue' => 0,
            'issBase' => 1000.00,
            'issRate' => 5.0,
            'issValue' => 50.00,
            'issRetained' => false,
            'issExigibilidade' => 1,
        ];

        return new NfseRequestDTO(...array_merge($defaults, $overrides));
    }

    public function test_payload_municipal_tem_a_estrutura_esperada(): void
    {
        $payload = $this->dto()->toArray();

        $this->assertSame('nfse_1_1_teste', $payload['idIntegracao']);
        $this->assertSame(['numero' => 42, 'serie' => 'RPS', 'tipo' => '1'], $payload['rps']);
        $this->assertSame('11222333000181', $payload['prestador']['cpfCnpj']);
        $this->assertTrue($payload['prestador']['simplesNacional']);
        $this->assertFalse($payload['prestador']['incentivadorCultural']);
        $this->assertSame('52998224725', $payload['tomador']['cpfCnpj']);
        $this->assertSame(['ddd' => '11', 'numero' => '988887777'], $payload['tomador']['telefone']);
        $this->assertSame('São Paulo', $payload['tomador']['endereco']['descricaoCidade']);
        $this->assertSame(1000.00, $payload['servico'][0]['valor']['servico']);
        $this->assertSame(5.0, $payload['servico'][0]['iss']['aliquota']);
        $this->assertArrayNotHasKey('retencaoFederal', $payload['servico'][0], 'Sem retenção, a chave não deve aparecer.');
    }

    public function test_retencoes_federais_usam_a_chave_retencaoFederal_singular(): void
    {
        $payload = $this->dto(['pisValue' => 10.0, 'cofinsValue' => 5.0])->toArray();

        $this->assertSame(
            ['pis' => ['valor' => 10.0], 'cofins' => ['valor' => 5.0]],
            $payload['servico'][0]['retencaoFederal'],
            'Nome de campo validado contra emissão real — não é "retencoesFederais".'
        );
    }

    public function test_discriminacao_com_quebra_de_linha_vira_pipe(): void
    {
        $payload = $this->dto(['serviceDescription' => "Linha 1\nLinha 2\r\nLinha 3"])->toArray();

        $this->assertSame('Linha 1|Linha 2|Linha 3', $payload['servico'][0]['discriminacao']);
    }

    public function test_payload_nacional_usa_layout_diferente(): void
    {
        $payload = $this->dto([
            'nfseStandard' => 'nacional',
            'serviceLocationCityCode' => '3550308',
        ])->toArray();

        $this->assertSame(['tipo' => 1, 'codigoCidade' => '3550308'], $payload['emitente']);
        $this->assertSame(['cpfCnpj' => '11222333000181'], $payload['prestador'], 'Layout nacional não inclui inscrição municipal/simplesNacional no prestador.');
        $this->assertSame(6, $payload['servico'][0]['iss']['tipoTributacao']);
        $this->assertArrayNotHasKey('municipioPrestacao', $payload, 'municipioPrestacao é exclusivo do layout municipal.');
    }

    public function test_telefone_ausente_nao_aparece_no_payload(): void
    {
        $payload = $this->dto(['customerPhone' => null])->toArray();

        $this->assertArrayNotHasKey('telefone', $payload['tomador']);
    }

    public function test_validate_aceita_payload_completo(): void
    {
        $this->dto()->validate();

        $this->addToAssertionCount(1);
    }

    public function test_validate_rejeita_cnpj_do_prestador_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CNPJ do prestador/');

        $this->dto(['companyCnpj' => '123'])->validate();
    }

    public function test_validate_rejeita_aliquota_iss_fora_da_faixa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Aliquota ISS invalida/');

        $this->dto(['issRate' => 10.0])->validate();
    }

    public function test_validate_rejeita_valor_de_servico_zerado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Valor do servico/');

        $this->dto(['serviceValue' => 0])->validate();
    }

    public function test_validate_acumula_todos_os_erros_na_mensagem(): void
    {
        try {
            $this->dto(['companyCnpj' => '', 'rpsNumber' => '', 'serviceValue' => 0])->validate();
            $this->fail('Deveria ter lançado InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('CNPJ do prestador', $e->getMessage());
            $this->assertStringContainsString('RPS', $e->getMessage());
            $this->assertStringContainsString('Valor do servico', $e->getMessage());
        }
    }
}
