<?php

namespace Rizer\PlugNotas\DTOs;

/**
 * Data Transfer Object for NFS-e (nota de servico) request to TecnoSpeed PlugNotas API.
 *
 * Generico e sem dependencia de Model — quem monta a partir de um Invoice e um
 * Mapper local a cada projeto consumidor (mesmo padrao de NFeRequestDTO).
 *
 * Suporta os dois layouts de NFS-e do PlugNotas:
 * - 'municipal': layout tradicional (ABRASF), usado pela maioria dos municipios.
 * - 'nacional': layout da NFS-e Nacional (Reforma Tributaria).
 *
 * @see https://docs.plugnotas.com.br
 */
class NfseRequestDTO
{
    public function __construct(
        // Provider reference
        public readonly string $providerRef,

        // RPS data
        public readonly string $rpsNumber,
        public readonly string $rpsSeries,
        public readonly string $rpsType, // '1'=RPS, '2'=Nota Conjugada/Mista, '3'=Cupom

        // Company data (prestador)
        public readonly string $companyCnpj,
        public readonly string $companyInscricaoMunicipal,
        public readonly bool $companySimplesNacional,

        // Customer data (tomador)
        public readonly string $customerDocument,
        public readonly string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ?string $customerAddressStreet,
        public readonly ?string $customerAddressNumber,
        public readonly ?string $customerAddressComplement,
        public readonly ?string $customerAddressNeighborhood,
        public readonly ?string $customerAddressCityCode,
        public readonly ?string $customerAddressCityName,
        public readonly ?string $customerAddressState,
        public readonly ?string $customerAddressPostalCode,

        // Service data
        public readonly string $serviceCode,
        public readonly ?string $cnae,
        public readonly string $serviceDescription,
        public readonly float $serviceValue,
        public readonly float $deductionsValue,
        public readonly float $issBase,
        public readonly float $issRate,
        public readonly float $issValue,
        public readonly bool $issRetained,
        public readonly int $issExigibilidade,

        // Federal tax retentions (optional)
        public readonly float $pisValue = 0,
        public readonly float $cofinsValue = 0,
        public readonly float $inssValue = 0,
        public readonly float $irValue = 0,
        public readonly float $csllValue = 0,

        // Location — municipioPrestacao: onde o ISS e devido
        public readonly ?string $serviceLocationCityCode = null,

        // Additional
        public readonly ?string $specialRegime = null,
        public readonly bool $culturalIncentive = false,

        // NFS-e standard: 'municipal' or 'nacional' (reforma tributaria)
        public readonly string $nfseStandard = 'municipal',
    ) {}

    /**
     * Validate required fields before sending to the provider.
     *
     * @throws \InvalidArgumentException with a descriptive message
     */
    public function validate(): void
    {
        $errors = [];

        if (strlen($this->companyCnpj) !== 14) {
            $errors[] = 'CNPJ do prestador invalido ou ausente';
        }

        if (empty($this->companyInscricaoMunicipal)) {
            $errors[] = 'Inscricao municipal do prestador e obrigatoria';
        }

        if (empty($this->rpsNumber) || (int) $this->rpsNumber <= 0) {
            $errors[] = 'Numero do RPS e obrigatorio e deve ser um numero inteiro positivo';
        }

        if (empty($this->rpsSeries)) {
            $errors[] = 'Serie do RPS e obrigatoria';
        }

        if (!in_array($this->rpsType, ['1', '2', '3'], true)) {
            $errors[] = 'Tipo do RPS invalido (deve ser 1, 2 ou 3)';
        }

        $docLen = strlen($this->customerDocument);
        if (!in_array($docLen, [11, 14], true)) {
            $errors[] = 'CPF/CNPJ do tomador invalido';
        }

        if (empty($this->customerName)) {
            $errors[] = 'Razao social do tomador e obrigatoria';
        }

        if (empty($this->serviceCode)) {
            $errors[] = 'Codigo do servico e obrigatorio';
        }

        if (empty($this->serviceDescription)) {
            $errors[] = 'Discriminacao do servico e obrigatoria';
        }

        if ($this->serviceValue <= 0) {
            $errors[] = 'Valor do servico deve ser maior que zero';
        }

        if ($this->issRate < 2.0 || $this->issRate > 5.0) {
            $errors[] = sprintf('Aliquota ISS invalida: %.2f%% (deve estar entre 2%% e 5%%)', $this->issRate);
        }

        if ($this->issValue <= 0) {
            $errors[] = sprintf('Valor ISS invalido: %.2f (deve ser maior que zero)', $this->issValue);
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Payload NFS-e invalido: '.implode('; ', $errors));
        }
    }

    /**
     * Convert to array for API request (PlugNotas format).
     */
    public function toArray(): array
    {
        return $this->nfseStandard === 'nacional'
            ? $this->toNacionalArray()
            : $this->toMunicipalArray();
    }

