/**
 * Gallery Grid Widget Handler
 */
(function($) {
    'use strict';

    class GalleryGridHandler extends elementorModules.frontend.handlers.Base {
        getDefaultSettings() {
            return {
                selectors: {
                    mainWrap: '.nmj-pg-main-wrap',
                    mainLink: '.nmj-pg-main-link',
                    mainImg: '.nmj-pg-main-img',
                    thumbnailsWrap: '.nmj-pg-thumbnails-wrap',
                    thumbnail: '.nmj-pg-thumb',
                },
                classes: {
                    activeThumb: 'nmj-pg-thumb-active'
                }
            };
        }

        getDefaultElements() {
            const selectors = this.getSettings('selectors');
            return {
                $mainWrap: this.$element.find(selectors.mainWrap),
                $thumbnails: this.$element.find(selectors.thumbnail),
            };
        }

        bindEvents() {
            this.elements.$thumbnails.on('click keydown', this.onThumbnailClick.bind(this));
        }

        onThumbnailClick(event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            if (event.type === 'keydown') {
                event.preventDefault();
            }

            const $thumb = $(event.currentTarget);
            const classes = this.getSettings('classes');
            
            if ($thumb.hasClass(classes.activeThumb)) {
                return;
            }
            
            this.elements.$thumbnails.removeClass(classes.activeThumb);
            $thumb.addClass(classes.activeThumb);
            
            const index = $thumb.index();
            const $mainLinks = this.elements.$mainWrap.find(this.getSettings('selectors').mainLink);
            
            $mainLinks.hide().removeClass('nmj-main-active');
            $mainLinks.eq(index).fadeIn(250).addClass('nmj-main-active');
        }
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.elementsHandler.attachHandler('nmj_gallery_grid', GalleryGridHandler);
    });

})(jQuery);
