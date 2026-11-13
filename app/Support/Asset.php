<?php

namespace App\Support;

// Adds the file's last-modified time to local CSS/JS links (style.css?v=1763021234), so
// browsers download a file again as soon as it changes instead of reusing a cached copy.
class Asset
{
    public static function url(string $path): string
    {
        $file = public_path(ltrim($path, '/'));

        return is_file($file) ? $path.'?v='.filemtime($file) : $path;
    }
}
