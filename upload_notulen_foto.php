<?php
/**
 * Unggah / hapus / ubah keterangan FOTO BUKTI KEGIATAN pada sebuah notulen rapat.
 * Sama seperti aplikasi e-GURU: maksimal NOTULEN_FOTO_MAKS (2) foto per notulen, batas ditegakkan
 * di SERVER. Berkas diteruskan ke media proxy platform dari sisi server (token tidak pernah ke
 * peramban); yang disimpan hanya URL CDN hasil respons.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

if (empty($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}
csrf_wajib();

/** Parameter dari POST maupun GET (jalur raw body/fetch memakai query string). */
function nf_param(string $nama, string $bawaan = ''): string
{
    if (isset($_POST[$nama]) && is_scalar($_POST[$nama])) { return (string) $_POST[$nama]; }
    if (isset($_GET[$nama]) && is_scalar($_GET[$nama])) { return (string) $_GET[$nama]; }
    return $bawaan;
}

$notulenId = (int) nf_param('notulen_id');
$n = notulen_by_id($notulenId);
$kembali = 'notulen_form.php?id=' . $notulenId . '#cardFotoNotulen';
if (!$n) {
    flash('Notulen tidak ditemukan.', 'err');
    redirect('notulen.php');
}

$aksi = nf_param('aksi');

// ---------------- hapus satu foto ----------------
if ($aksi === 'hapus') {
    $fotoId = (int) nf_param('id');
    $f = $fotoId > 0 ? satu('SELECT * FROM notulen_foto WHERE id = ? AND notulen_id = ?', [$fotoId, $notulenId]) : null;
    if (!$f) {
        flash('Foto tidak ditemukan pada notulen ini.', 'err');
    } else {
        q('DELETE FROM notulen_foto WHERE id = ? AND notulen_id = ?', [$fotoId, $notulenId]);
        notulen_foto_urutkan_ulang($notulenId);
        log_catat('hapus_foto_notulen', 'notulen ' . $notulenId);
        flash('Foto bukti kegiatan dihapus.', 'warning');
    }
    redirect($kembali);
}

// ---------------- ubah keterangan foto ----------------
if ($aksi === 'keterangan') {
    $fotoId = (int) nf_param('id');
    $ket = potong(nf_param('keterangan'), 160);
    $f = $fotoId > 0 ? satu('SELECT * FROM notulen_foto WHERE id = ? AND notulen_id = ?', [$fotoId, $notulenId]) : null;
    if (!$f) {
        flash('Foto tidak ditemukan pada notulen ini.', 'err');
    } else {
        q('UPDATE notulen_foto SET keterangan = ? WHERE id = ? AND notulen_id = ?', [$ket, $fotoId, $notulenId]);
        flash('Keterangan foto diperbarui.');
    }
    redirect($kembali);
}

if ($aksi !== 'unggah') {
    redirect($kembali);
}

// ---------------- unggah foto baru ----------------
if (notulen_sisa_slot_foto($notulenId) <= 0) {
    flash('Foto bukti kegiatan sudah ' . NOTULEN_FOTO_MAKS . ' buah (maksimal). Hapus salah satu dulu untuk menggantinya.', 'err');
    redirect($kembali);
}

$keterangan = potong(nf_param('keterangan'), 160);

$up = unggah_berkas('foto', FOTO_EKSTENSI, FOTO_MAKS_BYTE);
if (empty($up['ok'])) {
    if (!empty($up['kosong'])) {
        flash('Tidak ada berkas foto yang dikirim. Pilih foto terlebih dahulu.', 'err');
    } else {
        flash((string) ($up['pesan'] ?? 'Foto gagal diunggah.'), 'err');
    }
    redirect($kembali);
}

// Cek ulang slot SEBELUM menyimpan (menutup celah dua permintaan hampir bersamaan).
if (notulen_sisa_slot_foto($notulenId) <= 0) {
    flash('Foto bukti kegiatan sudah ' . NOTULEN_FOTO_MAKS . ' buah (maksimal).', 'err');
    redirect($kembali);
}

q('INSERT INTO notulen_foto (notulen_id, url, keterangan, urutan, dibuat, dibuat_oleh) VALUES (?,?,?,?,?,?)',
    [$notulenId, (string) $up['url'], $keterangan, notulen_jumlah_foto($notulenId),
     date('Y-m-d H:i:s'), (int) (current_user()['id'] ?? 0)]);
log_catat('foto_notulen', 'notulen ' . $notulenId . ' (' . (string) ($up['nama'] ?? '') . ')');
flash('Foto bukti kegiatan berhasil diunggah (' . notulen_jumlah_foto($notulenId) . ' dari ' . NOTULEN_FOTO_MAKS . ' foto).');
redirect($kembali);
