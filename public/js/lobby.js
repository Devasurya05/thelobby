/*
 * The Lobby screen. Plain jQuery:
 *   - every ~2 seconds ask the server for the state (GET .../state) and redraw if something changed
 *   - Grab / Confirm / Release / Give up buttons POST to the server (with the CSRF token in a header)
 *   - the server is always the boss: this script only shows what the server says
 *
 * SECURITY NOTE: names typed by users are only ever inserted with .text(), never .html(),
 * so a name like <script>alert(1)</script> is shown as plain text and cannot run (XSS).
 */
(function ($) {
    'use strict';

    var $root = $('#lobby');
    var stateUrl = $root.data('state-url');
    var actionBase = $root.data('action-base');
    var joinUrl = $root.data('join-url');
    var pollMs = Number($root.data('poll-ms')) || 2000;

    // jQuery adds this header to every AJAX call; Laravel rejects POSTs without it (CSRF protection).
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Accept': 'application/json'
        }
    });

    var deadlines = {};      // bed id -> time (ms) when its hold runs out, for the countdowns
    var lastSignature = '';  // fingerprint of the last drawn state, to skip pointless redraws
    var busy = false;        // true while a button press is being processed (ignores double taps)
    var stopped = false;     // true once we know polling is pointless (removed from the trip)
    var noticeTimer = null;
    var lastRoomCount = null; // how many rooms the last drawing had (null = nothing drawn yet)

    function formatSeconds(total) {
        var m = Math.floor(total / 60);
        var s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function notice(text, kind) {
        var $n = $('#notice');
        $n.removeClass('ok error').addClass(kind || 'ok').text(text).prop('hidden', false);
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(function () { $n.prop('hidden', true); }, 6000);
    }

    // Draw only when something other than the clock changed. Redrawing every 2 seconds would
    // replace a button between a finger touching it and lifting off, and the tap would be lost.
    function signatureOf(state) {
        return JSON.stringify({
            trip: state.trip,
            me: state.me,
            rooms: $.map(state.rooms, function (room) {
                return {
                    id: room.id,
                    name: room.name,
                    beds: $.map(room.beds, function (b) {
                        return { id: b.id, label: b.label, status: b.status, by: b.by, mine: b.mine };
                    })
                };
            })
        });
    }

    function applyState(state) {
        // Remember when each hold ends, measured on THIS phone's clock.
        $.each(state.rooms, function (_, room) {
            $.each(room.beds, function (_, b) {
                if (b.seconds_left === null) {
                    delete deadlines[b.id];
                } else {
                    deadlines[b.id] = Date.now() + b.seconds_left * 1000;
                }
            });
        });

        var signature = signatureOf(state);
        if (signature !== lastSignature) {
            render(state);
            lastSignature = signature;
        }
        tick();
    }

    // The box at the top: one short heading (kicker) and one sentence telling the person what to do next.
    function setBanner(extraClass, kicker, text, countdownBedId) {
        var $banner = $('#me-banner').removeClass('has-bed holding ended').addClass(extraClass).empty();
        var $text = $('<p class="banner-text">').text(text);
        if (countdownBedId) {
            $text.append($('<span class="countdown">').attr('data-bed-id', countdownBedId));
        }
        $banner.append($('<p class="kicker">').text(kicker), $text);
    }

    function legendItem(status, label, count) {
        return $('<span>').append($('<span class="chip">').addClass(status).text(label), $('<span class="count">').text(count));
    }

    function render(state) {
        var me = state.me;
        var ended = state.trip.ended;

        if (ended) {
            setBanner('ended', 'Trip ended', 'The lobby is read-only now.');
        } else if (state.rooms.length === 0) {
            // A trip waiting for a booking has no rooms yet; they appear here on their own (this page refreshes every few seconds).
            setBanner('', 'No rooms yet', 'Rooms and beds appear here as soon as a booking is added to the trip.');
        } else if (me.taken_label) {
            setBanner('has-bed', 'Your bed', me.taken_label + '. You can give it up if your plans change.');
        } else if (me.held_bed_id) {
            setBanner('holding', 'Holding a bed', 'Confirm before the timer runs out: ', me.held_bed_id);
        } else if (!me.can_pick) {
            setBanner('', 'Viewing', 'You can watch the lobby. Viewers cannot pick beds.');
        } else {
            setBanner('', 'Pick a bed', 'Tap Grab on a free bed. It stays yours for a short time while you decide.');
        }

        // The counts double as the legend for the three status labels (nothing to count while there are no rooms).
        var $summary = $('#summary').empty();
        if (state.rooms.length > 0) {
            $summary.append(
                legendItem('free', 'Free', state.counts.free),
                legendItem('held', 'Held', state.counts.held),
                legendItem('taken', 'Taken', state.counts.taken)
            );
        }

        // The first rooms just arrived (a booking was sent to a trip that was waiting for one): reload once, so the
        // dates, the place and the "waiting for a booking" box at the top are updated too.
        if (lastRoomCount === 0 && state.rooms.length > 0) {
            window.location.reload();
            return;
        }
        lastRoomCount = state.rooms.length;

        var $rooms = $('#rooms').empty();
        $.each(state.rooms, function (_, room) {
            var $room = $('<section class="room">').append($('<h2>').text(room.name));
            var $grid = $('<div class="beds">').appendTo($room);

            $.each(room.beds, function (_, b) {
                $grid.append(renderBed(b, me, ended));
            });
            $rooms.append($room);
        });
    }

    function renderBed(b, me, ended) {
        var $bed = $('<div class="bed">').addClass(b.status).attr('data-bed-id', b.id);
        if (b.mine) { $bed.addClass('mine'); }

        // The status word is shown as text as well as colour, so it reads the same without colour vision.
        $bed.append($('<div class="bed-top">').append(
            $('<span class="bed-label">').text(b.label),
            $('<span class="chip">').addClass(b.status).text(b.status)
        ));

        var $status = $('<div class="bed-status">');
        var who = b.mine ? 'you' : b.by;
        if (b.status === 'held') {
            $status.append(document.createTextNode('Held by ' + who + ' · '))
                .append($('<span class="countdown">').attr('data-bed-id', b.id));
        } else if (b.status === 'taken') {
            $status.text('Taken by ' + who);
        }
        $bed.append($status);

        // Which buttons make sense for this bed and this person.
        var $actions = $('<div class="bed-actions">');
        if (!ended && me.can_pick) {
            if (b.status === 'free' && !me.taken_label) {
                $actions.append(button('grab', 'Grab'));
            } else if (b.status === 'held' && b.mine) {
                $actions.append(button('confirm', 'Confirm'), button('release', 'Release', 'secondary'));
            } else if (b.status === 'taken' && b.mine) {
                $actions.append(button('giveup', 'Give up bed', 'secondary'));
            }
        }
        return $bed.append($actions);
    }

    function button(action, text, extraClass) {
        return $('<button type="button">').addClass(extraClass || '').attr('data-action', action).text(text);
    }

    // Runs once a second: move every visible countdown. No server call involved.
    function tick() {
        $('.countdown').each(function () {
            var end = deadlines[$(this).attr('data-bed-id')];
            if (end === undefined) { return; }
            $(this).text(formatSeconds(Math.max(0, Math.round((end - Date.now()) / 1000))));
        });
    }

    // ---- talking to the server ----------------------------------------------------------------

    function poll() {
        if (stopped) { return; }
        $.ajax({ url: stateUrl, dataType: 'json', cache: false })
            .done(applyState)
            .fail(function (xhr) {
                if (xhr.status === 401) {
                    window.location.href = joinUrl;   // cookie gone: join again
                } else if (xhr.status === 403) {
                    stopped = true;
                    forgetTrip();
                    setBanner('ended', 'Removed', 'You have been removed from this trip.');
                } else if (xhr.status === 404) {
                    // The trip is gone (deleted): polling again would only repeat the error forever.
                    stopped = true;
                    forgetTrip();
                    setBanner('ended', 'Gone', 'This trip no longer exists.');
                } else {
                    notice('Connection problem. Trying again...', 'error');
                }
            })
            .always(function () {
                // Next poll only after this one finished: requests can never pile up on a slow network.
                if (!stopped) { setTimeout(poll, pollMs); }
            });
    }

    $root.on('click', 'button[data-action]', function () {
        if (busy) { return; }

        // Giving up a confirmed bed is the one action we ask about first: someone else may grab it at once.
        if ($(this).attr('data-action') === 'giveup' && !window.confirm('Give up your bed? Someone else can take it right away.')) {
            return;
        }
        busy = true;

        var $btn = $(this);
        var bedId = $btn.closest('.bed').attr('data-bed-id');
        $('#lobby button').prop('disabled', true);

        $.post(actionBase + bedId + '/' + $btn.attr('data-action'))
            .done(function (data) {
                notice(data.message, 'ok');
                applyState(data.state);
            })
            .fail(function (xhr) {
                var data = xhr.responseJSON;
                if (data && data.state) {
                    // e.g. "Someone just grabbed this bed": show why, and redraw so the bed is red now.
                    notice(data.message, 'error');
                    applyState(data.state);
                } else if (xhr.status === 429) {
                    notice('Slow down a little, too many taps. Try again in a moment.', 'error');
                } else if (xhr.status === 401 || xhr.status === 403) {
                    window.location.href = joinUrl;
                } else {
                    notice('Something went wrong, please try again.', 'error');
                }
            })
            .always(function () {
                busy = false;
                $('#lobby button').prop('disabled', false);
            });
    });

    // ---- "your trips on this device" -------------------------------------------------------------
    // The login cookie only travels with /t/CODE pages, so the home page cannot see it. To let the
    // home page offer a "back to your trip" shortcut we keep a tiny list in this browser's
    // localStorage: trip code + trip name + your name. NO token, nothing secret. Losing it is harmless.
    var STORE_KEY = 'lobby_trips';

    function readTrips() {
        try {
            var list = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
            return $.isArray(list) ? list : [];
        } catch (e) { return []; }
    }

    function writeTrips(list) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(list.slice(0, 10))); } catch (e) { /* private mode: ignore */ }
    }

    function rememberTrip() {
        var code = String($root.data('trip-code'));
        var others = $.grep(readTrips(), function (t) { return t.code !== code; });
        // Newest first, so the trip you just opened is at the top of the list.
        writeTrips([{ code: code, name: String($root.data('trip-name')), me: String($root.data('my-name')) }].concat(others));
    }

    function forgetTrip() {
        var code = String($root.data('trip-code'));
        writeTrips($.grep(readTrips(), function (t) { return t.code !== code; }));
    }

    rememberTrip();

    // ---- invite link ---------------------------------------------------------------------------

    var $invite = $('#invite-link');

    // The modern clipboard API only works on HTTPS or localhost. Over plain http (a phone on the
    // Wi-Fi) we fall back to selecting the text and using the old copy command.
    $('#copy-invite').on('click', function () {
        var link = $invite.val();
        function fallback() {
            $invite.trigger('focus').get(0).select();
            var copied = false;
            try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
            notice(copied ? 'Invite link copied.' : 'Press and hold the link to copy it.', copied ? 'ok' : 'error');
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link)
                .then(function () { notice('Invite link copied.', 'ok'); }, fallback);
        } else {
            fallback();
        }
    });

    if (navigator.share) {
        $('#share-invite').prop('hidden', false).on('click', function () {
            navigator.share({ title: document.title, url: $invite.val() }).catch(function () { /* user closed the sheet */ });
        });
    }

    setInterval(tick, 1000);
    poll();
}(jQuery));
