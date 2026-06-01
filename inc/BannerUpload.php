<?php

declare(strict_types=1);

/**
 * 광고 배너 이미지 파일 업로드
 */
final class BannerUpload
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public static function storageDir(): string
    {
        return ROOT_PATH . '/uploads/banners';
    }

    public static function storedPathPrefix(): string
    {
        return '/uploads/banners/';
    }

    public static function ensureStorage(): void
    {
        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('업로드 폴더를 만들 수 없습니다.');
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            file_put_contents($ht, "Options -Indexes\n");
        }
    }

    /**
     * @param array<string, mixed> $file $_FILES['image'] 등
     * @return array{path: string, url: string, src: string, filename: string}
     */
    public static function storeFromUpload(array $file): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadErrorMessage($err));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('업로드 파일이 올바르지 않습니다.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('이미지는 5MB 이하여야 합니다.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: '';
        $ext   = self::EXT_BY_MIME[$mime] ?? null;
        if ($ext === null) {
            throw new InvalidArgumentException('JPG, PNG, WebP, GIF 이미지만 업로드할 수 있습니다.');
        }

        self::ensureStorage();

        $name = 'bn_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = self::storageDir() . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('파일 저장에 실패했습니다.');
        }

        $path = self::storedPathPrefix() . $name;

        return [
            'path'     => $path,
            'url'      => web_upload_url($path),
            'src'      => web_upload_url($path),
            'filename' => $name,
        ];
    }

    public static function isManagedPath(string $imageUrl): bool
    {
        $u = trim($imageUrl);

        return str_starts_with($u, self::storedPathPrefix());
    }

    public static function deleteStoredFile(string $imageUrl): void
    {
        if (!self::isManagedPath($imageUrl)) {
            return;
        }
        $name = basename($imageUrl);
        if ($name === '' || preg_match('/[^a-zA-Z0-9._-]/', $name)) {
            return;
        }
        $full = self::storageDir() . '/' . $name;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '파일 크기가 제한을 초과했습니다.',
            UPLOAD_ERR_PARTIAL => '파일이 일부만 업로드되었습니다. 다시 시도하세요.',
            UPLOAD_ERR_NO_FILE => '이미지 파일을 선택하세요.',
            default => '파일 업로드에 실패했습니다.',
        };
    }
}
