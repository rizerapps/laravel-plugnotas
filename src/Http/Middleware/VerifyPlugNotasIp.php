<?php

namespace Rizer\PlugNotas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe os webhooks do PlugNotas aos IPs de origem configurados em
 * `plugnotas.webhook_ips`.
 *
 * Por que IP e nao assinatura: o PlugNotas nao assina os webhooks — nao ha
 * HMAC nem secret na origem. A whitelist de IP e a protecao recomendada.
 *
 * Lista vazia desativa a verificacao (util em desenvolvimento local).
 */
class VerifyPlugNotasIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('plugnotas.webhook_ips', []);

        if (empty($allowedIps)) {
            return $next($request);
        }

        if (! in_array($this->resolveClientIp($request), $allowedIps, true)) {
            // Sem IP nem payload no log: e o primeiro lugar onde um scanner
            // apareceria, e deve poder ser lido sem expor conteudo fiscal.
            Log::warning('Webhook PlugNotas recusado: IP de origem nao autorizado', [
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    /**
     * IP real do cliente.
     *
     * Atras do Cloudflare, $request->ip() devolve o IP do proxy e o IP de
     * origem vem em CF-Connecting-IP. O header so e considerado com
     * `plugnotas.webhook_trust_cloudflare` ligado: fora do Cloudflare qualquer
     * um poderia envia-lo com um IP autorizado.
     */
    private function resolveClientIp(Request $request): ?string
    {
        if (config('plugnotas.webhook_trust_cloudflare', false)) {
            $cloudflareIp = $request->header('CF-Connecting-IP');

            if ($cloudflareIp !== null && $cloudflareIp !== '') {
                return $cloudflareIp;
            }
        }

        return $request->ip();
    }
}
