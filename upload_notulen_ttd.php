<?php
/**
 * Handler TANDA TANGAN DIGITAL notulen (layar sentuh) — TUSmart v1.7.0 / skema v7.
 *
 * Menerima POST dari dialog tanda tangan pada lembar Daftar Hadir Rapat:
 *   aksi=simpan (bawaan) + id (notulen) + pihak + gambar (data URL PNG hasil <canvas>)
 *   aksi=hapus  + id + pihak
 *
 * Alur: periksa login → periksa CSRF → periksa pihak SAH untuk notulen itu (anti-IDOR) →
 * dekode PNG → `notulen_ttd_simpan()` (unggah ke media proxy, simpan URL-nya saja).
 * Balasan JSON bila permintaan JSON/AJAX; selain itu flash + pengalihan kembali ke lembar
 * Daftar Hadir (pola yang sama dengan `upload_notulen_foto.php`).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/notulen_ttd.php';
require_login();

$inginJson = (f('format') === 'json')
    || (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json'));

/** Mengirim balasan lalu berhenti. */
function ttd_jawab(bool $ok, string $pesan, array $tambahan = [], int $kode = 0, bool $json = false): void
{
    // Kode status HANYA untuk balasan JSON; pada jalur formulir biasa pengguna selalu
    // dialihkan kembali ke lembar Daftar Hadir dengan pesan (mengikuti penangan foto notulen).
    if ($json && $kode > 0) { http_response_code($kode); }
    if ($json) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
        echo json_encode(array_merge(['ok' => $ok, 'pesan' => $pesan], $tambahan),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    flash($pesan, $ok ? 'ok' : 'err');
    $id = idf('id');
    redirect($id > 0 ? 'cetak_notulen.php?id=' . $id . '&bagian=hadir' : 'notulen.php');
}

/** Mengalihkan/menjawab saat permintaan bukan POST. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($inginJson) { ttd_jawab(false, 'Permintaan harus dikirim dengan metode POST.', [], 405, true); }
    flash('Permintaan tanda tangan tidak dikenal.', 'err');
    redirect('notulen.php');
}

// Token juga diterima lewat header (jalur fetch non-form).
if (empty($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}
// CSRF diperiksa lebih dahulu: 419 untuk klien AJAX (JSON), halaman 419 untuk formulir biasa
// (pola yang sama dengan penangan foto notulen).
if (!csrf_valid()) {
    if ($inginJson) {
        ttd_jawab(false, 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            ['kedaluwarsa' => true], 419, true);
    }
    csrf_wajib();
}

$notulenId = idf('id') > 0 ? idf('id') : idf('notulen_id');
$notulen = notulen_by_id($notulenId);
if (!$notulen) {
    ttd_jawab(false, 'Notulen tidak ditemukan.', [], 404, $inginJson);
}

$peserta = notulen_peserta($notulenId);
$daftarPihak = notulen_ttd_pihak($notulen, $peserta);
$pihak = trim((string) f('pihak'));
if ($pihak === '' || !array_key_exists($pihak, $daftarPihak)) {
    ttd_jawab(false, 'Pihak penanda tangan tidak dikenal pada notulen ini.', [], 400, $inginJson);
}

$aksi = aksi_post() !== '' ? aksi_post() : 'simpan';

if ($aksi === 'hapus') {
    $ada = notulen_ttd_hapus($notulenId, $pihak);
    if (!$ada) {
        ttd_jawab(false, 'Belum ada tanda tangan tersimpan untuk ' . $daftarPihak[$pihak]['label'] . '.', [], 404, $inginJson);
    }
    log_catat('hapus_ttd_notulen', 'Notulen #' . $notulenId . ' · ' . $daftarPihak[$pihak]['label']);
    ttd_jawab(true, 'Tanda tangan ' . $daftarPihak[$pihak]['label'] . ' dihapus.', ['pihak' => $pihak], 0, $inginJson);
}

if ($aksi !== 'simpan') {
    ttd_jawab(false, 'Aksi tanda tangan tidak dikenal.', [], 400, $inginJson);
}

// ------- data URL PNG dari kanvas -------
$data = trim((string) f('gambar'));
if ($data === '') {
    ttd_jawab(false, 'Tanda tangan masih kosong — coretkan tanda tangan terlebih dahulu.', [], 400, $inginJson);
}
if (strlen($data) > 6 * 1024 * 1024) {
    ttd_jawab(false, 'Gambar tanda tangan terlalu besar.', [], 400, $inginJson);
}
$b64 = $data;
if (str_starts_with($data, 'data:')) {
    $koma = strpos($data, ',');
    if ($koma === false) {
        ttd_jawab(false, 'Format gambar tanda tangan tidak dikenal.', [], 400, $inginJson);
    }
    $kepala = substr($data, 0, $koma);
    if (stripos($kepala, 'image/png') === false) {
        ttd_jawab(false, 'Gambar tanda tangan harus berformat PNG.', [], 400, $inginJson);
    }
    $b64 = substr($data, $koma + 1);
}
$b64 = preg_replace('/\s+/', '', $b64) ?? '';
$bytes = base64_decode($b64, true);
if ($bytes === false || $bytes === '') {
    ttd_jawab(false, 'Gambar tanda tangan tidak dapat dibaca.', [], 400, $inginJson);
}

$hasil = notulen_ttd_simpan($notulenId, $pihak, (string) $bytes);
if (empty($hasil['ok'])) {
    ttd_jawab(false, (string) ($hasil['pesan'] ?? 'Tanda tangan gagal disimpan.'), [], 400, $inginJson);
}
log_catat('ttd_notulen', 'Notulen #' . $notulenId . ' · ' . $daftarPihak[$pihak]['label']);
ttd_jawab(true, 'Tanda tangan ' . $daftarPihak[$pihak]['label'] . ' tersimpan.',
    ['pihak' => $pihak, 'url' => (string) ($hasil['url'] ?? '')], 0, $inginJson);
