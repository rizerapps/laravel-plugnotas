<?php

namespace Rizer\PlugNotas\DTOs;

use Rizer\PlugNotas\Support\BrazilianIbgeCodes;

/**
 * Data Transfer Object for NF-e request to TecnoSpeed PlugNotas API.
 *
 * Generico e sem dependencia de Model — quem monta a partir de um Invoice e o
 * App\Domains\Tax\Mappers\InvoiceToNFePayloadMapper.
 *
 * IMPORTANT: TecnoSpeed PlugNotas requires:
 * - codigoCidade (IBGE 7-digit code) for addresses
 * - descricaoCidade (city name) alongside codigoCidade
 * - codigoPais (BACEN country code) - "1058" for Brazil
 * - descricaoPais (country name) - "Brasil" for Brazil
 */
class NFeRequestDTO
{
    public function __construct(
        // Document identification
        public readonly string $natureOperation,
        public readonly int $documentType, // 0=Entrada, 1=Saida
        public readonly int $issuePurpose, // 1=Normal, 2=Complementar, 3=Ajuste, 4=Devolucao
        public readonly int $presenceIndicator, // 0=Nao se aplica, 1=Presencial, etc.
        public readonly int $finalConsumer, // 0=Normal, 1=Consumidor final

        // Emitter (company)
        public readonly string $emitterCnpj,
        public readonly string $emitterName, // Razão Social
        public readonly ?string $emitterStateRegistration,
        public readonly int $emitterTaxRegime, // 1=Simples, 2=Simples excesso, 3=Regime normal

        // Recipient (customer)
        public readonly string $recipientName,
        public readonly ?string $recipientCnpj,
        public readonly ?string $recipientCpf,
        public readonly ?string $recipientStateRegistration,
        public readonly ?string $recipientStreet,
        public readonly ?string $recipientNumber,
        public readonly ?string $recipientComplement,
        public readonly ?string $recipientNeighborhood,
        public readonly ?string $recipientCity,
        public readonly ?string $recipientState,
        public readonly ?string $recipientCityIbgeCode,
        public readonly ?string $recipientPostalCode,
        public readonly ?string $recipientCountry,
        public readonly ?string $recipientPhone,
        public readonly ?string $recipientEmail,

        // Items
        public readonly array $items,

        // Payment
        public readonly array $paymentForms,

        // Shipping
        public readonly int $shippingModality, // 0=Por conta do emitente, 1=Por conta do destinatario, etc.
        public readonly ?array $shippingData,

        // Additional
        public readonly ?string $additionalInfo,
        public readonly ?string $fiscalAdditionalInfo,

        // Totals (optional)
        public readonly ?float $productsTotal = null,
        public readonly ?float $discountTotal = null,
        public readonly ?float $shippingTotal = null,
        public readonly ?float $insuranceTotal = null,
        public readonly ?float $otherExpenses = null,

        // Technical responsible (infRespTec — required by some states like BA)
        public readonly ?array $responsavelTecnico = null,
    ) {}

