<?php
require_once __DIR__.'/config.php';

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function random_name(string $ext): string {
    return bin2hex(random_bytes(16)).'.'.$ext;
}

/**
 * Belső segéd: abszolút -> relatív útvonal a projekt gyökeréhez képest
 * (adatbázisba ezt a relatív utat tároljuk, pl. "signatures/2025/09/abc.png")
 */
function relpath_from_base(string $absPath): string {
    $absBase = rtrim(str_replace('\\','/', BASE_DIR), '/') . '/';
    $p       = str_replace('\\','/', $absPath);
    if (strpos($p, $absBase) === 0) {
        return ltrim(substr($p, strlen($absBase)), '/');
    }
    return basename($p); // fallback
}

/**
 * Biztonságos képfeltöltés fájlból (PNG/JPG/WEBP)
 * @param array  $file         $_FILES[...] eleme
 * @param string $targetDirAbs abszolút célkönyvtár (pl. UPLOADS_DIR vagy SIGNATURES_DIR)
 * @return string relatív út a projekt gyökeréhez képest (pl. "uploads/2025/09/abc.jpg")
 */
function safe_upload_image(array $file, string $targetDirAbs = UPLOADS_DIR): string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Feltöltési hiba.');
    }
    if ($file['size'] > 10*1024*1024) {
        throw new RuntimeException('Túl nagy fájl (max 10MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Csak PNG/JPG/WEBP engedélyezett.');
    }

    $ext      = $allowed[$mime];
    $datePath = date('Y/m');
    $dirAbs   = rtrim($targetDirAbs, '/\\') . DIRECTORY_SEPARATOR . $datePath;
    ensure_dir($dirAbs);

    $destAbs  = $dirAbs . DIRECTORY_SEPARATOR . random_name($ext);
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        throw new RuntimeException('Mentési hiba.');
    }
    chmod($destAbs, 0644);

    return relpath_from_base($destAbs);
}

/**
 * Canvas data URI (data:image/png;base64,...) mentése fájlba
 * @param string $dataUri      a canvas.toDataURL('image/png') eredménye
 * @param string $targetDirAbs abszolút célkönyvtár (pl. SIGNATURES_DIR)
 * @return string relatív út a projekt gyökeréhez képest (pl. "signatures/2025/09/abc.png")
 */
function save_canvas_png(string $dataUri, string $targetDirAbs = SIGNATURES_DIR): string {
    if (!preg_match('#^data:image/(png);base64,#', $dataUri, $m)) {
        throw new RuntimeException('Érvénytelen aláírás formátum (PNG kell).');
    }
    $raw = base64_decode(substr($dataUri, strpos($dataUri, ',')+1), true);
    if ($raw === false || strlen($raw) < 200) {
        throw new RuntimeException('Üres vagy hibás aláírás.');
    }

    $datePath = date('Y/m');
    $dirAbs   = rtrim($targetDirAbs, '/\\') . DIRECTORY_SEPARATOR . $datePath;
    ensure_dir($dirAbs);

    $destAbs  = $dirAbs . DIRECTORY_SEPARATOR . random_name('png');
    if (file_put_contents($destAbs, $raw) === false) {
        throw new RuntimeException('Aláírás mentési hiba.');
    }
    chmod($destAbs, 0644);

    return relpath_from_base($destAbs);
}
