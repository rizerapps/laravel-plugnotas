# Guia de instalação e configuração

Passo a passo para usar o `rizerapps/laravel-plugnotas` num projeto Laravel (10 a 13) e emitir NF-e
e NFS-e pelo PlugNotas (TecnoSpeed).

**Divisão de responsabilidades**

| Pacote | Projeto |
|---|---|
| Transporte HTTP, autenticação, retry e endpoints | Models, migrations, telas |
| DTOs de payload (`NFeRequestDTO`, `NfseRequestDTO`) | Mapper dos seus dados para o DTO |
| Credenciais a partir da config | De onde vem o ambiente de cada emissor (banco) |
| Middleware de IP do webhook | Rota e processamento do webhook |
| — | Alíquotas e regras fiscais |

---

## 0. Antes de começar: migração ou descarte?

Projetos derivados de um template podem ter uma integração PlugNotas antiga que **nunca emitiu uma
nota**. Ela fica parada e desatualiza em silêncio: a API muda e nada exercita aquele código. Então,
antes de planejar qualquer coisa, confira:

```bash
git log --oneline -- app/Domains/Tax/Clients/   # ou onde estiver o client antigo
```
```sql
SELECT COUNT(*) AS total, SUM(provider_ref IS NOT NULL) AS transmitidas FROM invoices;
```

| Resultado | Estratégia |
|---|---|
| Só o commit do template e 0 notas | **Descartar** o código antigo e adotar o pacote. Não há comportamento de produção a preservar. |
| Histórico próprio e notas emitidas | **Migrar com cuidado**: testes do payload atual antes de trocar, comparação antes e depois. |

---

## 1. Instalação

O repositório é público, então não precisa de token. Declare-o no `composer.json` do projeto:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/rizerapps/laravel-plugnotas.git" }
]
```

```bash
composer require rizerapps/laravel-plugnotas:^1.4
php artisan plugnotas:install
```

O Laravel registra o pacote sozinho (auto-discovery). O comando `plugnotas:install`:

- acrescenta ao `.env` as variáveis que ainda não existem, **sem sobrescrever nenhuma**;
- pede a chave de **sandbox** (digitação oculta, nunca exibida) e deixa a de **produção vazia**;
- acrescenta as mesmas variáveis, sem valores, ao `.env.example`;
- testa a conexão com o sandbox (`--no-check` pula o teste).

Para customizar a config, use `php artisan plugnotas:install --publish-config` ou
`php artisan vendor:publish --tag=plugnotas-config`. Publicar é opcional.

---

## 2. Configuração

### `.env`

```dotenv
PLUGNOTAS_API_KEY_SANDBOX=        # preenchida pelo install
PLUGNOTAS_API_KEY_PRODUCTION=     # vazia até a emissão real ser liberada
PLUGNOTAS_WEBHOOK_IPS=            # opcional; ver seção 5
```

Não existe variável para o ambiente: **o ambiente (sandbox/produção) é escolha do cliente e fica no
banco**, na configuração de cada emissor. Assim ele muda sem deploy, pode ser diferente entre empresas,
e esquecer de configurar nunca leva a nota para produção. O pacote usa `sandbox` como padrão.

### `config/plugnotas.php`

| Chave | Padrão | Para que serve |
|---|---|---|
| `api_keys.sandbox` / `api_keys.production` | `.env` | Chave usada conforme o ambiente ativo |
| `default_environment` | `sandbox` | Ambiente quando o projeto não informa um |
| `timeout` / `retry_times` / `retry_delay` | 30 s / 3 / 1000 ms | Transporte (retry só em 429 e 5xx) |
| `webhook_ips` | `[]` | IPs aceitos no webhook; vazio desliga a verificação |
| `webhook_trust_cloudflare` | `false` | Ler o IP real em `CF-Connecting-IP` |

**Projeto que já usa outros nomes de variável?** Publique a config e aponte para eles (por exemplo,
`'sandbox' => env('MEU_NOME_ANTIGO')`). Assim nada muda no servidor.

> Em produção, com `php artisan config:cache`, o Laravel não lê mais o `.env` fora dos arquivos de
> config. Por isso o pacote lê `config('plugnotas.*')`, nunca `env()`.

---

## 3. Credenciais

### Uma empresa só

O container já entrega o client em sandbox, pronto para uso:

```php
use Rizer\PlugNotas\Client\NFeClientInterface;
use Rizer\PlugNotas\Client\NfseClientInterface;

public function __construct(private NFeClientInterface $nfe, private NfseClientInterface $nfse) {}
```

### Multiempresa (ambiente vindo do emissor)

Monte as credenciais com o ambiente salvo no banco e o CNPJ do emissor:

```php
use Rizer\PlugNotas\Client\PlugNotasClient;
use Rizer\PlugNotas\Credentials\PlugNotasCredentials;

$credentials = PlugNotasCredentials::fromConfig(
    config('plugnotas'),
    $emissor->environment,   // 'sandbox' | 'production' — vazio ou desconhecido vira sandbox
    $emissor->cnpj,
);

