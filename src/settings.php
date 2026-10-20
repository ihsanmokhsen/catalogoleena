<?php
declare(strict_types=1);

// Pengaturan toko yang diubah dari Admin > Pengaturan (tabel settings).

const SETTING_DEFAULTS = [
    // 'manual' = QR/transfer milik toko + konfirmasi WhatsApp; 'midtrans' = pembayaran otomatis Midtrans.
    'payment_mode' => 'manual',
    'admin_whatsapp' => '6281236773427',
    'payment_qr' => '',
    'bank_name' => '',
    'bank_account' => '',
    'bank_holder' => '',
    'payment_note' => '',
];
const SETTINGS_UPLOAD_DIR = 'uploads/settings';

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $saved = array_column(db()->query('SELECT name, value FROM settings')->fetchAll(), 'value', 'name');
        $cache = array_merge(SETTING_DEFAULTS, array_intersect_key($saved, SETTING_DEFAULTS));
    }
    return $cache;
}

function setting(string $name): string
{
    return (string) (settings_all()[$name] ?? '');
}

function settings_save(array $values): void
{
    $stmt = db()->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
    foreach (array_intersect_key($values, SETTING_DEFAULTS) as $name => $value) {
        $stmt->execute([$name, (string) $value]);
    }
}

function payment_mode(): string
{
    return setting('payment_mode') === 'midtrans' ? 'midtrans' : 'manual';
}

// Info pembayaran manual yang ditampilkan ke pembeli (tanpa data rahasia).
function manual_payment_info(): array
{
    $settings = settings_all();
    return [
        'qr' => $settings['payment_qr'],
        'bankName' => $settings['bank_name'],
        'bankAccount' => $settings['bank_account'],
        'bankHolder' => $settings['bank_holder'],
        'note' => $settings['payment_note'],
        'whatsapp' => $settings['admin_whatsapp'],
    ];
}

/**
 * Simpan gambar QR pembayaran. QR dibuat ulang sebagai PNG (lossless) tanpa penajaman,
 * dan hanya diperkecil bila sangat besar, supaya tetap bisa di-scan.
 */
function payment_qr_store(array $file): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Pilih gambar QR.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Upload QR gagal (maksimal 5 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];
    if (!isset($loaders[$mime])) {
        throw new InvalidArgumentException('Gambar QR harus JPG, PNG, atau WebP.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] * $info[1] > 16_500_000) {
        throw new InvalidArgumentException('Gambar QR tidak valid atau terlalu besar.');
    }
    if (min($info[0], $info[1]) < 200) {
        throw new InvalidArgumentException('Gambar QR terlalu kecil (minimal 200 px) dan bisa gagal di-scan.');
    }
    $source = @$loaders[$mime]($file['tmp_name']);
    if (!$source) {
        throw new InvalidArgumentException('Gambar QR rusak atau tidak bisa dibaca.');
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, 1600 / max($width, $height));
    $targetW = (int) round($width * $scale);
    $targetH = (int) round($height * $scale);
    $target = imagecreatetruecolor($targetW, $targetH);
    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

    $dir = __DIR__ . '/../public/' . SETTINGS_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Folder upload tidak bisa dibuat: $dir");
    }
    $name = 'qr-' . bin2hex(random_bytes(6)) . '.png';
    if (!imagepng($target, "$dir/$name", 6)) {
        throw new RuntimeException('Gagal menyimpan gambar QR.');
    }
    chmod("$dir/$name", 0644);
    return SETTINGS_UPLOAD_DIR . '/' . $name;
}

function payment_qr_delete(?string $path): void
{
    if ($path && preg_match('#^' . SETTINGS_UPLOAD_DIR . '/qr-[0-9a-f]+\.png$#', $path)) {
        @unlink(__DIR__ . '/../public/' . $path);
    }
}
