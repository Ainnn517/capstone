<?php

namespace App\Services;

use InvalidArgumentException;

class UploadedImageSanitizer
{
    private const MAX_DIMENSION = 4000;

    public function sanitize(string $image, string $mimeType): string
    {
        $size = getimagesizefromstring($image);
        if ($size === false
            || $size[0] > self::MAX_DIMENSION
            || $size[1] > self::MAX_DIMENSION
            || ($size['mime'] ?? null) !== $mimeType) {
            throw new InvalidArgumentException('Images must be valid and no larger than 4000 by 4000 pixels.');
        }

        return match ($mimeType) {
            'image/jpeg' => $this->stripJpegMetadata($image),
            'image/png' => $this->stripPngMetadata($image),
            'image/webp' => $this->stripWebpMetadata($image),
            'image/gif' => $this->stripGifComments($image),
            default => throw new InvalidArgumentException('Upload a JPG, PNG, GIF, or WebP image.'),
        };
    }

    private function stripJpegMetadata(string $image): string
    {
        if (! str_starts_with($image, "\xFF\xD8")) {
            throw new InvalidArgumentException('The uploaded JPEG image is malformed.');
        }

        $clean = substr($image, 0, 2);
        $offset = 2;
        $length = strlen($image);
        while ($offset < $length) {
            $segmentStart = $offset;
            if (ord($image[$offset]) !== 0xFF) {
                throw new InvalidArgumentException('The uploaded JPEG image is malformed.');
            }
            while ($offset < $length && ord($image[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                throw new InvalidArgumentException('The uploaded JPEG image is malformed.');
            }
            $marker = ord($image[$offset++]);
            if ($marker === 0xDA) {
                return $clean.substr($image, $segmentStart);
            }
            if (in_array($marker, [0xD8, 0xD9, 0x01, ...range(0xD0, 0xD7)], true)) {
                $clean .= substr($image, $segmentStart, $offset - $segmentStart);

                continue;
            }
            if ($offset + 2 > $length) {
                throw new InvalidArgumentException('The uploaded JPEG image is malformed.');
            }
            $segmentLength = unpack('n', substr($image, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new InvalidArgumentException('The uploaded JPEG image is malformed.');
            }
            $segmentEnd = $offset + $segmentLength;
            if (! in_array($marker, [0xE1, 0xED, 0xFE], true)) {
                $clean .= substr($image, $segmentStart, $segmentEnd - $segmentStart);
            }
            $offset = $segmentEnd;
        }

        throw new InvalidArgumentException('The uploaded JPEG image is incomplete.');
    }

    private function stripPngMetadata(string $image): string
    {
        if (! str_starts_with($image, "\x89PNG\r\n\x1A\n")) {
            throw new InvalidArgumentException('The uploaded PNG image is malformed.');
        }

        $clean = substr($image, 0, 8);
        $offset = 8;
        $length = strlen($image);
        while ($offset + 12 <= $length) {
            $chunkStart = $offset;
            $chunkLength = unpack('N', substr($image, $offset, 4))[1];
            $chunkType = substr($image, $offset + 4, 4);
            $chunkEnd = $offset + 12 + $chunkLength;
            if ($chunkEnd > $length) {
                throw new InvalidArgumentException('The uploaded PNG image is malformed.');
            }
            if (! in_array($chunkType, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $clean .= substr($image, $chunkStart, $chunkEnd - $chunkStart);
            }
            $offset = $chunkEnd;
            if ($chunkType === 'IEND') {
                return $clean.substr($image, $offset);
            }
        }

        throw new InvalidArgumentException('The uploaded PNG image is incomplete.');
    }

    private function stripWebpMetadata(string $image): string
    {
        if (! str_starts_with($image, 'RIFF') || substr($image, 8, 4) !== 'WEBP') {
            throw new InvalidArgumentException('The uploaded WebP image is malformed.');
        }

        $chunks = [];
        $offset = 12;
        $length = strlen($image);
        while ($offset + 8 <= $length) {
            $chunkStart = $offset;
            $chunkType = substr($image, $offset, 4);
            $chunkLength = unpack('V', substr($image, $offset + 4, 4))[1];
            $chunkEnd = $offset + 8 + $chunkLength + ($chunkLength % 2);
            if ($chunkEnd > $length) {
                throw new InvalidArgumentException('The uploaded WebP image is malformed.');
            }
            if (! in_array($chunkType, ['EXIF', 'XMP '], true)) {
                $chunk = substr($image, $chunkStart, $chunkEnd - $chunkStart);
                if ($chunkType === 'VP8X' && $chunkLength > 0) {
                    $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
                }
                $chunks[] = $chunk;
            }
            $offset = $chunkEnd;
        }

        $body = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private function stripGifComments(string $image): string
    {
        if (! str_starts_with($image, 'GIF87a') && ! str_starts_with($image, 'GIF89a')) {
            throw new InvalidArgumentException('The uploaded GIF image is malformed.');
        }

        $offset = 13;
        $packed = ord($image[10]);
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }
        if ($offset > strlen($image)) {
            throw new InvalidArgumentException('The uploaded GIF image is malformed.');
        }

        $clean = substr($image, 0, $offset);
        $length = strlen($image);
        while ($offset < $length) {
            $blockStart = $offset;
            $marker = ord($image[$offset++]);
            if ($marker === 0x3B) {
                return $clean.substr($image, $blockStart);
            }
            if ($marker === 0x21) {
                if ($offset >= $length) {
                    break;
                }
                $label = ord($image[$offset++]);
                $isComment = $label === 0xFE;
                if ($label === 0xF9) {
                    if ($offset + 6 > $length) {
                        break;
                    }
                    $offset += 6;
                } else {
                    if ($label === 0xFF) {
                        if ($offset >= $length) {
                            break;
                        }
                        $headerLength = ord($image[$offset]);
                        if ($offset + 1 + $headerLength > $length) {
                            break;
                        }
                        $isComment = stripos(substr($image, $offset + 1, $headerLength), 'XMP') !== false;
                    }
                    while ($offset < $length) {
                        $subBlockLength = ord($image[$offset++]);
                        if ($subBlockLength === 0) {
                            break;
                        }
                        if ($offset + $subBlockLength > $length) {
                            break 2;
                        }
                        $offset += $subBlockLength;
                    }
                }
                if (! $isComment) {
                    $clean .= substr($image, $blockStart, $offset - $blockStart);
                }

                continue;
            }
            if ($marker !== 0x2C || $offset + 9 > $length) {
                break;
            }
            $descriptor = ord($image[$offset + 8]);
            $offset += 9;
            if (($descriptor & 0x80) !== 0) {
                $offset += 3 * (1 << (($descriptor & 0x07) + 1));
            }
            if ($offset >= $length) {
                break;
            }
            $offset++;
            while ($offset < $length) {
                $subBlockLength = ord($image[$offset++]);
                if ($subBlockLength === 0) {
                    break;
                }
                if ($offset + $subBlockLength > $length) {
                    break 2;
                }
                $offset += $subBlockLength;
            }
            $clean .= substr($image, $blockStart, $offset - $blockStart);
        }

        throw new InvalidArgumentException('The uploaded GIF image is incomplete or malformed.');
    }
}
