/*
 * Mobile menu. Without this script the menu is simply always visible (it just wraps), so the site works either way.
 * With it, on a narrow phone the links hide behind a "Menu" button.
 */
(function () {
    'use strict';

    var toggle = document.getElementById('menu-toggle');
    var nav = document.getElementById('site-nav');
    if (!toggle || !nav) { return; }

    document.documentElement.classList.add('js');

    toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open ? 'Close' : 'Menu';
    });
}());
