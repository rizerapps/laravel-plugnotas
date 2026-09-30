# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/), versionamento
[SemVer](https://semver.org/lang/pt-BR/).

## [1.6.0] - 2026-09-30

### Adicionado
- `NfseRequestDTO::$rpsAutomatico` (último parâmetro do construtor, `false` por padrão): para empresa com
  numeração automática no PlugNotas (`nfse.config.rps.numeracaoAutomatica`). Ligado, o payload sai sem
  `rps.numero` (série e tipo continuam) e o `validate()` não exige `rpsNumber`. O número dado pelo
  PlugNotas volta na consulta da nota.

### Compatibilidade
- Sem o parâmetro, nada muda: `rps.numero` continua obrigatório no `validate()` e presente no payload,
  nos dois layouts.

## [1.5.0] - 2026-09-29

### Adicionado
- `NfseRequestDTO`: campos opcionais do layout nacional (schema `dadosNfseNacional` do PlugNotas), todos
  no fim do construtor e com `null` por padrão:
  - `nbsCode` → `servico.codigoNbs`, `contributorCode` → `servico.codigoContribuinte`;
  - `approximateFederalTaxPercent`, `approximateStateTaxPercent`, `approximateMunicipalTaxPercent` →
    `servico.tributacaoTotal.{federal,estadual,municipal}.valorPercentual` (Lei 12.741);
  - `ibsCbsCst`, `ibsCbsClassification`, `ibsCbsOperationCode`, `ibsCbsPersonalUse`, `ibsCbsPurpose` →
    `servico.ibscbs` (`valores.tributacao.cst`/`cct`, `codigoOperacao`, `operacaoPessoal`, `finalidadeNFSe`);
  - `additionalInformation` → `informacoesComplementares` (quebras de linha viram espaço);
  - `simplesApuracao` → `regimeApuracaoTributaria`.
- `validate()` recusa IBS/CBS sem NBS no layout nacional (rejeição E0322).

### Compatibilidade
- Sem os campos novos, o payload é o mesmo da 1.4.2, e o layout municipal não muda. No layout nacional,
  o código de tributação nacional continua sendo o `serviceCode` (`servico.codigo`).

## [1.4.2] - 2026-09-23

### Corrigido
- O alias `plugnotas.ip` não sobrescreve mais um alias que o projeto já tenha registrado. Antes, atualizar
  o pacote num projeto com middleware próprio trocava o middleware em silêncio, e a lista de IPs passava a
  vir de `plugnotas.webhook_ips` (vazia por padrão), o que desligava a proteção do webhook.

## [1.4.1] - 2026-09-23

### Alterado
- `.gitattributes` com `export-ignore`: testes, CI e `phpunit.xml` não vão mais para o `vendor/` dos projetos.

## [1.4.0] - 2026-09-23

### Adicionado
- `PlugNotasServiceProvider` com auto-discovery: config, bind das interfaces, alias de middleware e comando.
- `config/plugnotas.php` publicável (tag `plugnotas-config`): uma chave de API por ambiente, ambiente padrão
  `sandbox`, parâmetros de transporte e IPs de webhook.
- `PlugNotasCredentials::fromConfig($config, $environment, $cnpj)`: escolhe a chave pelo ambiente; valor vazio ou
  desconhecido vira `sandbox`.
- `NfseClientInterface`, implementada por `PlugNotasClient`.
- Middleware `VerifyPlugNotasIp` (alias `plugnotas.ip`), com `webhook_trust_cloudflare` desligado por padrão.
- Comando `php artisan plugnotas:install`.
- Testes de contrato de endpoint, credenciais, middleware, comando e provider; CI (PHP 8.1/Laravel 10 e PHP 8.3/Laravel 13).
- `docs/INSTALACAO.md`.

### Corrigido
- `guzzlehttp/guzzle` declarado em `require`: no Laravel 10 o `illuminate/http` não o traz, e o client HTTP não
  funcionava fora de um projeto que já tivesse o Guzzle.

### Obsoleto
- `validateWebhookSignature()` e `validateWebhookSignatureForCompany()`: o PlugNotas não assina webhooks.
  Use `plugnotas.ip`. Serão removidos na 2.0.

### Removido
- Métodos internos vazios `logRequest()`/`logResponse()`.

## [1.3.0] - 2026-09-02
### Adicionado
- `NfseRequestDTO` genérico para o payload de NFS-e (municipal e nacional).

## [1.2.0] - 2026-09-02
### Corrigido
- `queryNfseByIntegration()` usava rota inexistente; passa a consultar `GET /nfse` por prestador + `idIntegracao`
  (exige `PlugNotasCredentials::$cnpj`).
### Alterado
- `getClient()` e `handleRequestException()` passam a ser `protected`, para subclasses.

## [1.1.0] - 2026-08-25
### Alterado
- `PlugNotasClient` desacoplado de Model, config e container do projeto: recebe `PlugNotasCredentials` prontas.

## [1.0.0] - 2026-08-25
- Versão inicial: client, credenciais, DTOs de NF-e, exceptions e códigos IBGE.
