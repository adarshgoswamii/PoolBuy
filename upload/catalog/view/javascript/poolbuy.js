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
     * Live pool progress.
     *
     * Every figure updated here is already rendered correctly by the server, so
     * this only keeps a page that is left open from going stale. Without
     * JavaScript, or if the request fails, the server-rendered values simply
     * remain - nothing is hidden pending a fetch.
     *
     * Containers opt in with data-pb-progress="<pool_id>". Inside, any of these
     * optional hooks are updated when present:
     *   [data-pb-progress-filled]    "x of y Units" style summary
     *   [data-pb-progress-reserved]  the reserved quantity on its own
     *   [data-pb-progress-percent]   the "NN% full" headline
     *   [role=progressbar]           aria-valuenow and the inner bar width
     */
    function initProgress() {
        var containers = document.querySelectorAll('[data-pb-progress]');

        if (!containers.length || typeof window.fetch !== 'function') {
            return;
        }

        var POLL_MS = 20000;
        var timer = null;

        function poolIds() {
            var ids = [];

            Array.prototype.forEach.call(containers, function (el) {
                var id = parseInt(el.getAttribute('data-pb-progress'), 10);

                // Skip pools already in a terminal state; they cannot move again.
                if (!isNaN(id) && ids.indexOf(id) === -1 && el.getAttribute('data-pb-progress-done') !== '1') {
                    ids.push(id);
                }
            });

            return ids;
        }

        function setText(scope, selector, value) {
            var node = scope.querySelector(selector);

            if (node && typeof value === 'string' && value !== '') {
                node.textContent = value;
            }
        }

        function apply(pool) {
            var scope = document.querySelector('[data-pb-progress="' + pool.pool_id + '"]');

            if (!scope) {
                return;
            }

            var bar = scope.querySelector('[role="progressbar"]');

            if (bar) {
                bar.setAttribute('aria-valuenow', String(pool.fill));

                var inner = bar.querySelector('.pb-progress__bar');

                if (inner) {
                    inner.style.width = pool.fill + '%';
                }

                // A pool that is no longer open should stop pulsing.
                if (!pool.is_open) {
                    bar.classList.remove('pb-progress--pulse');
                }
            }

            setText(scope, '[data-pb-progress-reserved]', String(pool.reserved_qty));
            setText(scope, '[data-pb-progress-percent]', pool.fill + '% full');

            var filled = scope.querySelector('[data-pb-progress-filled]');

            if (filled) {
                // Rewrite only the numbers, preserving whatever wording and unit
                // label the server chose for this locale.
                filled.textContent = filled.textContent
                    .replace(/\d[\d,]*/, String(pool.reserved_qty));
            }

            // Once terminal, stop asking about this pool.
            if (!pool.is_open) {
                scope.setAttribute('data-pb-progress-done', '1');
            }
        }

        function refresh() {
            var ids = poolIds();

            if (!ids.length) {
                stop();
                return;
            }

            fetch('index.php?route=extension/poolbuy/pool.progress&pool_id=' + ids.join(','), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (json) {
                if (json && json.pools) {
                    json.pools.forEach(apply);
                }
            }).catch(function () {
                // Leave the server-rendered values in place on any failure.
            });
        }

        function start() {
            if (timer === null) {
                timer = window.setInterval(refresh, POLL_MS);
            }
        }

        function stop() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        // Do not poll while the tab is in the background.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                refresh();
                start();
            }
        });

        if (!document.hidden) {
            start();
        }
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
            initProgress();
        });
    } else {
        initReveal();
        initCountdowns();
        initDrawer();
        initProgress();
    }
}());
