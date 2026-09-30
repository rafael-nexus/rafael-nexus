<?php
declare(strict_types=1);

const THUMB_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
const PREVIEW_TYPES = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm'];

function file_ext(string $name): string
{
    return substr(preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($name, PATHINFO_EXTENSION))), 0, 10);
}

function new_file_name(string $original): string
{
    $ext = file_ext($original);
    return uuid() . ($ext !== '' ? ".$ext" : '');
}

function remove_data_file(string $dir, ?string $name): void
{
    if ($name) @unlink(data_path($dir, $name));
}

// Streams a file with HTTP Range support (required for video playback on iPhone/Safari and resumable downloads).
function send_file(string $path, string $type, ?string $downloadName = null, bool $cache = true): void
{
    if (!is_file($path)) { http_response_code(404); exit; }
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            header("Content-Range: bytes */$size", true, 416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    header("Content-Type: $type");
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('X-Content-Type-Options: nosniff');
    header($cache ? 'Cache-Control: public, max-age=604800' : 'Cache-Control: private, no-store');
    if ($downloadName !== null) {
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($downloadName));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int)min(1048576, $left));
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

// Returns a human-readable error for a failed PHP upload, or null if it's fine / absent.
function upload_error(?array $f): ?string
{
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    switch ($f['error']) {
        case UPLOAD_ERR_OK: return null;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return sprintf('"%s" is larger than the server allows (%s). Upload big masters by FTP into the data/inbox folder instead.',
                $f['name'], bytes_label(max_upload_bytes()));
        case UPLOAD_ERR_PARTIAL: return "\"{$f['name']}\" was only partly uploaded. Please try again.";
        default: return "Upload of \"{$f['name']}\" failed (error {$f['error']}).";
    }
}

function has_upload(?array $f): bool
{
    return $f && $f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']);
}

// Masters uploaded by FTP into data/inbox, waiting to be attached to a clip.
function inbox_files(): array
{
    $out = [];
    foreach (glob(data_path('inbox') . '/*') ?: [] as $p) {
        if (is_file($p) && basename($p)[0] !== '.' && basename($p) !== 'index.html') $out[basename($p)] = filesize($p);
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

// Makes a thumbnail smaller when GD is available, so the catalogue stays fast. Keeps the original on failure.
function shrink_image(string $path, int $maxWidth = 960): void
{
    if (!function_exists('imagecreatefromstring')) return;
    $info = @getimagesize($path);
    if (!$info || $info[0] <= $maxWidth) return;
    $src = @imagecreatefromstring((string)file_get_contents($path));
    if (!$src) return;
    $h = (int)round($info[1] * $maxWidth / $info[0]);
    $dst = imagecreatetruecolor($maxWidth, $h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $h, $info[0], $info[1]);
    $ok = match ($info[2]) {
        IMAGETYPE_PNG => imagepng($dst, $path, 6),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $path, 82) : false,
        default => imagejpeg($dst, $path, 82),
    };
    imagedestroy($src);
    imagedestroy($dst);
}

// Checks that the file really is an image (not just named like one).
function is_real_image(string $path): bool
{
    $info = @getimagesize($path);
    return $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
}
