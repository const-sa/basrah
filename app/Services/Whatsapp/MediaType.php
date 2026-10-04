<?php

namespace App\Services\Whatsapp;

/**
 * Maps file URLs / paths to the media types the WhatsApp gateways understand.
 */
final class MediaType
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const AUDIO = 'audio';

    public const DOCUMENT = 'document';

    private const EXTENSIONS = [
        self::IMAGE => [
            'jpg', 'jpeg', 'jfif', 'jpe', 'pjpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'tif', 'ico',
            'heic', 'heif',
        ],
        self::VIDEO => [
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp', 'mpeg', 'mpg', 'm4v',
        ],
        self::AUDIO => [
            'mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'amr', 'opus',
        ],
        self::DOCUMENT => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'csv', 'md',
            'json', 'xml', 'html', 'htm', 'zip', 'rar',
        ],
    ];

    /**
     * أنواع MIME للامتدادات الشائعة.
     *
     * تُعلَن للبوابة صراحةً ولا تُترك لها تستنتجها: خادمٌ يقدّم الملف بترويسة
     * application/octet-stream يجعل واتساب يعرضه «BIN» فلا يفتحه العميل، ولو
     * كان اسمه ينتهي بـ.pdf.
     */
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'rtf' => 'application/rtf',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'heic' => 'image/heic',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'amr' => 'audio/amr',
    ];

    /**
     * نوع MIME للملف، أو لا شيء إن كان امتداده مجهولًا.
     */
    public static function mimeType(string $file): ?string
    {
        return self::MIME_TYPES[self::extension($file)] ?? null;
    }

    /**
     * image|video|audio|document, or null when the extension is unknown.
     */
    public static function detect(string $file): ?string
    {
        $extension = self::extension($file);

        if ($extension === '') {
            return null;
        }

        foreach (self::EXTENSIONS as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    public static function isSupported(string $file): bool
    {
        return self::detect($file) !== null;
    }

    /**
     * Lowercased extension of a URL or path, without the dot.
     */
    public static function extension(string $file): string
    {
        $path = parse_url($file, PHP_URL_PATH);

        return strtolower(pathinfo(is_string($path) && $path !== '' ? $path : $file, PATHINFO_EXTENSION));
    }

    /**
     * File name of a URL or path, e.g. "https://site.test/pdf/invoice_9.pdf?v=2" -> "invoice_9.pdf".
     */
    public static function fileName(string $file): string
    {
        $path = parse_url($file, PHP_URL_PATH);

        return basename(is_string($path) && $path !== '' ? $path : $file);
    }
}
