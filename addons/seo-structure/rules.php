<?php
/**
 * Heading rules for mwtsolutions.eu, from MWT's heading table (Jakub Wieczerza, 2026-11-25):
 * one H1 per page, H2 for the listed sections, H3 for product feature names, everything else
 * ordinary text. Widget ids are the same in every language (WPML copies them), so these rules
 * cover PL/EN/CS/SK/HU. Widgets that are not listed are never touched, so headings set on NEW
 * widgets keep working as the editor chose.
 *
 * Override in code:  add_filter('mwts_seo_structure_rules', function ($rules) { ... return $rules; });
 */

defined('ABSPATH') || exit;

$p = 'p';

return [
    // Templates whose headings are always plain text (menu, footer, popups, listing tiles).
    'plain_doc_types' => ['uicore-tb', 'popup', 'loop-item', 'jet-listing-items'],
    'plain_docs'      => [1224, 103, 203940, 203657, 201976, 107932, 206986, 203184, 109152, 205321],

    // Pages that have no visible title: add a visually hidden H1 with the page title (original post ids).
    'h1_pages'        => [107803],

    // Promotions that need a stylesheet to keep the look: [from, to].
    'promotions'      => [['h3', 'h2'], ['h4', 'h3'], ['h2', 'h1']],

    'widgets' => [
        // --- Category archive (ITSM, UEM, ...): H1 term name; H2 offer / support / services ---
        '989afe6' => 'h2', 'a0eeab0' => 'h2',
        '2aa6542' => $p, 'bdab4d4' => $p, '66570bd' => $p, '8c6f679' => $p, '4ab3ef6' => $p, 'c8d85c8' => $p,
        '74130a4' => $p, 'e780d7c' => $p, '1110739' => $p, 'ea83ef5' => $p, '0a8715e' => $p, '6f724b1' => $p,
        '54c350a' => $p, 'c0532bb' => $p, 'df78a2f' => $p, 'cff9983' => $p,
        '3e8f28e' => $p, '0142dfb' => $p, 'de40284' => $p, '17286bd' => $p, '1001c0e' => $p,

        // --- Product page: H1 product; H2 main sections; H3 feature names ---
        '1622e17' => 'h2', '94fa3a5' => 'h2', '3d3022f' => 'h2', '3107fa2' => 'h2', 'c9bd25d' => 'h3',
        'e9a0419' => $p, '861c42d' => $p, 'e8b05d0' => $p, '42ef114' => $p, '564a96f' => $p, 'f29a09d' => $p,
        '9b967af' => $p,

        // --- Partners: H1; H2 why / list / how to become a partner ---
        'fa5ff2e' => 'h2', '1e01d71' => 'h2',
        '2f2d4c4' => $p, 'f602df6' => $p, 'e3c9895' => $p, 'd3838e4' => $p, '3d8e18d' => $p, '172cc28' => $p,
        'b06ae65' => $p, '82e7cd3' => $p, '184a013' => $p, 'ea42909' => $p, '039bbad' => $p, '921cfd3' => $p,
        '0ba5c1e' => $p, '620ef14' => $p, '71d053e' => $p, '22978bd' => $p, 'a15310c' => $p, 'b0d969f' => $p,
        '2c06fa9' => $p, 'd190067' => $p, '6553c9f' => $p,

        // --- Knowledge base and events: H1 only ---
        '09d8ae7' => 'h1',
        '9009ae7' => 'h1',
        'fe18f08' => $p, 'e4dfdc0' => $p, '3e06699' => $p, '92a8696' => $p, '71fb8e4' => $p,

        // --- Careers: H1; H2 why join / what sets us apart / current offers ---
        '468689c' => $p, 'e94080e' => $p,
        'c2642b4' => $p, 'afccb29' => $p, '7fc5db8' => $p, 'acd4139' => $p, 'd63906f' => $p, '0afc787' => $p,
        '6723562' => $p, 'b0b77dc' => $p, 'c00edcc' => $p, '14859a0' => $p, '3e21496' => $p, 'af9208c' => $p,
        '854be1c' => $p, '72fdc3b' => $p, '7370ded' => $p, '0b0d7b8' => $p, 'b51fd0a' => $p, 'f3357e7' => $p,
        '5c5a95b' => $p, '54117a2' => $p, '8b1cd5e' => $p, '818a09f' => $p, 'e14eaf9' => $p, 'ce2ab22' => $p,
        'f5595df' => $p, '2411449' => $p, '034f2e3' => $p, '382c545' => $p, '00e2e9b' => $p, '4bfa673' => $p,
        '2b10bac' => $p, 'ffca2f3' => $p, 'b231b3f' => $p,

        // --- About us: H1 (hidden, see h1_pages); H2 who we are / team / branches ---
        '02efe35' => 'h2',
        'ab6d807' => $p, '1820f1f' => $p, 'ebc92af' => $p, 'ea83ddd' => $p, 'bff4658' => $p, 'c50aacc' => $p,
        '964ea54' => $p, '22c59a2' => $p, '412e24d' => $p, '2ec0f90' => $p, 'a8fa880' => $p, 'e64d583' => $p,
        '3c3a117' => $p, '77c7c71' => $p, '4dbcc8b' => $p,

        // --- Contact: H1; H2 branches / get in touch ---
        '8a97a11' => 'h2',
        '056e259' => $p, '158fd8c' => $p, 'bd94da1' => $p, '050dbfa' => $p, '446436f' => $p, 'e6b2838' => $p,
        '1cd55c8' => $p, '0f8d41f' => $p,

        // --- Fallback for tiles, menu and popup (also covered by plain_doc_types / plain_docs) ---
        '2d75236' => $p, 'e8678b5' => $p,
        'c59dd9f' => $p, 'f161886' => $p, 'b0f4efb' => $p, '3431808' => $p, '568f05a' => $p, 'f7fa97d' => $p,
        '4e65ed6' => $p, '06dd1a6' => $p, 'dfafbd1' => $p, '54bf992' => $p, '46a3e84' => $p, '36bc290' => $p,
        '6f3d91e' => $p, 'a23a6e4' => $p, '4f9f2df' => $p, '6c6db0c' => $p, 'f801e62' => $p, 'ac883c0' => $p,
        '2dc0b79' => $p, '1c7d78b' => $p, 'f2d85ec' => $p, '559e584' => $p, '31fc985' => $p,
    ],
];
