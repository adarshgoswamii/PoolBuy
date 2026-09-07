/**
 * PoolBuy storefront behaviour.
 *
 * Everything here is progressive enhancement: the pages are fully readable and
 * usable with JavaScript disabled. The `pb-js` class is only added once this file
 * runs, so CSS that hides content before revealing it can never strand a
 * no-script visitor with an invisible page.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('pb-js');

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * Reveal elements as they scroll into view.
     */
    function initReveal() {
        var targets = document.querySelectorAll('.pb-reveal');

        if (!targets.length) {
            return;
        }

        // Without IntersectionObserver, or when the visitor prefers reduced
        // motion, show everything immediately rather than animating.
        if (reduceMotion || typeof window.IntersectionObserver !== 'function') {
            Array.prototype.forEach.call(targets, function (el) {
                el.classList.add('pb-reveal--visible');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('pb-reveal--visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08 });

        Array.prototype.forEach.call(targets, function (el) {
            observer.observe(el);
        });
    }

    /**
     * Live countdown for pool deadlines.
     *
     * Each element carries data-pb-countdown as a unix timestamp in seconds. The
     * server renders a correct value first, so this only keeps it ticking.
     */
    function initCountdowns() {
        var nodes = document.querySelectorAll('[data-pb-countdown]');

        if (!nodes.length) {
            return;
        }

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function tick() {
            var now = Math.floor(Date.now() / 1000);

            Array.prototype.forEach.call(nodes, function (node) {
                var end = parseInt(node.getAttribute('data-pb-countdown'), 10);

                if (isNaN(end)) {
                    return;
                }

                var left = end - now;

                if (left <= 0) {
                    node.textContent = node.getAttribute('data-pb-ended') || 'Ended';
                    return;
                }

                var days = Math.floor(left / 86400);
                var hours = Math.floor((left % 86400) / 3600);
                var minutes = Math.floor((left % 3600) / 60);
                var seconds = left % 60;

                if (days > 0) {
                    node.textContent = days + 'd : ' + pad(hours) + 'h : ' + pad(minutes) + 'm';
                } else {
                    node.textContent = pad(hours) + 'h : ' + pad(minutes) + 'm : ' + pad(seconds) + 's';
                }
            });
        }

        tick();
        window.setInterval(tick, 1000);
    }

    /**
     * Off-canvas filter drawer on small screens.
     */
    function initDrawer() {
        var toggles = document.querySelectorAll('[data-pb-drawer-toggle]');

        Array.prototype.forEach.call(toggles, function (toggle) {
            toggle.addEventListener('click', function () {
                var target = document.getElementById(toggle.getAttribute('data-pb-drawer-toggle'));

                if (!target) {
                    return;
                }

                var isHidden = target.hasAttribute('hidden');

                if (isHidden) {
                    target.removeAttribute('hidden');
                } else {
                    target.setAttribute('hidden', '');
                }

                toggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initReveal();
            initCountdowns();
            initDrawer();
        });
    } else {
        initReveal();
        initCountdowns();
        initDrawer();
    }
}());
