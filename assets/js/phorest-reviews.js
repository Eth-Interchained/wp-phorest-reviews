/* Phorest Reviews — frontend interactivity.
 * Minimal: progressive enhancement for the filters form (URL-state already
 * works without JS). Adds a "show more" expansion on long review text on
 * the homepage strip. No external deps.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Expand truncated homepage reviews on click.
        document.querySelectorAll('.phorest-reviews-home .phorest-review__text').forEach(function (el) {
            var full = el.getAttribute('data-full');
            if (!full) {
                return;
            }
            el.style.cursor = 'pointer';
            el.setAttribute('title', 'Click to expand');
            el.addEventListener('click', function () {
                el.textContent = full;
                el.removeAttribute('title');
                el.style.cursor = 'text';
            });
        });

        // Filter form: update the URL without a full reload so back-button works.
        var form = document.querySelector('.phorest-reviews-page__filters');
        if (form) {
            form.addEventListener('submit', function (e) {
                // Let the native GET happen — server-side handles it.
                return true;
            });
        }
    });
})();
