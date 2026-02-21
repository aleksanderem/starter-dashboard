/**
 * Image Compare - Persistent Labels
 *
 * Wraps the HA Image Compare widget in a relative container
 * and places label divs there — outside overflow:hidden.
 */
(function ($) {
    function initPersistentLabels() {
        $('.ha-image-compare .twentytwenty-container').each(function () {
            var $container = $(this);
            var $widget = $container.closest('.ha-image-compare');

            // Skip if already processed
            if ($widget.parent('.ha-ic-wrapper').length) return;

            // Read label text from the original ::before pseudo-elements
            var $beforeLabel = $container.find('.twentytwenty-before-label');
            var $afterLabel = $container.find('.twentytwenty-after-label');

            if (!$beforeLabel.length || !$afterLabel.length) return;

            var beforeText = window.getComputedStyle($beforeLabel[0], '::before').content;
            var afterText = window.getComputedStyle($afterLabel[0], '::before').content;

            // Remove quotes from computed content value
            beforeText = beforeText.replace(/^["']|["']$/g, '');
            afterText = afterText.replace(/^["']|["']$/g, '');

            if (!beforeText || beforeText === 'none') return;

            // Clone computed styles from the original label pseudo-elements
            var originalStyles = window.getComputedStyle($beforeLabel[0], '::before');

            var $newBefore = $('<div class="ha-ic-label ha-ic-label--before"></div>').text(beforeText);
            var $newAfter = $('<div class="ha-ic-label ha-ic-label--after"></div>').text(afterText);

            // Copy relevant styles from the original labels
            var stylesToCopy = ['backgroundColor', 'color', 'fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'lineHeight', 'padding', 'borderRadius'];
            stylesToCopy.forEach(function (prop) {
                var val = originalStyles[prop];
                if (val) {
                    $newBefore.css(prop, val);
                    $newAfter.css(prop, val);
                }
            });

            // Wrap widget in a relative container and append labels there
            $widget.wrap('<div class="ha-ic-wrapper"></div>');
            var $wrapper = $widget.parent('.ha-ic-wrapper');
            $wrapper.append($newBefore).append($newAfter);
        });
    }

    // Run after twentytwenty initializes
    $(window).on('load', function () {
        setTimeout(initPersistentLabels, 500);
    });

    // Elementor frontend handler
    $(window).on('elementor/frontend/init', function () {
        if (window.elementorFrontend && elementorFrontend.hooks) {
            elementorFrontend.hooks.addAction('frontend/element_ready/ha-image-compare.default', function () {
                setTimeout(initPersistentLabels, 500);
            });
        }
    });
})(jQuery);
