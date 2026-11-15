<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

// Deletes stored report photos and videos from disk. Only paths in the two known storage shapes are
// touched, so a bad database value can never delete an unrelated file.
class AttachmentFileRemover
{
    /**
     * @param  iterable<string|null>  $paths
     */
    public function remove(iterable $paths): void
    {
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            if (preg_match(Attachment::STORED_PATH, $path)) {
                Storage::disk('local')->delete($path);
            } elseif (preg_match('#\Adatabase/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z#', $path)
                && is_file(base_path($path))) {
                @unlink(base_path($path));
            }
        }
    }
}
