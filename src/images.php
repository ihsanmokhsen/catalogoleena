<?php
declare(strict_types=1);

// Upload foto produk. File asli tidak pernah disimpan: gambar selalu dibuat ulang lewat GD
// (membuang metadata/EXIF dan isi tersembunyi), diperkecil, lalu disimpan sebagai JPEG dengan nama acak.

const PRODUCT_UPLOAD_DIR = 'uploads/products';
const PRODUCT_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const PRODUCT_IMAGE_MAX_PIXELS = 16_500_000; // cukup untuk foto HP 12 MP, mencegah "decompression bomb"
const PRODUCT_IMAGE_MIN_SIDE = 300;
const PRODUCT_IMAGE_BOX = [900, 1200];       // batas lebar x tinggi hasil (katalog memakai rasio 3:4)
const PRODUCT_IMAGE_QUALITY = 82;

function product_upload_path(string $relative = ''): string
{
    return __DIR__ . '/../public/' . PRODUCT_UPLOAD_DIR . ($relative === '' ? '' : '/' . $relative);
}

/**
 * Validasi, proses, dan simpan foto dari $_FILES. Mengembalikan path relatif untuk kolom products.image.
 */
function product_image_store(array $file, string $slug): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Pilih foto produk.');
    }
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > PRODUCT_IMAGE_MAX_BYTES) {
        throw new InvalidArgumentException('Foto terlalu besar (maksimal 8 MB).');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Upload foto gagal, coba lagi.');
    }

    $tmp = $file['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];
    if (!isset($loaders[$mime])) {
        throw new InvalidArgumentException('Format foto harus JPG, PNG, atau WebP.');
    }

    $info = @getimagesize($tmp);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        throw new InvalidArgumentException('File bukan gambar yang valid.');
    }
    [$width, $height] = $info;
    if ($width * $height > PRODUCT_IMAGE_MAX_PIXELS) {
        throw new InvalidArgumentException('Resolusi foto terlalu besar. Perkecil di bawah 16 megapiksel.');
    }
    if (min($width, $height) < PRODUCT_IMAGE_MIN_SIDE) {
        throw new InvalidArgumentException('Foto terlalu kecil (sisi terpendek minimal 300 px).');
    }

    $source = @$loaders[$mime]($tmp);
    if (!$source) {
        throw new InvalidArgumentException('Foto rusak atau tidak bisa dibaca.');
    }
    $source = product_image_fix_orientation($source, $tmp, $mime);
    $width = imagesx($source);
    $height = imagesy($source);

    $scale = min(1, PRODUCT_IMAGE_BOX[0] / $width, PRODUCT_IMAGE_BOX[1] / $height);
    $targetW = max(1, (int) round($width * $scale));
    $targetH = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetW, $targetH);
    // Latar putih untuk PNG/WebP transparan.
    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
    imagedestroy($source);

    $dir = product_upload_path();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Folder upload tidak bisa dibuat: $dir");
    }

    $name = substr($slug, 0, 80) . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $tmpOut = $dir . '/.' . $name . '.tmp';
    imageinterlace($target, true);
    $saved = imagejpeg($target, $tmpOut, PRODUCT_IMAGE_QUALITY);
    imagedestroy($target);
    if (!$saved || !rename($tmpOut, $dir . '/' . $name)) {
        @unlink($tmpOut);
        throw new RuntimeException('Gagal menyimpan foto ke ' . $dir);
    }
    chmod($dir . '/' . $name, 0644);

    return PRODUCT_UPLOAD_DIR . '/' . $name;
}

// Foto dari kamera HP sering tersimpan miring dan hanya ditandai lewat EXIF Orientation.
function product_image_fix_orientation(GdImage $image, string $path, string $mime): GdImage
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }
    $exif = @exif_read_data($path);
    $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
    if ($angle === 0) {
        return $image;
    }
    $rotated = imagerotate($image, $angle, 0);
    if ($rotated === false) {
        return $image;
    }
    imagedestroy($image);
    return $rotated;
}

// Hanya hapus foto hasil upload (bukan foto bawaan di assets/).
function product_image_delete(?string $relative): void
{
    if ($relative && preg_match('#^' . PRODUCT_UPLOAD_DIR . '/[a-z0-9-]+\.jpg$#', $relative)) {
        @unlink(product_upload_path(basename($relative)));
    }
}

function slugify(string $text): string
{
    $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    return substr($slug, 0, 100) ?: 'produk';
}