$client = new PlugNotasClient($credentials);
```

- Produção sem chave gera credencial inválida (`isValid() === false`). **Ela nunca cai para a chave de sandbox.**
- **Propague o CNPJ.** `queryNfseByIntegration()` exige o CNPJ e lança `RuntimeException` sem ele.
- Para registrar seu próprio client no container, faça o `bind` no seu `AppServiceProvider`. O pacote usa
  `bindIf`, então o bind do projeto prevalece.

---

## 4. O que o projeto implementa

1. **Mapper** dos seus dados (pedido, fatura) para `NFeRequestDTO` / `NfseRequestDTO`. É onde entra a regra
   fiscal do projeto, e é normal ele ser grande.
2. **Colunas mínimas** na tabela de notas:
   - `provider_ref`: o `idIntegracao` que você gera e envia;
   - **`provider_document_id`**: o `id` que o PlugNotas devolve em `documents[0].id` na emissão. **Guarde
     esse valor.** Consulta, download e cancelamento funcionam com ele. Com o `idIntegracao`, a API responde 404
     e a nota fica presa em "processando".
3. **Flag de habilitação por emissor** (NF-e e NFS-e), para liberar a emissão de forma controlada.
4. **Ação de "consultar status"** na tela da nota. Sem webhook alcançável (ambiente local, por exemplo), é o
   único jeito de uma nota sair de "processando".

---

## 5. Webhook

O PlugNotas **não assina** os webhooks: não há HMAC nem secret. A proteção é aceitar só os IPs de origem
dele. Os métodos `validateWebhookSignature*()` estão obsoletos e não protegem nada.

```php
// routes/api.php
Route::post('/webhooks/plugnotas', [PlugNotasWebhookController::class, 'handle'])
    ->middleware(['throttle:webhooks', 'plugnotas.ip']);
```

- **`PLUGNOTAS_WEBHOOK_IPS`**: lista separada por vírgula com os IPs oficiais (consulte a documentação do
  PlugNotas). Vazio desliga a verificação, o que é útil em desenvolvimento local.
- **Atrás do Cloudflare** (inclui DigitalOcean App Platform): publique a config e ligue
  `webhook_trust_cloudflare`. Sem isso, o IP visto é o do proxy e todo webhook é recusado. **Fora do
  Cloudflare, mantenha desligado**, porque o header pode ser forjado.

Regras do controller:

- **Ping de verificação**: o PlugNotas manda um POST sem `idIntegracao` ao cadastrar a URL. Responda **200**,
  senão a verificação falha no painel.
- **Erro no seu processamento também responde 200**, com log do erro. Um status diferente faz o PlugNotas
  reenviar.
- NFS-e traz `idIntegracao`. Em NF-e, localize a nota também pelo `id` do PlugNotas (`provider_document_id`).
- Cadastro da URL: `$client->createWebhook($url)`, ou `createCompanyWebhook($cnpj, $url)` para um webhook por empresa.

---

## 6. Rate limiting sugerido

Os limites derivam da API do PlugNotas, não do projeto:

```php
// AppServiceProvider::boot()
RateLimiter::for('nfe_processing', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->company_id));
RateLimiter::for('plugnotas_api',  fn (Request $r) => Limit::perMinute(60)->by($r->user()?->company_id));
RateLimiter::for('webhooks',       fn (Request $r) => Limit::perMinute(100)->by($r->ip()));
```

---

## 7. Testes no projeto

- **Nunca** chame a API real em teste. Use `Http::fake()` **restrito ao host do PlugNotas**
  (`'api.sandbox.plugnotas.com.br/*'`). Um `Http::fake('*')` intercepta outras integrações do projeto
  (consulta de CEP/IBGE em observers, por exemplo).
- Para simular o IP de origem do webhook, use `withServerVariables(['REMOTE_ADDR' => ...])`. O terceiro
  argumento de `postJson()` é header, e o `REMOTE_ADDR` passado ali é ignorado sem aviso.
- Use IPs de documentação (192.0.2.x, 198.51.100.x) e CNPJs fictícios nos testes.

---

## 8. Validação em sandbox

Toda validação é feita **somente em sandbox**. Nunca emita nota de teste em produção.

1. Emissor com ambiente `sandbox`, certificado enviado e empresa cadastrada no PlugNotas.
2. Cliente com endereço real: a cidade precisa existir na tabela IBGE (`BrazilianIbgeCodes`).
3. Emitir NF-e → consultar status → baixar XML e DANFE → cancelar.
4. Emitir NFS-e → consultar → baixar PDF.

> O sandbox devolve XML, DANFE e PDF de **exemplo fixo**. Emitente, valores e datas não batem com o que foi
> enviado. Isso é normal. O que vale conferir no sandbox é o fluxo: status, número e protocolo.

---

## 9. Problemas conhecidos

| Sintoma | Causa | Solução |
|---|---|---|
| Nota presa em "processando", consulta retorna 404 "Não localizamos qualquer NFe" | Consulta feita pelo `idIntegracao` | Guardar e usar o `provider_document_id` (seção 4) |
| `RuntimeException` ao consultar NFS-e por integração | Credenciais sem CNPJ | Passar o CNPJ em `fromConfig(..., $cnpj)` |
| "Chave de API do PlugNotas não configurada para o ambiente production" | Emissor em produção sem `PLUGNOTAS_API_KEY_PRODUCTION` | Preencher a chave, ou manter o emissor em sandbox |
| Emissão rejeitada por município | Cidade inexistente ou digitada errado | Corrigir o endereço; o código IBGE é resolvido pelo nome e UF |
| Todo webhook recusado com 403 em produção | App atrás de proxy/Cloudflare | Ligar `webhook_trust_cloudflare` (seção 5) |
| Verificação da URL falha no painel do PlugNotas | Ping sem `idIntegracao` respondido com erro | Responder 200 ao ping |
