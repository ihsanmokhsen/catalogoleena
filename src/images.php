<?php
declare(strict_types=1);

// Upload foto produk. File asli tidak pernah disimpan: gambar selalu dibuat ulang lewat GD
// (membuang metadata/EXIF dan isi tersembunyi), lalu disimpan sebagai JPEG dengan nama acak dalam dua ukuran:
//   nama.jpg     HD untuk tampilan detail/zoom (tajam di layar HP berkepadatan 3x)
//   nama-sm.jpg  ukuran kartu katalog (ringan untuk koneksi HP)

const PRODUCT_UPLOAD_DIR = 'uploads/products';
const PRODUCT_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const PRODUCT_IMAGE_MAX_PIXELS = 16_500_000; // cukup untuk foto HP 12 MP, mencegah "decompression bomb"
const PRODUCT_IMAGE_MIN_SIDE = 600;         // di bawah ini foto akan terlihat pecah di HP
const PRODUCT_IMAGE_LARGE = [1440, 1920, 86]; // batas lebar, tinggi, kualitas JPEG (rasio katalog 3:4)
const PRODUCT_IMAGE_SMALL = [720, 960, 82];
// Skor ketajaman (variansi Laplacian, diukur pada lebar/tinggi maks 1024 px). Dari 46 foto katalog:
// foto asli >= 57, versi tidak fokus (gaussian blur) <= 25. Foto yang sekadar diperbesar dari resolusi kecil
// tidak bisa dibedakan dengan andal, jadi itu dicegah lewat PRODUCT_IMAGE_MIN_SIDE.
const PRODUCT_IMAGE_BLUR_THRESHOLD = 40;
const PRODUCT_IMAGE_SHARPNESS_SIZE = 1024;

function product_upload_path(string $relative = ''): string
{
    return __DIR__ . '/../public/' . PRODUCT_UPLOAD_DIR . ($relative === '' ? '' : '/' . $relative);
}

/**
 * Validasi, proses, dan simpan foto dari $_FILES.
 * Mengembalikan path relatif foto HD (untuk kolom products.image) dan peringatan bila foto terdeteksi buram.
 *
 * @return array{path: string, warning: ?string}
 */
function product_image_store(array $file, string $slug): array
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
        throw new InvalidArgumentException('Foto terlalu kecil (sisi terpendek minimal 600 px). Pakai foto asli dari kamera, bukan screenshot atau foto dari chat.');
    }

    $source = @$loaders[$mime]($tmp);
    if (!$source) {
        throw new InvalidArgumentException('Foto rusak atau tidak bisa dibaca.');
    }
    $source = product_image_fix_orientation($source, $tmp, $mime);
    $sharpness = product_image_sharpness($source);

    $dir = product_upload_path();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Folder upload tidak bisa dibuat: $dir");
    }

    $base = substr($slug, 0, 80) . '-' . bin2hex(random_bytes(4));
    $written = [];
    try {
        foreach (['' => PRODUCT_IMAGE_LARGE, '-sm' => PRODUCT_IMAGE_SMALL] as $suffix => [$maxW, $maxH, $quality]) {
            $resized = product_image_resize($source, $maxW, $maxH);
            $final = "$dir/$base$suffix.jpg";
            $tmpOut = "$dir/.$base$suffix.tmp";
            imageinterlace($resized, true);
            $saved = imagejpeg($resized, $tmpOut, $quality);
            if (!$saved || !rename($tmpOut, $final)) {
                @unlink($tmpOut);
                throw new RuntimeException('Gagal menyimpan foto ke ' . $dir);
            }
            chmod($final, 0644);
            $written[] = $final;
        }
    } catch (Throwable $e) {
        array_map('unlink', $written);
        throw $e;
    }

    return [
        'path' => PRODUCT_UPLOAD_DIR . "/$base.jpg",
        'warning' => $sharpness < PRODUCT_IMAGE_BLUR_THRESHOLD
            ? 'Foto terdeteksi kurang tajam (tidak fokus atau goyang). Sebaiknya foto ulang dengan cahaya cukup, ketuk layar untuk fokus, dan kamera diam.'
            : null,
    ];
}

// Perkecil agar muat dalam kotak (tidak pernah memperbesar), latar putih untuk transparansi,
// lalu pertajam ringan supaya tekstur kain tetap jelas setelah diperkecil.
function product_image_resize(GdImage $source, int $maxW, int $maxH): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, $maxW / $width, $maxH / $height);
    $targetW = max(1, (int) round($width * $scale));
    $targetH = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($targetW, $targetH);
    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
    if ($scale < 0.9) {
        imageconvolution($target, [[0, -1, 0], [-1, 12, -1], [0, -1, 0]], 8, 0);
    }
    return $target;
}

// Variansi Laplacian pada versi grayscale kecil: ukuran sederhana seberapa tajam tepi-tepi di foto.
function product_image_sharpness(GdImage $source): float
{
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, PRODUCT_IMAGE_SHARPNESS_SIZE / max($width, $height));
    $w = max(3, (int) ($width * $scale));
    $h = max(3, (int) ($height * $scale));
    $small = imagecreatetruecolor($w, $h);
    imagecopyresampled($small, $source, 0, 0, 0, 0, $w, $h, $width, $height);

    $gray = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($small, $x, $y);
            $gray[$y][$x] = (($c >> 16 & 255) * 299 + ($c >> 8 & 255) * 587 + ($c & 255) * 114) / 1000;
        }
    }

    $sum = 0.0;
    $squares = 0.0;
    $count = 0;
    for ($y = 1; $y < $h - 1; $y++) {
        for ($x = 1; $x < $w - 1; $x++) {
            $lap = 4 * $gray[$y][$x] - $gray[$y - 1][$x] - $gray[$y + 1][$x] - $gray[$y][$x - 1] - $gray[$y][$x + 1];
            $sum += $lap;
            $squares += $lap * $lap;
            $count++;
        }
    }
    $mean = $sum / $count;
    return $squares / $count - $mean * $mean;
}

// Path versi kartu (kecil). Foto bawaan di assets/ hanya punya satu ukuran.
function product_image_small(string $path): string
{
    return str_starts_with($path, PRODUCT_UPLOAD_DIR . '/') ? substr($path, 0, -4) . '-sm.jpg' : $path;
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
    return $rotated;
}

// Hanya hapus foto hasil upload (bukan foto bawaan di assets/), beserta versi kartunya.
function product_image_delete(?string $relative): void
{
    if ($relative && preg_match('#^' . PRODUCT_UPLOAD_DIR . '/[a-z0-9-]+\.jpg$#', $relative)) {
        @unlink(product_upload_path(basename($relative)));
        @unlink(product_upload_path(basename(product_image_small($relative))));
    }
}

function slugify(string $text): string
{
    $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    return substr($slug, 0, 100) ?: 'produk';
}
