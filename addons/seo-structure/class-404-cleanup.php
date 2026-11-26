<?php
/**
 * Pure logic for the 404 clean-up (no WordPress calls — testable with plain `php`).
 *
 * An error page must not claim a canonical address (AIOSEO invents /strona-nie-zostala-znaleziona/),
 * language alternates or structured data about a page that does not exist.
 */

defined('ABSPATH') || exit;

class Starter_404_Cleanup {

    public static function clean($html) {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        $patterns = [
            // <link rel="canonical" ...>
            '~<link\b[^>]*\brel=(["\'])canonical\1[^>]*>\s*~i',
            // <link rel="alternate" hreflang="..." ...>
            '~<link\b[^>]*\brel=(["\'])alternate\1[^>]*\bhreflang=[^>]*>\s*~i',
            '~<link\b[^>]*\bhreflang=[^>]*\brel=(["\'])alternate\1[^>]*>\s*~i',
            // <meta property="og:url" ...> and twitter:url
            '~<meta\b[^>]*\b(?:property|name)=(["\'])(?:og|twitter):url\1[^>]*>\s*~i',
            // AIOSEO structured data (WebPage with the invented URL)
            '~<script\b[^>]*\bclass=(["\'])aioseo-schema\1[^>]*>.*?</script>\s*~is',
        ];
        $out = $html;
        foreach ($patterns as $p) {
            $r = preg_replace($p, '', $out);
            if ($r !== null) {
                $out = $r;
            }
        }
        return $out;
    }
}
