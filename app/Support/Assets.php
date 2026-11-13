<?php

namespace App\Support;

/**
 * Links to our own CSS and JavaScript with the file's last-changed time on the end ("app.css?v=1763000000").
 *
 * Why: browsers keep a copy of a stylesheet and may keep using it for a while after a new version is put on the server,
 * so a release can look like it "did not update". When the file changes, the number changes, the address is new,
 * and every browser fetches the fresh file at once. Nothing else needs to be done on a release.
 */
class Assets
{
    public static function url(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
