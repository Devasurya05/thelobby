/*
 * Home page, plain JavaScript (no jQuery), three small jobs:
 *   1. "Your trips on this device": read the small list the lobby page keeps in localStorage
 *      (trip code, trip name, your name; never a token). Clicking one just opens /t/CODE: the login
 *      cookie does the rest, and if it is gone the server sends you to the join page with the code filled in.
 *   2. "Fill in an example" for the rooms box.
 *   3. The practice lobby. It is made up on this page only: nothing is sent to the server or saved.
 */
(function () {
    'use strict';

    // ---- 1. your trips on this device -----------------------------------------------------------
    var trips = [];
    try {
        trips = JSON.parse(localStorage.getItem('lobby_trips') || '[]');
    } catch (e) { trips = []; }

    var list = document.getElementById('my-trips-list');
    var shown = 0;

    if (Array.isArray(trips) && list) {
        trips.forEach(function (t) {
            // localStorage can be edited by anyone with the browser: only accept well-formed codes.
            if (!t || typeof t.code !== 'string' || !/^[A-Za-z0-9]{8}$/.test(t.code)) { return; }

            var link = document.createElement('a');
            link.href = '/t/' + t.code;
            // textContent (never innerHTML): names are shown as plain text, so they cannot run as code.
            link.textContent = String(t.name || t.code);

            var who = document.createElement('span');
            who.className = 'muted small';
            who.textContent = ' as ' + String(t.me || 'you');

            var li = document.createElement('li');
            li.appendChild(link);
            li.appendChild(who);
            list.appendChild(li);
            shown++;
        });
    }
    if (shown > 0) { document.getElementById('my-trips').hidden = false; }

    // ---- 2. example rooms -----------------------------------------------------------------------
    var fill = document.getElementById('fill-example');
    if (fill) {
        fill.addEventListener('click', function () {
            var box = document.getElementById('rooms');
            box.value = 'Room 1: Window, Door\nRoom 2: Bed 1, Bed 2, Bed 3';
            box.focus();
        });
    }

    // ---- 3. practice lobby ----------------------------------------------------------------------
    var demo = document.getElementById('demo');
    if (!demo) { return; }

    var HOLD = 20; // seconds, shorter than the real lobby so the demo is quick
    var beds = [];
    var me = { state: 'none', bed: -1, left: 0 }; // state: none | holding | has

    function reset() {
        beds = [
            { label: 'Window', status: 'taken', by: 'Asha' },
            { label: 'Door', status: 'free', by: null },
            { label: 'Bed 3', status: 'held', by: 'Ravi', left: 12 },
            { label: 'Bed 4', status: 'free', by: null }
        ];
        me = { state: 'none', bed: -1, left: 0 };
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    function actionButton(text, cls, handler) {
        var b = el('button', cls || '', text);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
    }

    function fmt(s) { return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60); }

    function draw() {
        demo.textContent = '';

        var banner = el('div', 'me-banner' + (me.state === 'holding' ? ' holding' : me.state === 'has' ? ' has-bed' : ''));
        var kicker = el('p', 'kicker', me.state === 'holding' ? 'Holding a bed' : me.state === 'has' ? 'Your bed' : 'Pick a bed');
        var text = el('p', 'banner-text');
        if (me.state === 'holding') {
            text.textContent = 'Confirm before the timer runs out: ';
            var bannerTimer = el('span', 'countdown', fmt(me.left));
            bannerTimer.setAttribute('data-bed', String(me.bed));
            text.appendChild(bannerTimer);
        } else if (me.state === 'has') {
            text.textContent = 'Room 1 · ' + beds[me.bed].label + '. You can give it up if your plans change.';
        } else {
            text.textContent = 'Tap Grab on a free bed.';
        }
        banner.appendChild(kicker);
        banner.appendChild(text);
        demo.appendChild(banner);

        var grid = el('div', 'beds');
        grid.style.marginTop = '16px';
        beds.forEach(function (b, i) {
            var mine = me.bed === i && me.state !== 'none';
            var card = el('div', 'bed ' + b.status + (mine ? ' mine' : ''));

            var top = el('div', 'bed-top');
            top.appendChild(el('span', 'bed-label', b.label));
            top.appendChild(el('span', 'chip ' + b.status, b.status));
            card.appendChild(top);

            var status = el('div', 'bed-status');
            if (b.status === 'held') {
                status.textContent = 'Held by ' + (mine ? 'you' : b.by) + ' · ';
                var timer = el('span', 'countdown', fmt(mine ? me.left : b.left));
                timer.setAttribute('data-bed', String(i));
                status.appendChild(timer);
            } else if (b.status === 'taken') {
                status.textContent = 'Taken by ' + (mine ? 'you' : b.by);
            }
            card.appendChild(status);

            var actions = el('div', 'bed-actions');
            if (b.status === 'free' && me.state === 'none') {
                actions.appendChild(actionButton('Grab', '', function () { grab(i); }));
            } else if (mine && me.state === 'holding') {
                actions.appendChild(actionButton('Confirm', '', function () { confirmBed(i); }));
                actions.appendChild(actionButton('Release', 'secondary', function () { release(i); }));
            } else if (mine && me.state === 'has') {
                actions.appendChild(actionButton('Give up bed', 'secondary', function () { giveUp(i); }));
            }
            card.appendChild(actions);
            grid.appendChild(card);
        });
        demo.appendChild(grid);

        var note = el('p', 'hint practice-note', 'Practice only. ');
        note.appendChild(actionButton('Start over', 'link-button', function () { reset(); draw(); }));
        demo.appendChild(note);
    }

    function grab(i) {
        beds[i].status = 'held';
        beds[i].by = 'you';
        me = { state: 'holding', bed: i, left: HOLD };
        draw();
    }

    function confirmBed(i) {
        beds[i].status = 'taken';
        me = { state: 'has', bed: i, left: 0 };
        draw();
    }

    function release(i) {
        beds[i].status = 'free';
        beds[i].by = null;
        me = { state: 'none', bed: -1, left: 0 };
        draw();
    }

    function giveUp(i) { release(i); }

    // One tick a second: count the holds down. Only when a hold runs out do we redraw the whole lobby;
    // otherwise we just rewrite the timer text, so a button is never swapped out under a finger mid-tap.
    setInterval(function () {
        var rebuild = false;
        beds.forEach(function (b, i) {
            if (b.status !== 'held') { return; }
            var mine = me.bed === i && me.state === 'holding';
            if (mine) {
                me.left -= 1;
                if (me.left <= 0) { release(i); rebuild = true; }
            } else {
                b.left -= 1;
                if (b.left <= 0) { b.status = 'free'; b.by = null; rebuild = true; }
            }
        });

        if (rebuild) { draw(); return; }

        Array.prototype.forEach.call(demo.querySelectorAll('.countdown'), function (span) {
            var i = Number(span.getAttribute('data-bed'));
            var mine = me.bed === i && me.state === 'holding';
            span.textContent = fmt(mine ? me.left : beds[i].left);
        });
    }, 1000);

    reset();
    draw();
}());
