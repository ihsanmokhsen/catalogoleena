<?php
declare(strict_types=1);

// Voucher diskon. Potongan hanya dari subtotal produk (ongkir dan kode unik tidak ikut dipotong).
// Pemakaian dihitung dari pesanan yang masih berlaku (bukan dibatalkan/kedaluwarsa), jadi voucher
// dari pesanan yang tidak jadi dibayar otomatis bisa dipakai lagi.

const VOUCHER_CODE_PATTERN = '/^[A-Z0-9-]{3,30}$/';

function voucher_normalize_code(mixed $code): string
{
    return is_string($code) ? strtoupper(trim($code)) : '';
}

function voucher_money(int $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

function voucher_date(string $date): string
{
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return $day . ' ' . $months[$month - 1] . ' ' . $year;
}

function voucher_find(string $code, bool $forUpdate = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM vouchers WHERE code = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function voucher_usage(string $code, ?string $phone = null): int
{
    $sql = "SELECT COUNT(*) FROM orders WHERE voucher_code = ? AND status NOT IN ('cancelled', 'expired')";
    $params = [$code];
    if ($phone !== null) {
        $sql .= ' AND customer_phone = ?';
        $params[] = $phone;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function voucher_label(array $voucher): string
{
    if ($voucher['type'] === 'percent') {
        $label = 'Diskon ' . (int) $voucher['value'] . '%';
        return (int) $voucher['max_discount'] > 0 ? $label . ' (maks ' . voucher_money((int) $voucher['max_discount']) . ')' : $label;
    }
    return 'Potongan ' . voucher_money((int) $voucher['value']);
}

function voucher_discount(array $voucher, int $subtotal): int
{
    if ($voucher['type'] === 'percent') {
        $discount = intdiv($subtotal * (int) $voucher['value'], 100);
        if ((int) $voucher['max_discount'] > 0) {
            $discount = min($discount, (int) $voucher['max_discount']);
        }
    } else {
        $discount = (int) $voucher['value'];
    }
    return max(0, min($discount, $subtotal));
}

/**
 * Cek voucher untuk subtotal ini. Pesan InvalidArgumentException ditampilkan apa adanya ke pembeli.
 * $phone diisi bila nomor pembeli sudah diketahui (untuk voucher sekali per pembeli).
 * $lock dipakai di dalam transaksi pembuatan pesanan supaya kuota tidak terlewati oleh pesanan bersamaan.
 *
 * @return array{code: string, label: string, description: string, discount: int, type: string, value: int, minSubtotal: int, maxDiscount: int}
 */
function voucher_apply(mixed $rawCode, int $subtotal, ?string $phone = null, bool $lock = false): array
{
    $code = voucher_normalize_code($rawCode);
    $voucher = preg_match(VOUCHER_CODE_PATTERN, $code) ? voucher_find($code, $lock) : null;
    if ($voucher === null || !$voucher['is_active']) {
        throw new InvalidArgumentException('Kode voucher "' . mb_substr($code, 0, 30) . '" tidak ditemukan. Periksa lagi penulisannya.');
    }

    $today = date('Y-m-d');
    if ($voucher['starts_at'] !== null && $today < $voucher['starts_at']) {
        throw new InvalidArgumentException("Voucher $code baru bisa dipakai mulai " . voucher_date($voucher['starts_at']) . '.');
    }
    if ($voucher['ends_at'] !== null && $today > $voucher['ends_at']) {
        throw new InvalidArgumentException("Voucher $code sudah berakhir pada " . voucher_date($voucher['ends_at']) . '.');
    }
    $minSubtotal = (int) $voucher['min_subtotal'];
    if ($subtotal < $minSubtotal) {
        throw new InvalidArgumentException(sprintf(
            'Voucher %s berlaku untuk belanja minimal %s. Belanja Anda %s, kurang %s lagi.',
            $code,
            voucher_money($minSubtotal),
            voucher_money($subtotal),
            voucher_money($minSubtotal - $subtotal)
        ));
    }
    if ($voucher['usage_limit'] !== null && voucher_usage($code) >= (int) $voucher['usage_limit']) {
        throw new InvalidArgumentException("Maaf, kuota voucher $code sudah habis.");
    }
    if ($voucher['once_per_customer'] && $phone !== null && voucher_usage($code, $phone) > 0) {
        throw new InvalidArgumentException("Voucher $code hanya bisa dipakai sekali per nomor WhatsApp, dan nomor ini sudah memakainya.");
    }

    return [
        'code' => $code,
        'label' => voucher_label($voucher),
        'description' => (string) ($voucher['description'] ?? ''),
        'discount' => voucher_discount($voucher, $subtotal),
        // Aturan dikirim ke browser supaya potongan bisa dihitung ulang saat isi keranjang berubah.
        // Server tetap menghitung ulang saat pesanan dibuat.
        'type' => $voucher['type'],
        'value' => (int) $voucher['value'],
        'minSubtotal' => $minSubtotal,
        'maxDiscount' => (int) $voucher['max_discount'],
    ];
}
