<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale from Accept-Language.
 *
 * Coding Standards §8: stable machine-readable error codes, user-facing messages
 * localized by request language. This middleware handles the second half — the
 * codes are never touched, only the messages built from translation files.
 *
 * The header is untrusted input like any other (Coding Standards §5), so the
 * value is matched against a fixed list rather than passed to setLocale. A
 * locale that reached the framework unvalidated is a path fragment: App::setLocale
 * feeds it to the translation file loader.
 */
final class SetLocaleFromRequest
{
    /**
     * CLAUDE.md: every UI string in Arabic (default) and English, and only those.
     *
     * @var list<string>
     */
    private const SUPPORTED = ['ar', 'en'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        $response = $next($request);

        // Tell caches and clients which language they actually received. Without
        // it a shared cache can serve an Arabic response to an English request.
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language', false);

        return $response;
    }

    private function resolve(Request $request): string
    {
        // getPreferredLanguage does the quality-value negotiation, so
        // `ar-EG,ar;q=0.9,en;q=0.8` resolves to ar rather than being rejected
        // for not matching exactly.
        //
        // But when nothing matches it returns the FIRST entry of the supported
        // list rather than null — so `fr-FR`, and an unparseable header like a
        // path fragment, both came back as 'ar' purely because ar is listed
        // first. Found by asking the running endpoint, not by reading the
        // signature. The header has to be checked for a real match first.
        $accepted = array_map(
            static fn (string $tag): string => strtolower(substr($tag, 0, 2)),
            $request->getLanguages(),
        );

        foreach (self::SUPPORTED as $supported) {
            if (in_array($supported, $accepted, true)) {
                return $request->getPreferredLanguage(self::SUPPORTED) ?? $supported;
            }
        }

        // config() is mixed to static analysis. Narrowed rather than cast, so a
        // misconfigured app.locale surfaces here instead of somewhere later.
        $fallback = config('app.locale');

        return is_string($fallback) && in_array($fallback, self::SUPPORTED, true)
            ? $fallback
            : 'en';
    }
}
