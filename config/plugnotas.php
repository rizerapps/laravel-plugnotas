<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chaves de API
    |--------------------------------------------------------------------------
    |
    | Uma chave por ambiente. A chave usada e sempre a do ambiente ativo, o que
    | evita transmitir com a chave de sandbox em producao (ou vice-versa).
    |
    */

    'api_keys' => [
        'sandbox' => env('PLUGNOTAS_API_KEY_SANDBOX'),
        'production' => env('PLUGNOTAS_API_KEY_PRODUCTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambiente padrao
    |--------------------------------------------------------------------------
    |
    | Usado apenas quando o projeto nao informa o ambiente ao montar as
    | credenciais. O ambiente de emissao deve vir da configuracao do emissor
    | (banco de dados), escolhida pelo cliente — por isso nao ha variavel de
    | ambiente aqui. Valores aceitos: sandbox, production.
    |
    */

    'default_environment' => 'sandbox',

    /*
    |--------------------------------------------------------------------------
    | Transporte HTTP
    |--------------------------------------------------------------------------
    |
    | timeout em segundos; retry_delay em milissegundos. O retry so acontece
    | em respostas 429 e 5xx.
    |
    */

    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 1000,

    /*
    |--------------------------------------------------------------------------
    | IPs de origem do webhook (opcional)
    |--------------------------------------------------------------------------
    |
    | O PlugNotas nao assina os webhooks; a protecao recomendada e aceitar
    | apenas os IPs de origem dele (middleware `plugnotas.ip`). Lista separada
    | por virgula. Vazio desativa a verificacao (util em desenvolvimento local).
    | Consulte a documentacao do PlugNotas para a lista oficial de IPs.
    |
    */

    'webhook_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLUGNOTAS_WEBHOOK_IPS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Confiar no header CF-Connecting-IP
    |--------------------------------------------------------------------------
    |
    | Ligue somente se a aplicacao estiver atras do Cloudflare (ex.: DigitalOcean
    | App Platform). Fora dele o header pode ser forjado por qualquer cliente.
    |
    */

    'webhook_trust_cloudflare' => false,

];
