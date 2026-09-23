# Laravel PlugNotas

Cliente para a API PlugNotas (TecnoSpeed) de emissão de NF-e e NFS-e, para projetos Laravel.

## O que este pacote é

- **Transporte e formato**: cliente HTTP da API PlugNotas (NF-e, NFS-e, certificados, empresas e webhooks),
  DTOs de request/response, exceptions e códigos IBGE.
- **Integração com o Laravel**: config publicável, bind das interfaces, middleware de IP do webhook e o comando
  `plugnotas:install`.
- Sem Model, sem migration, sem `illuminate/database`. Cada projeto implementa o mapeamento dos seus dados
  (pedido, fatura etc.) para o payload do pacote e decide o ambiente de cada emissor.

## O que este pacote NÃO é

- **Não carrega alíquotas nem regras fiscais.** Vigências, CFOPs e alíquotas ficam no projeto: são dados com
  vigência legal, não código de transporte.
- **Não decide o ambiente.** Sandbox ou produção é escolha do cliente, guardada no banco pelo projeto. O padrão
  do pacote é sempre `sandbox`.

## Requisitos

- PHP ^8.1
- Laravel 10, 11, 12 ou 13

## Instalação rápida

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/rizerapps/laravel-plugnotas.git" }
]
```

```bash
composer require rizerapps/laravel-plugnotas:^1.4
php artisan plugnotas:install
```

O passo a passo completo está em **[docs/INSTALACAO.md](docs/INSTALACAO.md)**: credenciais por emissor, webhook,
testes, validação em sandbox e problemas conhecidos.

## Uso mínimo

```php
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;

$client = new PlugNotasClient(
    PlugNotasCredentials::fromConfig(config('plugnotas'), $emissor->environment, $emissor->cnpj)
);

$resultado = $client->createNfse($payload);
```

## Testes

```bash
composer install
vendor/bin/phpunit
```

## Changelog

Ver [CHANGELOG.md](CHANGELOG.md).
