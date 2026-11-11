/*
 * Home page: show "Your trips on this device" from the small list the lobby page keeps in
 * localStorage (trip code, trip name, your name; never a token). Plain JavaScript, no jQuery needed.
 * Clicking a trip just opens /t/CODE: the login cookie does the rest, and if it is gone the
 * server sends you to the join page with the code filled in.
 */
(function () {
    'use strict';

    var trips = [];
    try {
        trips = JSON.parse(localStorage.getItem('lobby_trips') || '[]');
    } catch (e) { trips = []; }
    if (!Array.isArray(trips)) { return; }

    var list = document.getElementById('my-trips-list');
    var shown = 0;

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

    if (shown > 0) { document.getElementById('my-trips').hidden = false; }
}());
