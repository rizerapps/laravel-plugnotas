<?php

namespace Rizer\PlugNotas\Tests\Http;

use Illuminate\Support\Facades\Route;
use Rizer\PlugNotas\Tests\TestCase;

/**
 * Atencao: postJson($uri, $data, $headers) trata o 3o argumento como header e
 * ignora REMOTE_ADDR. Para simular o IP de origem use withServerVariables().
 *
 * IPs de documentacao (RFC 5737), nenhum IP real.
 */
class VerifyPlugNotasIpTest extends TestCase
{
    private const IP_AUTORIZADO = '192.0.2.10';

    private const IP_AUTORIZADO_2 = '192.0.2.20';

    private const IP_INTRUSO = '198.51.100.7';

    private const IP_PROXY = '203.0.113.1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['plugnotas.webhook_ips' => [self::IP_AUTORIZADO, self::IP_AUTORIZADO_2]]);

        Route::post('/webhook-teste', fn () => response()->json(['received' => true]))
            ->middleware('plugnotas.ip');
    }

    private function postDoIp(string $ip, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/webhook-teste', ['idIntegracao' => 'ref1'], $headers);
    }

    public function test_ip_fora_da_lista_e_recusado(): void
    {
        $this->postDoIp(self::IP_INTRUSO)
            ->assertStatus(403)
            ->assertJson(['error' => 'Forbidden']);
    }

    public function test_ips_da_lista_sao_aceitos(): void
    {
        $this->postDoIp(self::IP_AUTORIZADO)->assertOk();
        $this->postDoIp(self::IP_AUTORIZADO_2)->assertOk();
    }

    public function test_lista_vazia_desativa_a_verificacao(): void
    {
        config(['plugnotas.webhook_ips' => []]);

        $this->postDoIp(self::IP_INTRUSO)->assertOk();
    }

    /**
     * Fora do Cloudflare o header pode ser forjado: por padrao ele e ignorado.
     */
    public function test_por_padrao_cf_connecting_ip_e_ignorado(): void
    {
        $this->postDoIp(self::IP_INTRUSO, ['CF-Connecting-IP' => self::IP_AUTORIZADO])
            ->assertStatus(403);
    }

    public function test_confiando_no_cloudflare_usa_cf_connecting_ip(): void
    {
        config(['plugnotas.webhook_trust_cloudflare' => true]);

        $this->postDoIp(self::IP_PROXY, ['CF-Connecting-IP' => self::IP_AUTORIZADO])
            ->assertOk();
    }

    public function test_confiando_no_cloudflare_o_header_nao_permite_bypass(): void
    {
        config(['plugnotas.webhook_trust_cloudflare' => true]);

        $this->postDoIp(self::IP_AUTORIZADO, ['CF-Connecting-IP' => self::IP_INTRUSO])
            ->assertStatus(403);
    }

    public function test_confiando_no_cloudflare_sem_header_usa_o_ip_da_conexao(): void
    {
        config(['plugnotas.webhook_trust_cloudflare' => true]);

        $this->postDoIp(self::IP_AUTORIZADO)->assertOk();
    }
}
