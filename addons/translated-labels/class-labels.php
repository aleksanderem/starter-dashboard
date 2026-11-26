<?php
/**
 * Pure logic for the Translated Labels addon (no WordPress calls — testable with plain `php`).
 *
 * Some Elementor templates contain English literals ("Job title", "Work mode:") that were never
 * translated. When the visitor's language has a translation here and the widget still shows the
 * English text, the text is swapped. Already translated widgets are left alone.
 */

defined('ABSPATH') || exit;

class Starter_Translated_Labels {

    /** widget id => English literal => language => text */
    public static function map() {
        return [
            // Careers: job offer tile (loop item)
            '413546a' => ['Job title' => ['pl' => 'Stanowisko', 'cs' => 'Pozice', 'sk' => 'Pozícia', 'hu' => 'Pozíció']],
            'ac90ed1' => ['Location:' => ['pl' => 'Lokalizacja:', 'cs' => 'Lokalita:', 'sk' => 'Lokalita:', 'hu' => 'Helyszín:']],
            '295619a' => ['Work mode:' => ['pl' => 'Tryb pracy:', 'cs' => 'Způsob práce:', 'sk' => 'Spôsob práce:', 'hu' => 'Munkavégzés módja:']],
        ];
    }

    /**
     * @param string $html       Rendered widget HTML.
     * @param string $widget_id  Elementor widget id.
     * @param string $lang       Two-letter language code ('pl', 'en', ...).
     */
    public static function translate($html, $widget_id, $lang, array $map = null) {
        $map = $map === null ? self::map() : $map;
        if (!is_string($html) || $html === '' || !isset($map[$widget_id]) || $lang === 'en') {
            return $html;
        }
        foreach ($map[$widget_id] as $english => $texts) {
            if (!isset($texts[$lang])) {
                continue;
            }
            // Only the element text: ">Job title<" (whole text node), never attributes or longer text.
            $html = str_replace('>' . $english . '<', '>' . htmlspecialchars($texts[$lang], ENT_QUOTES, 'UTF-8') . '<', $html);
        }
        return $html;
    }
}
