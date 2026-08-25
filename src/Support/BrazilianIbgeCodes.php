<?php

namespace Rizer\PlugNotas\Support;

/**
 * Utility class for Brazilian IBGE municipality codes.
 *
 * IBGE codes are required by SEFAZ for NF-e emission.
 * The code is 7 digits: 2 for state + 5 for municipality.
 *
 * Cobre os 5.571 municipios via resources/ibge-municipios.json empacotado
 * junto, carregado uma vez por processo.
 *
 * Codigo IBGE/BACEN e identificador estavel, nao dado com vigencia fiscal —
 * por isso viaja com o pacote, ao contrario de aliquotas.
 *
 * @see https://www.ibge.gov.br/explica/codigos-dos-municipios.php
 */
class BrazilianIbgeCodes
{
    /**
     * @var array<string, array<string, string>>|null
     */
    private static ?array $municipalities = null;

    /**
     * @return array<string, array<string, string>>
     */
    private static function municipalities(): array
    {
        if (self::$municipalities === null) {
            /*
             * Caminho relativo ao proprio pacote (src/Support -> ../../resources),
             * nunca resource_path(): o pacote nao pode depender da estrutura de
             * diretorios da aplicacao host.
             */
            $path = dirname(__DIR__, 2).'/resources/ibge-municipios.json';

            self::$municipalities = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];
        }

        return self::$municipalities;
    }

    /**
     * State codes (UF) to IBGE state code mapping.
     */
    private const STATE_CODES = [
        'AC' => '12', // Acre
        'AL' => '27', // Alagoas
        'AM' => '13', // Amazonas
        'AP' => '16', // Amapa
        'BA' => '29', // Bahia
        'CE' => '23', // Ceara
        'DF' => '53', // Distrito Federal
        'ES' => '32', // Espirito Santo
        'GO' => '52', // Goias
        'MA' => '21', // Maranhao
        'MG' => '31', // Minas Gerais
        'MS' => '50', // Mato Grosso do Sul
        'MT' => '51', // Mato Grosso
        'PA' => '15', // Para
        'PB' => '25', // Paraiba
        'PE' => '26', // Pernambuco
        'PI' => '22', // Piaui
        'PR' => '41', // Parana
        'RJ' => '33', // Rio de Janeiro
        'RN' => '24', // Rio Grande do Norte
        'RO' => '11', // Rondonia
        'RR' => '14', // Roraima
        'RS' => '43', // Rio Grande do Sul
        'SC' => '42', // Santa Catarina
        'SE' => '28', // Sergipe
        'SP' => '35', // Sao Paulo
        'TO' => '17', // Tocantins
        'EX' => '99', // Exterior
    ];

    /**
     * Get IBGE code for a city/state combination.
     *
     * @param string $city City name
     * @param string $state State abbreviation (UF)
     * @return string|null IBGE code or null if not found
     */
    public static function getCode(string $city, string $state): ?string
    {
        $state = strtoupper(trim($state));
        $cityNormalized = self::normalizeString($city);

        // UF invalida/desconhecida: unico caso em que vale buscar em qualquer estado
        if (!isset(self::STATE_CODES[$state])) {
            return self::findCityInAnyState($cityNormalized);
        }

        $municipalities = self::municipalities();

        if (isset($municipalities[$state][$cityNormalized])) {
            return $municipalities[$state][$cityNormalized];
        }

        /*
         * UF valida mas cidade nao encontrada nela: NAO cair para outros estados.
         * Com as 5.571 cidades do Brasil carregadas, nomes repetidos entre UFs
         * (ex: "Bom Jesus" existe em varios estados) tornariam esse fallback
         * uma fonte de falso positivo. Melhor retornar null e deixar a camada
         * de API (que consulta somente municipios da propria UF) tentar.
         */
        return null;
    }

    /**
     * Busca a cidade em qualquer estado. Usado apenas quando a UF informada
     * e invalida/desconhecida (dado de origem malformado).
     *
     * @param string $cityNormalized Normalized city name
     * @return string|null IBGE code or null if not found
     */
    private static function findCityInAnyState(string $cityNormalized): ?string
    {
        foreach (self::municipalities() as $stateCities) {
            if (isset($stateCities[$cityNormalized])) {
                return $stateCities[$cityNormalized];
            }
        }

        return null;
    }

    /**
     * Get state IBGE code from UF.
     *
     * @param string $uf State abbreviation
     * @return string|null State code (2 digits)
     */
    public static function getStateCode(string $uf): ?string
    {
        $uf = strtoupper(trim($uf));

        return self::STATE_CODES[$uf] ?? null;
    }

    /**
     * Check if UF is valid.
     *
     * @param string $uf State abbreviation
     */
    public static function isValidUf(string $uf): bool
    {
        return isset(self::STATE_CODES[strtoupper(trim($uf))]);
    }

    /**
     * Get exterior code (for foreign addresses).
     */
    public static function getExteriorCode(): string
    {
        return '9999999';
    }

    /**
     * Check if code is for exterior.
     */
    public static function isExteriorCode(string $code): bool
    {
        return $code === '9999999';
    }

    /**
     * Get Brazil country code (BACEN/ISO).
     */
    public static function getBrazilCountryCode(): string
    {
        return '1058';
    }

    /**
     * Normalize string for comparison (remove accents, lowercase).
     */
    private static function normalizeString(string $string): string
    {
        $string = mb_strtolower(trim($string));

        // Remove accents
        $string = preg_replace(
            ['/[áàâãä]/u', '/[éèêë]/u', '/[íìîï]/u', '/[óòôõö]/u', '/[úùûü]/u', '/[ç]/u', '/[ñ]/u'],
            ['a', 'e', 'i', 'o', 'u', 'c', 'n'],
            $string
        );

        return $string;
    }

    /**
     * Get all state UF codes.
     *
     * @return array<string, string>
     */
    public static function getAllStates(): array
    {
        return self::STATE_CODES;
    }

    /**
     * Get all major cities for a state.
     *
     * @param string $uf State abbreviation
     * @return array<string, string>
     */
    public static function getCitiesForState(string $uf): array
    {
        $uf = strtoupper(trim($uf));

        return self::municipalities()[$uf] ?? [];
    }
}
