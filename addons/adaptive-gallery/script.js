/**
 * Adaptive Gallery
 * Carousel that adjusts visible slides based on image orientation
 */
(function($) {
    'use strict';

    window.initAdaptiveGallery = function(containerId, settings) {
        var $wrapper = $('#' + containerId);
        if (!$wrapper.length) return;

        var $container = $wrapper.find('.adaptive-gallery-container');
        var $track = $wrapper.find('.adaptive-gallery-track');
        var $slides = $wrapper.find('.adaptive-gallery-slide');
        var $prevBtn = $wrapper.find('.adaptive-gallery-prev');
        var $nextBtn = $wrapper.find('.adaptive-gallery-next');
        var $dotsContainer = $wrapper.find('.adaptive-gallery-dots');

        var currentIndex = 0;
        var isAnimating = false;
        var autoplayTimer = null;
        var totalSlides = $slides.length;

        if (totalSlides === 0) return;

        // Get slides count based on current slide orientation
        function getSlidesToShow() {
            var $currentSlide = $slides.eq(currentIndex);
            var orientation = $currentSlide.data('orientation') || 'landscape';

            switch (orientation) {
                case 'portrait':
                    return settings.slidesPortrait;
                case 'square':
                    return settings.slidesSquare;
                default:
                    return settings.slidesLandscape;
            }
        }

        // Calculate slide widths based on orientation
        function calculateLayout() {
            var containerWidth = $container.width();
            var containerHeight = $container.height();
            var spacing = settings.spacing;

            // Check if mobile viewport
            var isMobile = window.innerWidth <= 768;

            $slides.each(function(index) {
                var $slide = $(this);
                var $img = $slide.find('img');
                var orientation = $slide.data('orientation');

                if (isMobile) {
                    // Mobile: full width, auto height
                    $img.css({
                        'width': '100%',
                        'height': 'auto',
                        'max-width': '100%',
                        'object-fit': 'contain'
                    });

                    $slide.css({
                        'height': 'auto',
                        'margin-right': spacing + 'px',
                        'flex-shrink': '0'
                    });
                } else {
                    // Desktop: fill height, width auto
                    $img.css({
                        'height': '100%',
                        'width': 'auto',
                        'max-width': 'none',
                        'object-fit': 'contain'
                    });

                    $slide.css({
                        'height': containerHeight + 'px',
                        'margin-right': spacing + 'px',
                        'flex-shrink': '0'
                    });
                }
            });
        }

        // Update track position
        function updatePosition(animate) {
            if (isAnimating) return;

            var offset = 0;
            for (var i = 0; i < currentIndex; i++) {
                offset += $slides.eq(i).outerWidth(true);
            }

            if (animate) {
                isAnimating = true;
                $track.css({
                    'transition': 'transform 0.5s ease',
                    'transform': 'translateX(-' + offset + 'px)'
                });
                setTimeout(function() {
                    isAnimating = false;
                }, 500);
            } else {
                $track.css({
                    'transition': 'none',
                    'transform': 'translateX(-' + offset + 'px)'
                });
            }

            updateDots();
            updateArrows();
        }

        // Navigate to slide
        function goToSlide(index, animate) {
            if (index < 0) {
                index = settings.infinite ? totalSlides - 1 : 0;
            } else if (index >= totalSlides) {
                index = settings.infinite ? 0 : totalSlides - 1;
            }

            currentIndex = index;
            updatePosition(animate !== false);
        }

        // Next slide
        function nextSlide() {
            goToSlide(currentIndex + 1);
        }

        // Previous slide
        function prevSlide() {
            goToSlide(currentIndex - 1);
        }

        // Update navigation dots
        function updateDots() {
            if (!settings.showDots) return;

            $dotsContainer.empty();
            for (var i = 0; i < totalSlides; i++) {
                var $dot = $('<button class="adaptive-gallery-dot" aria-label="Go to slide ' + (i + 1) + '"></button>');
                if (i === currentIndex) {
                    $dot.addClass('active');
                }
                (function(index) {
                    $dot.on('click', function() {
                        goToSlide(index);
                        resetAutoplay();
                    });
                })(i);
                $dotsContainer.append($dot);
            }
        }

        // Update arrows state
        function updateArrows() {
            if (!settings.showArrows || settings.infinite) return;

            $prevBtn.prop('disabled', currentIndex === 0);
            $nextBtn.prop('disabled', currentIndex >= totalSlides - 1);
        }

        // Autoplay
        function startAutoplay() {
            if (!settings.autoplay) return;

            stopAutoplay();
            autoplayTimer = setInterval(function() {
                nextSlide();
            }, settings.autoplaySpeed);
        }

        function stopAutoplay() {
            if (autoplayTimer) {
                clearInterval(autoplayTimer);
                autoplayTimer = null;
            }
        }

        function resetAutoplay() {
            stopAutoplay();
            startAutoplay();
        }

        // Touch/swipe support
        var touchStartX = 0;
        var touchEndX = 0;

        $container.on('touchstart', function(e) {
            touchStartX = e.originalEvent.touches[0].clientX;
        });

        $container.on('touchmove', function(e) {
            touchEndX = e.originalEvent.touches[0].clientX;
        });

        $container.on('touchend', function() {
            var diff = touchStartX - touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) {
                    nextSlide();
                } else {
                    prevSlide();
                }
                resetAutoplay();
            }
        });

        // Event bindings
        $prevBtn.on('click', function() {
            prevSlide();
            resetAutoplay();
        });

        $nextBtn.on('click', function() {
            nextSlide();
            resetAutoplay();
        });

        // Pause on hover
        $wrapper.on('mouseenter', function() {
            stopAutoplay();
        });

        $wrapper.on('mouseleave', function() {
            startAutoplay();
        });

        // Resize handler
        var resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                calculateLayout();
                updatePosition(false);
            }, 100);
        });

        // Initialize
        function init() {
            $track.css({
                'display': 'flex',
                'align-items': 'center'
            });

            calculateLayout();
            updatePosition(false);
            updateDots();
            startAutoplay();

            // Recalculate after images load
            $slides.find('img').on('load', function() {
                calculateLayout();
                updatePosition(false);
            });
        }

        // Wait for images to have dimensions
        setTimeout(init, 100);
    };

    // Auto-init on Elementor frontend
    $(window).on('elementor/frontend/init', function() {
        var widgetName = (window.adaptiveGalleryConfig && window.adaptiveGalleryConfig.widgetName)
            ? window.adaptiveGalleryConfig.widgetName
            : 'buspatrol_adaptive_gallery';

        elementorFrontend.hooks.addAction('frontend/element_ready/' + widgetName + '.default', function($scope) {
            var $gallery = $scope.find('.adaptive-gallery-wrapper');
            if ($gallery.length) {
                // Re-init is handled by inline script
            }
        });
    });

})(jQuery);