    /**
     * Split a Brazilian phone number into PlugNotas' "ddd" + "numero" object.
     * DDD is always the first 2 digits of the cleaned number.
     */
    private static function splitPhoneDdd(?string $phone): ?array
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 3) {
            return null;
        }

        return [
            'ddd' => substr($digits, 0, 2),
            'numero' => substr($digits, 2),
        ];
    }

    /**
     * Convert DTO to array for TecnoSpeed PlugNotas API.
     */
    public function toArray(): array
    {
        return $this->toTecnoSpeedArray();
    }

    /**
     * Convert DTO to array for TecnoSpeed PlugNotas API.
     */
    public function toTecnoSpeedArray(): array
    {
        $data = [
            'natureza' => $this->natureOperation,
            'tipoDocumento' => $this->documentType,
            'finalidade' => $this->issuePurpose,
            'presencial' => $this->presenceIndicator,
            'consumidorFinal' => $this->finalConsumer === 1,

            // Emitter
            'emitente' => $this->buildTecnoSpeedEmitter(),

            // Recipient
            'destinatario' => $this->buildTecnoSpeedRecipient(),

            // Items
            'itens' => $this->buildTecnoSpeedItems(),

            // Payment
            'pagamentos' => $this->buildTecnoSpeedPayments(),

            // Shipping
            'transporte' => [
                'modalidadeFrete' => $this->shippingModality,
            ],
        ];

        // Add additional info
        if ($this->additionalInfo || $this->fiscalAdditionalInfo) {
            $data['informacoesAdicionais'] = [];
            if ($this->additionalInfo) {
                $data['informacoesAdicionais']['contribuinte'] = $this->additionalInfo;
            }
            if ($this->fiscalAdditionalInfo) {
                $data['informacoesAdicionais']['fisco'] = $this->fiscalAdditionalInfo;
            }
        }

        // Add shipping data if present
        if ($this->shippingData) {
            $data['transporte'] = array_merge($data['transporte'], $this->shippingData);
        }

        if ($this->responsavelTecnico) {
            $data['responsavelTecnico'] = $this->responsavelTecnico;
        }

        return $data;
    }

    /**
     * Build emitter data for TecnoSpeed.
     *
     * NOTE: For TecnoSpeed PlugNotas, the emitente (emitter/company) should be
     * PRE-REGISTERED via the /empresa endpoint with complete data including
     * address and digital certificate. When issuing NF-e, you only need to
     * reference the company by CNPJ.
     *
     * If the company is registered, you can use simplified emitter:
     * { "cpfCnpj": "CNPJ_HERE" }
     *
     * If not registered, you need full data including address.
     */
    private function buildTecnoSpeedEmitter(): array
    {
        // For registered companies, we can use simplified emitter
        // TecnoSpeed will fetch the company data from their database
        return [
            'cpfCnpj' => $this->emitterCnpj,
        ];
    }

    /**
     * Build recipient data for TecnoSpeed.
     *
     * TecnoSpeed PlugNotas requires specific address fields:
     * - codigoCidade: IBGE municipality code (7 digits)
     * - descricaoCidade: Municipality name
     * - estado: UF (2 letters)
     * - codigoPais: BACEN country code ("1058" for Brazil)
     * - descricaoPais: Country name ("Brasil" for Brazil)
     */
    private function buildTecnoSpeedRecipient(): array
    {
        $recipient = [];

        // Add document - TecnoSpeed uses unified cpfCnpj field
        // IMPORTANT: cpfCnpj should come before nome for proper validation
        if ($this->recipientCnpj) {
            $recipient['cpfCnpj'] = $this->recipientCnpj;
        } elseif ($this->recipientCpf) {
            $recipient['cpfCnpj'] = $this->recipientCpf;
        }

        // Add name
        $recipient['razaoSocial'] = $this->recipientName;

        // Add state registration if present
        if ($this->recipientStateRegistration) {
            $recipient['inscricaoEstadual'] = $this->recipientStateRegistration;
        }

        // Determine if this is a foreign address
        $isForeign = $this->recipientCountry && strtoupper($this->recipientCountry) !== 'BR' && strtoupper($this->recipientCountry) !== 'BRASIL';

        // Build address
        $endereco = [];

        if ($this->recipientStreet) {
            $endereco['logradouro'] = $this->recipientStreet;
        }
        if ($this->recipientNumber) {
            $endereco['numero'] = $this->recipientNumber;
        }
        if ($this->recipientComplement) {
            $endereco['complemento'] = $this->recipientComplement;
        }
        if ($this->recipientNeighborhood) {
            $endereco['bairro'] = $this->recipientNeighborhood;
        }

        // Handle city code and description
        if ($isForeign) {
            // Foreign address
            $endereco['codigoCidade'] = BrazilianIbgeCodes::getExteriorCode();
            $endereco['descricaoCidade'] = 'EXTERIOR';
            $endereco['estado'] = 'EX';
            $endereco['codigoPais'] = $this->getCountryCode($this->recipientCountry);
            $endereco['descricaoPais'] = $this->recipientCountry;
        } else {
            // Brazilian address - prefer the IBGE code already resolved on the customer record,
            // falling back to the (incomplete) static lookup table only when it's missing
            $ibgeCode = $this->recipientCityIbgeCode;
            if (!$ibgeCode && $this->recipientCity && $this->recipientState) {
                $ibgeCode = BrazilianIbgeCodes::getCode($this->recipientCity, $this->recipientState);
            }

            if ($ibgeCode) {
                $endereco['codigoCidade'] = $ibgeCode;
            }

            // Always include descricaoCidade (required if codigoCidade is invalid or missing)
            if ($this->recipientCity) {
                $endereco['descricaoCidade'] = $this->recipientCity;
            }

            if ($this->recipientState) {
                $endereco['estado'] = strtoupper($this->recipientState);
            }

            // Brazil country info
            $endereco['codigoPais'] = BrazilianIbgeCodes::getBrazilCountryCode();
            $endereco['descricaoPais'] = 'Brasil';
        }

        if ($this->recipientPostalCode) {
            $endereco['cep'] = preg_replace('/\D/', '', $this->recipientPostalCode);
        }

        $recipient['endereco'] = $endereco;

        // Contact info (outside endereco object)
        // PlugNotas expects telefone as an object with "ddd" and "numero", not a flat string
        $phone = self::splitPhoneDdd($this->recipientPhone);
        if ($phone) {
            $recipient['telefone'] = $phone;
        }
        if ($this->recipientEmail) {
            $recipient['email'] = $this->recipientEmail;
        }

        return $recipient;
    }

    /**
     * Get BACEN country code for a given country name/code.
     *
     * @param string $country Country name or ISO code
     * @return string BACEN country code
     */
    private function getCountryCode(string $country): string
    {
        // Common country codes (BACEN)
        $countryCodes = [
            'BR' => '1058',
            'BRASIL' => '1058',
            'BRAZIL' => '1058',
            'US' => '2496',
            'USA' => '2496',
            'UNITED STATES' => '2496',
            'ESTADOS UNIDOS' => '2496',
            'AR' => '0639',
            'ARGENTINA' => '0639',
            'PY' => '5860',
            'PARAGUAY' => '5860',
            'PARAGUAI' => '5860',
            'UY' => '8451',
            'URUGUAY' => '8451',
            'URUGUAI' => '8451',
            'CL' => '1589',
            'CHILE' => '1589',
            'CO' => '1830',
            'COLOMBIA' => '1830',
            'MX' => '4880',
            'MEXICO' => '4880',
            'DE' => '0230',
            'GERMANY' => '0230',
            'ALEMANHA' => '0230',
            'FR' => '2399',
            'FRANCE' => '2399',
            'FRANCA' => '2399',
            'IT' => '3867',
            'ITALY' => '3867',
            'ITALIA' => '3867',
            'ES' => '2453',
            'SPAIN' => '2453',
            'ESPANHA' => '2453',
            'PT' => '6076',
            'PORTUGAL' => '6076',
            'GB' => '6289',
            'UK' => '6289',
            'UNITED KINGDOM' => '6289',
            'REINO UNIDO' => '6289',
            'CN' => '1600',
            'CHINA' => '1600',
            'JP' => '3999',
            'JAPAN' => '3999',
            'JAPAO' => '3999',
        ];

        $countryUpper = strtoupper(trim($country));

        return $countryCodes[$countryUpper] ?? '9999'; // 9999 = outros/unknown
    }

    /**
     * Build items data for TecnoSpeed.
     */
    private function buildTecnoSpeedItems(): array
    {
        $items = [];

        foreach ($this->items as $item) {
            $valorComercial = (float) ($item['valor_unitario_comercial'] ?? 0);
            $valorTributavel = (float) ($item['valor_unitario_tributavel'] ?? $valorComercial);
            $valorBruto = (float) ($item['valor_bruto'] ?? 0);

            $quantidadeComercial = (float) ($item['quantidade_comercial'] ?? 1);
            $quantidadeTributavel = (float) ($item['quantidade_tributavel'] ?? $quantidadeComercial);
            $unidadeComercial = $item['unidade_comercial'] ?? 'UN';
            $unidadeTributavel = $item['unidade_tributavel'] ?? $unidadeComercial;

            $tecnoSpeedItem = [
                'codigo' => $item['codigo_produto'] ?? '',
                'descricao' => $item['descricao'] ?? '',
                'ncm' => $item['ncm'] ?? '00000000',
                'cfop' => $item['cfop'] ?? '5102',
                'quantidade' => [
                    'comercial' => $quantidadeComercial,
                    'tributavel' => $quantidadeTributavel,
                ],
                'unidade' => [
                    'comercial' => $unidadeComercial,
                    'tributavel' => $unidadeTributavel,
                ],
                'valorUnitario' => [
                    'comercial' => $valorComercial,
                    'tributavel' => $valorTributavel,
                ],
                'valor' => $valorBruto,
            ];

            // Add discount if present
            if (isset($item['valor_desconto']) && $item['valor_desconto'] > 0) {
                $tecnoSpeedItem['valorDesconto'] = (float) $item['valor_desconto'];
            }

            // Add CEST if present
            if (!empty($item['cest'])) {
                $tecnoSpeedItem['cest'] = $item['cest'];
            }

            // Tributos (PlugNotas requires taxes nested under 'tributos')
            $tributos = [];

            // PlugNotas sempre usa 'cst' — para Simples Nacional o valor CSOSN vai nesse campo
            $isSimples = $item['_is_simples'] ?? false;
            $icmsDefaultCode = $isSimples ? '400' : '00';

            $cst = $item['icms_situacao_tributaria'] ?? $icmsDefaultCode;

            // CST/CSOSN que NÃO permitem valor/aliquota/baseCalculo (isentos/não tributados)
            // Regime Normal: 40=Isenta, 41=Não tributada, 50=Suspensão, 60=Cobrado anteriormente via ST
            // Simples Nacional: 102, 103, 300=Imune, 400=Não tributado, 500=Cobrado anteriormente via ST
            $cstSemTributacao = ['40', '41', '50', '60', '102', '103', '300', '400', '500'];

            // CSOSN Simples Nacional com crédito — exigem percentualCreditoSN + valorCreditoSN
            // (mapeados de icms_rate e icms_value; sem baseCalculo/aliquota/valor)
            $cstCreditoSN = ['101', '201'];

            // ICMS
            $tributos['icms'] = [
                'origem' => (string) ($item['icms_origem'] ?? 0),
                'cst' => $cst,
            ];

            if (in_array($cst, $cstCreditoSN, true)) {
                // CSOSN 101/201: crédito de ICMS do Simples Nacional
                $tributos['icms']['percentualCreditoSN'] = (float) ($item['icms_aliquota'] ?? 0);
                $tributos['icms']['valorCreditoSN'] = (float) ($item['icms_valor'] ?? 0);
            } elseif (!in_array($cst, $cstSemTributacao, true)) {
                // CST com tributação normal
                $tributos['icms']['baseCalculo'] = [
                    'modalidadeDeterminacao' => 0,
                    'valor' => (float) ($item['icms_base_calculo'] ?? $valorBruto),
                ];
                $tributos['icms']['aliquota'] = (float) ($item['icms_aliquota'] ?? 0);
                $tributos['icms']['valor'] = (float) ($item['icms_valor'] ?? 0);
            }

            if (isset($item['icms_percentual_reducao_base_calculo']) && $item['icms_percentual_reducao_base_calculo'] > 0) {
                $tributos['icms']['baseCalculo']['percentualReducao'] = (float) $item['icms_percentual_reducao_base_calculo'];
            }

            // ICMS ST
            if (isset($item['icms_valor_st']) && $item['icms_valor_st'] > 0) {
                $tributos['icms']['baseCalculoST'] = [
                    'valor' => (float) ($item['icms_base_calculo_st'] ?? 0),
                ];
                $tributos['icms']['aliquotaST'] = (float) ($item['icms_aliquota_st'] ?? 0);
                $tributos['icms']['valorST'] = (float) $item['icms_valor_st'];
                if (isset($item['icms_margem_valor_adicionado_st']) && $item['icms_margem_valor_adicionado_st'] > 0) {
                    $tributos['icms']['baseCalculoST']['margemValorAdicionado'] = (float) $item['icms_margem_valor_adicionado_st'];
                }
            }

            /*
             * CST 04-09 → PISNT/COFINSNT (sem tributação): enviar APENAS cst, sem baseCalculo/aliquota/valor.
             * CST 01-02 → PISAliq/COFINSAliq; CST 49/50/70/99 → PISOutr/COFINSOutr.
             * Enviar campos numéricos para CST NT faz o PlugNotas gerar PISAliq com CST inválido
             * no XSD da NF-e → cStat 225 "Falha no Schema XML do lote de NFe".
             */
            $cstNT = ['04', '05', '06', '07', '08', '09'];

            // PIS
            $pisCST = $item['pis_situacao_tributaria'] ?? '07';
            $tributos['pis'] = ['cst' => $pisCST];
            if (!in_array($pisCST, $cstNT, true)) {
                $tributos['pis']['baseCalculo'] = ['valor' => (float) ($item['pis_base_calculo'] ?? 0)];
                $tributos['pis']['aliquota']    = (float) ($item['pis_aliquota_porcentual'] ?? 0);
                $tributos['pis']['valor']       = (float) ($item['pis_valor'] ?? 0);
            }

            // COFINS
            $cofinsCST = $item['cofins_situacao_tributaria'] ?? '07';
            $tributos['cofins'] = ['cst' => $cofinsCST];
            if (!in_array($cofinsCST, $cstNT, true)) {
                $tributos['cofins']['baseCalculo'] = ['valor' => (float) ($item['cofins_base_calculo'] ?? 0)];
                $tributos['cofins']['aliquota']    = (float) ($item['cofins_aliquota_porcentual'] ?? 0);
                $tributos['cofins']['valor']       = (float) ($item['cofins_valor'] ?? 0);
            }

            // IPI
            if (!empty($item['ipi_situacao_tributaria'])) {
                $tributos['ipi'] = [
                    'cst' => $item['ipi_situacao_tributaria'],
                    'baseCalculo' => [
                        'valor' => (float) ($item['ipi_base_calculo'] ?? 0),
                    ],
                    'aliquota' => (float) ($item['ipi_aliquota'] ?? 0),
                    'valor' => (float) ($item['ipi_valor'] ?? 0),
                ];
            }

            /*
             * IBS/CBS (Reforma Tributaria) — obrigatorio a partir de 03/08/2026.
             * Ja vem calculado de buildItemData(); ausente quando o regime do
             * emitente ainda nao esta obrigado.
             */
            if (!empty($item['ibscbs'])) {
                $tributos['ibscbs'] = $item['ibscbs'];
            }

            $tecnoSpeedItem['tributos'] = $tributos;

            // Approximate tax (Lei 12.741)
            if (isset($item['valor_aproximado_tributos']) && $item['valor_aproximado_tributos'] > 0) {
                $tecnoSpeedItem['valorAproximadoTributos'] = (float) $item['valor_aproximado_tributos'];
            }

            // Benefit code
            if (!empty($item['codigo_beneficio_fiscal'])) {
                $tecnoSpeedItem['codigoBeneficioFiscal'] = $item['codigo_beneficio_fiscal'];
            }

            $items[] = $tecnoSpeedItem;
        }

        return $items;
    }

    /**
     * Build payments data for TecnoSpeed.
     */
    private function buildTecnoSpeedPayments(): array
    {
        $payments = [];

        foreach ($this->paymentForms as $payment) {
            $meio = $payment['forma_pagamento'] ?? '99';

            $paymentData = [
                'meio' => $meio,
                'valor' => (float) ($payment['valor_pagamento'] ?? 0),
            ];

            // PlugNotas requires "descricaoMeio" whenever "meio" is "99" (Outros)
            if ($meio === '99') {
                $paymentData['descricaoMeio'] = $payment['descricao_meio'] ?? 'Outros';
            }

            /*
             * NT 2025.001: o grupo "cartao" passou a ser obrigatorio tambem para
             * PIX (17), alem de Cartao de Credito/Debito (03/04) e 20. Como este ERP
             * nao tem integracao com TEF/maquininha, informamos "nao integrado" (2),
             * o que dispensa CNPJ da credenciadora e codigo de autorizacao.
             */
            if (in_array($meio, ['03', '04', '17', '20'], true)) {
                $paymentData['cartao'] = ['tipoIntegracao' => '2'];
            }

            $payments[] = $paymentData;
        }

        return $payments;
    }

    /**
     * Create from raw array data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            natureOperation: $data['natureza_operacao'] ?? 'Venda de mercadoria',
            documentType: (int) ($data['tipo_documento'] ?? 1),
            issuePurpose: (int) ($data['finalidade_emissao'] ?? 1),
            presenceIndicator: (int) ($data['presenca_comprador'] ?? 0),
            finalConsumer: (int) ($data['consumidor_final'] ?? 0),

            emitterCnpj: $data['cnpj_emitente'],
            emitterName: $data['razao_social_emitente'] ?? $data['nome_emitente'] ?? '',
            emitterStateRegistration: $data['inscricao_estadual_emitente'] ?? null,
            emitterTaxRegime: (int) ($data['regime_tributario_emitente'] ?? 1),

            recipientName: $data['nome_destinatario'],
            recipientCnpj: $data['cnpj_destinatario'] ?? null,
            recipientCpf: $data['cpf_destinatario'] ?? null,
            recipientStateRegistration: $data['inscricao_estadual_destinatario'] ?? null,
            recipientStreet: $data['logradouro_destinatario'] ?? null,
            recipientNumber: $data['numero_destinatario'] ?? null,
            recipientComplement: $data['complemento_destinatario'] ?? null,
            recipientNeighborhood: $data['bairro_destinatario'] ?? null,
            recipientCity: $data['municipio_destinatario'] ?? null,
            recipientState: $data['uf_destinatario'] ?? null,
            recipientCityIbgeCode: $data['codigo_ibge_destinatario'] ?? null,
            recipientPostalCode: $data['cep_destinatario'] ?? null,
            recipientCountry: $data['pais_destinatario'] ?? 'Brasil',
            recipientPhone: $data['telefone_destinatario'] ?? null,
            recipientEmail: $data['email_destinatario'] ?? null,

            items: $data['items'] ?? [],
            paymentForms: $data['formas_pagamento'] ?? [],

            shippingModality: (int) ($data['modalidade_frete'] ?? 9),
            shippingData: $data['shipping_data'] ?? null,

            additionalInfo: $data['informacoes_adicionais_contribuinte'] ?? null,
            fiscalAdditionalInfo: $data['informacoes_adicionais_fisco'] ?? null,

            productsTotal: $data['products_total'] ?? null,
            discountTotal: $data['discount_total'] ?? null,
            shippingTotal: $data['shipping_total'] ?? null,
            insuranceTotal: $data['insurance_total'] ?? null,
            otherExpenses: $data['other_expenses'] ?? null,
        );
    }
}