    /**
     * Payload NFS-e Municipal (layout original PlugNotas/ABRASF).
     */
    private function toMunicipalArray(): array
    {
        $service = [
            'discriminacao' => str_replace(["\r\n", "\r", "\n"], '|', $this->serviceDescription),
            'codigo' => $this->serviceCode,
            'iss' => [
                'aliquota' => $this->issRate,
                'retido' => $this->issRetained,
                'valor' => $this->issValue,
                'exigibilidade' => $this->issExigibilidade,
            ],
            'valor' => [
                'servico' => $this->serviceValue,
                'baseCalculo' => $this->issBase,
                'deducoes' => $this->deductionsValue,
            ],
        ];

        if ($this->cnae) {
            $service['cnae'] = $this->cnae;
        }

        $retencaoFederal = array_filter([
            'pis' => $this->pisValue > 0 ? ['valor' => $this->pisValue] : null,
            'cofins' => $this->cofinsValue > 0 ? ['valor' => $this->cofinsValue] : null,
            'inss' => $this->inssValue > 0 ? ['valor' => $this->inssValue] : null,
            'ir' => $this->irValue > 0 ? ['valor' => $this->irValue] : null,
            'csll' => $this->csllValue > 0 ? ['valor' => $this->csllValue] : null,
        ]);

        if (!empty($retencaoFederal)) {
            $service['retencaoFederal'] = $retencaoFederal;
        }

        $tomadorEndereco = array_filter([
            'logradouro' => $this->customerAddressStreet,
            'numero' => $this->customerAddressNumber,
            'complemento' => $this->customerAddressComplement,
            'bairro' => $this->customerAddressNeighborhood,
            'codigoCidade' => $this->customerAddressCityCode,
            'descricaoCidade' => $this->customerAddressCityName,
            'estado' => $this->customerAddressState,
            'cep' => $this->customerAddressPostalCode,
        ]);

        $payload = [
            'idIntegracao' => $this->providerRef,
            'rps' => [
                'numero' => (int) $this->rpsNumber,
                'serie' => $this->rpsSeries,
                'tipo' => $this->rpsType,
            ],
            'prestador' => array_filter([
                'cpfCnpj' => $this->companyCnpj,
                'inscricaoMunicipal' => $this->companyInscricaoMunicipal ?: null,
                'regimeEspecialTributacao' => $this->specialRegime,
            ]) + [
                'simplesNacional' => $this->companySimplesNacional,
                'incentivadorCultural' => $this->culturalIncentive,
            ],
            'tomador' => array_filter([
                'cpfCnpj' => $this->customerDocument,
                'razaoSocial' => $this->customerName,
                'email' => $this->customerEmail,
                'telefone' => self::splitPhoneDdd($this->customerPhone),
                'endereco' => !empty($tomadorEndereco) ? $tomadorEndereco : null,
            ]),
            'servico' => [$service],
        ];

        if ($this->serviceLocationCityCode) {
            $payload['municipioPrestacao'] = ['codigoCidade' => $this->serviceLocationCityCode];
        }

        return $payload;
    }

    /**
     * Payload NFS-e Nacional (layout PlugNotas Nacional / Reforma Tributaria).
     *
     * @see https://docs.plugnotas.com.br/#tag/NFSe-Nacional
     */
    private function toNacionalArray(): array
    {
        $service = [
            'codigo' => $this->serviceCode,
            'discriminacao' => str_replace(["\r\n", "\r", "\n"], '|', $this->serviceDescription),
            'iss' => [
                'tipoTributacao' => 6,
                'exigibilidade' => $this->issExigibilidade,
                'retido' => $this->issRetained,
                'aliquota' => $this->issRate,
            ],
            'valor' => [
                'servico' => $this->serviceValue,
            ],
        ];

        if ($this->cnae) {
            $service['cnae'] = $this->cnae;
        }

        $tomadorEndereco = array_filter([
            'logradouro' => $this->customerAddressStreet,
            'numero' => $this->customerAddressNumber,
            'complemento' => $this->customerAddressComplement,
            'bairro' => $this->customerAddressNeighborhood,
            'codigoCidade' => $this->customerAddressCityCode,
            'descricaoCidade' => $this->customerAddressCityName,
            'estado' => $this->customerAddressState,
            'cep' => $this->customerAddressPostalCode,
        ]);

        return [
            'idIntegracao' => $this->providerRef,
            'rps' => [
                'numero' => (int) $this->rpsNumber,
                'serie' => $this->rpsSeries,
                'tipo' => $this->rpsType,
            ],
            'emitente' => [
                'tipo' => 1,
                'codigoCidade' => $this->serviceLocationCityCode,
            ],
            'prestador' => [
                'cpfCnpj' => $this->companyCnpj,
            ],
            'tomador' => array_filter([
                'cpfCnpj' => $this->customerDocument,
                'razaoSocial' => $this->customerName,
                'email' => $this->customerEmail,
                'endereco' => !empty($tomadorEndereco) ? $tomadorEndereco : null,
            ]),
            'servico' => [$service],
        ];
    }

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
}
