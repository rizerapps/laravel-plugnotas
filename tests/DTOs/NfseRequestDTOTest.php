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

    /**
     * Quem nao informa os campos nacionais (cramaq, por exemplo) envia o mesmo
     * JSON da 1.4.2, chave por chave.
     */
    public function test_payload_nacional_sem_os_campos_novos_fica_igual_ao_da_1_4_2(): void
    {
        $payload = $this->dto(['nfseStandard' => 'nacional', 'serviceLocationCityCode' => '3550308'])->toArray();

        $this->assertSame(['idIntegracao', 'rps', 'emitente', 'prestador', 'tomador', 'servico'], array_keys($payload));
        $this->assertSame(['codigo', 'discriminacao', 'iss', 'valor'], array_keys($payload['servico'][0]));
    }

    /** Nomes conferidos no schema `dadosNfseNacional` de docs.plugnotas.com.br/api.json. */
    public function test_payload_nacional_leva_os_campos_da_dps(): void
    {
        $payload = $this->dto([
            'nfseStandard' => 'nacional',
            'serviceCode' => '110201',
            'nbsCode' => '118029000',
            'contributorCode' => '8020001',
            'additionalInformation' => "NOTA EMITIDA POR ME OU EPP\nNAO GERA CREDITO",
            'simplesApuracao' => 1,
            'approximateFederalTaxPercent' => 13.45,
            'approximateStateTaxPercent' => 0.0,
            'approximateMunicipalTaxPercent' => 2.39,
            'ibsCbsCst' => '000',
            'ibsCbsClassification' => '000001',
            'ibsCbsOperationCode' => '100301',
            'ibsCbsPersonalUse' => 0,
            'ibsCbsPurpose' => 0,
        ])->toArray();

        $servico = $payload['servico'][0];

        $this->assertSame('110201', $servico['codigo'], 'No layout nacional, servico.codigo e o cTribNac.');
        $this->assertSame('118029000', $servico['codigoNbs']);
        $this->assertSame('8020001', $servico['codigoContribuinte']);
        $this->assertSame([
            'federal' => ['valorPercentual' => 13.45],
            'estadual' => ['valorPercentual' => 0.0],
            'municipal' => ['valorPercentual' => 2.39],
        ], $servico['tributacaoTotal'], 'Zero e informado: so null fica de fora.');
        $this->assertSame([
            'finalidadeNFSe' => 0,
            'operacaoPessoal' => 0,
            'codigoOperacao' => '100301',
            'valores' => ['tributacao' => ['cst' => '000', 'cct' => '000001']],
        ], $servico['ibscbs']);
        $this->assertSame('NOTA EMITIDA POR ME OU EPP NAO GERA CREDITO', $payload['informacoesComplementares'], 'O webservice nao aceita quebra de linha.');
        $this->assertSame(1, $payload['regimeApuracaoTributaria']);
    }

    public function test_campos_nacionais_nao_vazam_para_o_layout_municipal(): void
    {
        $payload = $this->dto(['nbsCode' => '118029000', 'ibsCbsCst' => '000', 'simplesApuracao' => 1])->toArray();

        $this->assertArrayNotHasKey('codigoNbs', $payload['servico'][0]);
        $this->assertArrayNotHasKey('ibscbs', $payload['servico'][0]);
        $this->assertArrayNotHasKey('regimeApuracaoTributaria', $payload);
    }

    public function test_validate_exige_nbs_quando_ha_ibs_cbs_no_layout_nacional(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/NBS do servico/');

        $this->dto(['nfseStandard' => 'nacional', 'ibsCbsCst' => '000'])->validate();
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
