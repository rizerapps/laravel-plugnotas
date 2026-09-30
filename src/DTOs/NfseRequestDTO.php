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

        /*
         * Somente layout nacional (schema `dadosNfseNacional` do PlugNotas). Todos
         * opcionais: null nao entra no payload, e quem nao os informa envia o mesmo
         * JSON de antes. O codigo de tributacao nacional (cTribNac) NAO tem campo
         * proprio: no layout nacional e o `serviceCode` (servico.codigo, 6 digitos).
         * A opcao do Simples (opSimpNac) vem do regime do cadastro da empresa.
         */
        public readonly ?string $nbsCode = null,                 // servico.codigoNbs (cNBS, 9 digitos)
        public readonly ?string $contributorCode = null,         // servico.codigoContribuinte (cIntContrib)
        public readonly ?string $additionalInformation = null,   // informacoesComplementares (xInfComp)
        public readonly ?int $simplesApuracao = null,            // regimeApuracaoTributaria (regApTribSN: 1, 2 ou 3)
        public readonly ?float $approximateFederalTaxPercent = null,   // servico.tributacaoTotal.federal.valorPercentual
        public readonly ?float $approximateStateTaxPercent = null,     // servico.tributacaoTotal.estadual.valorPercentual
        public readonly ?float $approximateMunicipalTaxPercent = null, // servico.tributacaoTotal.municipal.valorPercentual
        public readonly ?string $ibsCbsCst = null,               // servico.ibscbs.valores.tributacao.cst
        public readonly ?string $ibsCbsClassification = null,    // servico.ibscbs.valores.tributacao.cct (cClassTrib)
        public readonly ?string $ibsCbsOperationCode = null,     // servico.ibscbs.codigoOperacao (cIndOp)
        public readonly ?int $ibsCbsPersonalUse = null,          // servico.ibscbs.operacaoPessoal (indFinal: 0 ou 1)
        public readonly ?int $ibsCbsPurpose = null,              // servico.ibscbs.finalidadeNFSe (finNFSe: 0 = normal)

        // Numeração automática do PlugNotas (empresa com `nfse.config.rps.numeracaoAutomatica`):
        // `rps.numero` não vai no payload e o `validate()` não exige `rpsNumber`.
        public readonly bool $rpsAutomatico = false,
    ) {}

    /** Payload do bloco `rps`: sem `numero` quando o PlugNotas numera. */
    private function rps(): array
    {
        return array_filter([
            'numero' => $this->rpsAutomatico ? null : (int) $this->rpsNumber,
            'serie' => $this->rpsSeries,
            'tipo' => $this->rpsType,
        ], fn ($valor) => $valor !== null);
    }

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

        if (!$this->rpsAutomatico && (empty($this->rpsNumber) || (int) $this->rpsNumber <= 0)) {
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

        // Rejeicao E0322 da NFS-e Nacional: IBS/CBS exige a NBS no servico.
        if ($this->nfseStandard === 'nacional' && $this->ibsCbsGroup() !== [] && empty($this->nbsCode)) {
            $errors[] = 'NBS do servico e obrigatoria quando ha IBS/CBS';
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
            'rps' => $this->rps(),
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

        if ($this->nbsCode) {
            $service['codigoNbs'] = $this->nbsCode;
        }

        if ($this->contributorCode) {
            $service['codigoContribuinte'] = $this->contributorCode;
        }

        $approximateTaxes = array_filter([
            'federal' => $this->approximateFederalTaxPercent,
            'estadual' => $this->approximateStateTaxPercent,
            'municipal' => $this->approximateMunicipalTaxPercent,
        ], fn (?float $percent) => $percent !== null);

        if ($approximateTaxes !== []) {
            $service['tributacaoTotal'] = array_map(
                fn (float $percent) => ['valorPercentual' => $percent],
                $approximateTaxes,
            );
        }

        $ibsCbs = $this->ibsCbsGroup();
        if ($ibsCbs !== []) {
            $service['ibscbs'] = $ibsCbs;
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

        // Campos da raiz do layout nacional: so entram quando informados.
        $extra = [];

        if ($this->additionalInformation) {
            // O webservice nacional nao aceita quebra de linha neste campo.
            $extra['informacoesComplementares'] = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $this->additionalInformation));
        }

        if ($this->simplesApuracao !== null) {
            $extra['regimeApuracaoTributaria'] = $this->simplesApuracao;
        }

        return [
            'idIntegracao' => $this->providerRef,
            'rps' => $this->rps(),
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
        ] + $extra;
    }

    /**
     * Grupo IBS/CBS do servico no layout nacional (`servico.ibscbs`).
     *
     * Vazio quando nenhum campo foi informado: o PlugNotas so gera o grupo no XML
     * se o no existir, e mandar o no vazio pediria a NBS (E0322) sem necessidade.
     */
    private function ibsCbsGroup(): array
    {
        $tributacao = array_filter([
            'cst' => $this->ibsCbsCst,
            'cct' => $this->ibsCbsClassification,
        ], fn (?string $value) => $value !== null && $value !== '');

        $group = array_filter([
            'finalidadeNFSe' => $this->ibsCbsPurpose,
            'operacaoPessoal' => $this->ibsCbsPersonalUse,
            'codigoOperacao' => $this->ibsCbsOperationCode ?: null,
        ], fn ($value) => $value !== null);

        if ($tributacao !== []) {
            $group['valores'] = ['tributacao' => $tributacao];
        }

        return $group;
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
