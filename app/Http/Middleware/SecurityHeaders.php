<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response: a content security policy bound to a per-request script nonce, no framing,
 * no content sniffing, a strict referrer policy, and HSTS outside local development.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! app()->environment(['local', 'testing']) && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = Vite::isRunningHot() ? $this->devServerSources() : ['http' => '', 'ws' => ''];

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$dev['http'],
            "style-src 'self'".$dev['http'],
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'".$dev['http'],
            "connect-src 'self'".$dev['http'].$dev['ws'],
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The Vite dev server needs to serve scripts, styles and fonts and open a websocket while developing.
     *
     * @return array{http: string, ws: string}
     */
    private function devServerSources(): array
    {
        $hot = trim((string) file_get_contents(public_path('hot')));
        $parts = parse_url($hot);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost').':'.($parts['port'] ?? 5173);

        return ['http' => ' '.$origin, 'ws' => ' '.str_replace('http', 'ws', $origin)];
    }
}
