<?php

declare(strict_types=1);

/**
 * 바이너리 파일 다운로드 전 출력 버퍼·경고 정리
 */
function send_download_response(string $binary, string $filename, string $contentType, array $extraHeaders = []): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }

    echo $binary;
    exit;
}
