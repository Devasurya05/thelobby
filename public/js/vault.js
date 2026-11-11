/*
 * Vault page: while a file is uploading (a big photo on a slow phone connection can take a while),
 * disable the button so a second tap does not upload the same file twice.
 * Plain JavaScript. The server does not rely on this: it re-checks everything anyway.
 */
document.addEventListener('submit', function (event) {
    if (event.target.id !== 'upload-form' || event.defaultPrevented) {
        return;
    }
    var button = document.getElementById('upload-button');
    button.disabled = true;
    button.textContent = 'Uploading...';
});
