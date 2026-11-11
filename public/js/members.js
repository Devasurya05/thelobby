/*
 * Members page: any form with a data-confirm attribute asks "are you sure?" before it is sent.
 * Plain JavaScript. The server does not rely on this: it re-checks everything anyway.
 */
document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
