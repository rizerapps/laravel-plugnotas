# Laravel PlugNotas

Cliente para a API PlugNotas (TecnoSpeed) de emissão de NF-e, extraído de um ERP interno para reuso
em outros projetos Laravel do ecossistema Rizer.

## O que este pacote é

- **Transporte e formato**: cliente HTTP para a API PlugNotas, DTOs de request/response, exceptions,
  enums fiscais puros (CST/CSOSN/CFOP...) e o middleware de validação de webhook.
- Sem Model, sem migration, sem `illuminate/database` — cada projeto consumidor implementa seu
  próprio resolver de credenciais e seu próprio mapeamento de dados de negócio (Invoice, Pedido,
  etc.) para o payload deste pacote.

## O que este pacote NÃO é

- **Não carrega alíquotas nem regras fiscais.** Cronograma de vigência (ex.: transição IBS/CBS),
  CFOPs e alíquotas ficam na configuração de cada projeto consumidor — são dados com vigência legal,
  não código de transporte. Publicar uma nova versão deste pacote nunca deveria ser o caminho para
  corrigir uma alíquota.
- Não resolve credenciais por tenant/empresa — isso é responsabilidade do projeto consumidor
  (implementar o contrato de resolver de credenciais exposto pelo pacote).

## Requisitos

- PHP ^8.1
- illuminate/support ^10.0|^11.0|^12.0|^13.0
- illuminate/http ^10.0|^11.0|^12.0|^13.0

## Instalação

```bash
composer require rizerapps/laravel-plugnotas
```

## Origem

Extraído de um ERP interno do ecossistema Rizer para permitir reuso do cliente PlugNotas em
outros projetos Laravel.
