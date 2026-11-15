<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends ApiController
{
    // Return a photo or video only when the signed-in user can access its report.
    public function show(int $id): JsonResponse|Response|BinaryFileResponse
    {
        $user = $this->currentUser();

        $query = Attachment::query()
            ->where('attachment_id', $id);
        if ($user->role !== 'admin') {
            $query->whereHas('report', fn ($reports) => $reports
                ->where('user_id', $user->user_id)
                ->whereNull('archived_at'));
        }
        $attachment = $query->first();
        if (! $attachment) {
            return $this->error('Attachment not found.', 404);
        }

        $imageData = $attachment->file_data;
        $mimeType = $attachment->mime_type;
        $allowed = array_merge(array_keys(Attachment::IMAGE_TYPES), array_keys(Attachment::VIDEO_TYPES));
        // Files on disk are streamed with byte-range support, which phones need to play
        // and seek videos.
        if ($imageData === null && preg_match(Attachment::STORED_PATH, (string) $attachment->file_path)
            && in_array($mimeType, $allowed, true)) {
            if (! Storage::disk('local')->exists($attachment->file_path)) {
                return $this->error('Attachment not found.', 404);
            }

            return response()->file(Storage::disk('local')->path($attachment->file_path), [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        if ($imageData === null && $attachment->file_path !== null
            && preg_match('#\Adatabase/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z#', $attachment->file_path)) {
            $legacyPath = base_path($attachment->file_path);
            if (is_file($legacyPath)) {
                $imageData = file_get_contents($legacyPath);
                $mimeType = $imageData === false ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($imageData);
            }
        }
        if (is_resource($imageData)) {
            $imageData = stream_get_contents($imageData);
        }

        if (! is_string($imageData)
            || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return $this->error('Attachment not found.', 404);
        }

        return response($imageData, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($imageData),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
