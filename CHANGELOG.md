# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/), versionamento
[SemVer](https://semver.org/lang/pt-BR/).

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
