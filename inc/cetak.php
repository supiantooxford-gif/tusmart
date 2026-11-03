<?php
/**
 * Bantuan lembar cetak (A4) + ekspor CSV.
 * Setiap halaman cetak memuat `inc/init.php` lalu memanggil cetak_mulai()/cetak_selesai().
 */
declare(strict_types=1);

/** Baris identitas kop yang tidak kosong / bukan tanda hubung. */
function kop_nilai(?string $v): string
{
    $v = trim((string) $v);
    return in_array($v, ['', '-', '–', '—', '.', '_'], true) ? '' : $v;
}

function kop_baris(): array
{
    $baris = [];
    $baris[] = setting('alamat');
    $wilayah = [];
    if (kop_nilai(setting('desa')) !== '') { $wilayah[] = 'Desa/Kelurahan ' . setting('desa'); }
    if (kop_nilai(setting('kecamatan')) !== '') { $wilayah[] = 'Kec. ' . setting('kecamatan'); }
    if (kop_nilai(setting('kabupaten')) !== '') { $wilayah[] = kop_nilai(setting('kabupaten')); }
    if (kop_nilai(setting('provinsi')) !== '') { $wilayah[] = setting('provinsi'); }
    if ($wilayah) { $baris[] = implode(', ', $wilayah); }
    $kontak = [];
    if (kop_nilai(setting('email')) !== '') { $kontak[] = 'email: ' . setting('email'); }
    if (kop_nilai(setting('website')) !== '') { $kontak[] = 'website: ' . setting('website'); }
    if ($kontak) { $baris[] = implode(', ', $kontak); }
    $kode = [];
    if (kop_nilai(setting('npsn')) !== '') { $kode[] = 'NPSN: ' . setting('npsn'); }
    if (kop_nilai(setting('nsm')) !== '') { $kode[] = 'NSM: ' . setting('nsm'); }
    if (kop_nilai(setting('telepon')) !== '') { $kode[] = 'HP: ' . setting('telepon'); }
    if ($kode) { $baris[] = implode(', ', $kode); }
    return array_values(array_filter($baris, static fn($b) => kop_nilai($b) !== ''));
}

/**
 * @param array $meta pasangan label => nilai (ditampilkan sebagai baris identitas lembar)
 */
function cetak_mulai(string $judul, string $ukuran = 'A4 portrait', array $meta = [], string $catatan = ''): void
{
    $logo = setting('logo');
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
@page { size: <?= e($ukuran) ?>; margin: 12mm 12mm 12mm 12mm; }
</style>
</head>
<body class="cetak"<?= isset($_GET['auto']) ? ' data-auto-cetak="1"' : '' ?>>
<div class="lembar">
  <div class="sheet-head<?= $logo !== '' ? ' kop-logo' : '' ?>">
    <?php if ($logo !== ''): ?><img class="kop-gambar" src="<?= e($logo) ?>" alt="Logo"><?php endif; ?>
    <div class="kop-teks">
      <div class="kop-nama"><?= e(lembaga_nama()) ?></div>
      <?php foreach (kop_baris() as $b): ?><div class="kop-baris"><?= e($b) ?></div><?php endforeach; ?>
    </div>
  </div>
  <hr class="kop-garis">
  <h2 class="sheet-title"><?= e($judul) ?></h2>
  <?php if ($meta): ?>
    <table class="sheet-meta">
      <?php foreach ($meta as $label => $nilai): ?>
        <tr><td class="lbl"><?= e($label) ?></td><td class="titik">:</td><td><?= e((string) $nilai) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($catatan !== ''): ?><p class="ket-cetak"><?= e($catatan) ?></p><?php endif; ?>
<?php
}

/**
 * Teks baris "tempat, tanggal" (tanpa HTML) untuk blok tanda tangan.
 */
function ttd_tempat_teks(): string
{
    $kec = trim(setting('kecamatan'));
    return ($kec !== '' ? $kec . ', ' : '') . tanggal_id(tanggal_hari_ini());
}

/* ---------------- TANDA TANGAN KEPALA (gambar hasil unggahan) ----------------
 * Diunggah & diaktifkan pada Pengaturan Data Lembaga. Bila AKTIF, gambarnya dicetak pada
 * SEMUA blok tanda tangan Kepala Sekolah/Madrasah di seluruh dokumen; bila tidak diaktifkan
 * (atau gambarnya dihapus) blok tanda tangan tetap kosong seperti sebelumnya.
 * ------------------------------------------------------------------------- */

/** URL gambar tanda tangan Kepala ('' bila belum diunggah). */
function ttd_kepala_url(): string
{
    return trim(setting('ttd_kepala'));
}

/** Tanda tangan Kepala dipakai pada dokumen? (gambar ada DAN dicentang "diaktifkan"). */
function ttd_kepala_pakai(): bool
{
    return setting('ttd_kepala_aktif', '0') === '1' && ttd_kepala_url() !== '';
}

/** <img> tanda tangan Kepala ('' bila tidak dipakai). $kelas mengatur ukuran per lembar. */
function ttd_kepala_img(string $kelas = 'ttd-gambar'): string
{
    if (!ttd_kepala_pakai()) { return ''; }
    return '<img class="' . e($kelas) . '" src="' . e(ttd_kepala_url())
        . '" alt="Tanda tangan Kepala ' . e(istilah_lembaga()) . '">';
}

/** Blok tanda tangan ini milik KEPALA? (label ber-"Kepala" dan bukan "Kepala Tata Usaha"). */
function ttd_blok_kepala($label): bool
{
    $label = is_array($label) ? implode(' ', array_map('strval', $label)) : (string) $label;
    $l = strtolower($label);
    return str_contains($l, 'kepala') && !str_contains($l, 'tata usaha');
}

/**
 * @param array $ttd daftar blok tanda tangan:
 *        ['label' => string|string[] , 'nama' => …, 'keterangan' => [baris…], 'tempat' => bool]
 *        'label' boleh berupa array supaya jabatan tercetak DUA BARIS (mis.
 *        ['Mengetahui,', 'Kepala Madrasah']) — jangan menulis "<br>" karena akan di-escape.
 *        'tempat' => true mencetak baris "Kecamatan, tanggal" DI ATAS label blok itu
 *        (dipakai pada blok pihak pelaksana / Kepala Tata Usaha).
 */
function cetak_selesai(array $ttd = [], string $kaki = ''): void
{
    $dua = count($ttd) > 1;
    // Lembar yang hanya punya SATU tanda tangan: baris "tempat, tanggal" tetap dicetak
    // di atas blok itu (tidak ada blok Kepala Tata Usaha untuk menampungnya).
    if (count($ttd) === 1 && empty($ttd[0]['tempat'])) {
        $ttd[0]['tempat'] = true;
    }
    // Jumlah baris SEBELUM nama dihitung agar NAMA kedua pihak tercetak SEJAJAR:
    // blok yang barisnya lebih sedikit diberi baris kosong penyeimbang.
    $pra = [];
    foreach ($ttd as $i => $t) {
        $label = $t['label'] ?? [];
        if (!is_array($label)) { $label = [$label]; }
        $pra[$i] = count($label) + (!empty($t['tempat']) ? 1 : 0);
    }
    $maks = $pra ? max($pra) : 0;
    ?>
  <?php if ($ttd): ?>
    <div class="sheet-foot<?= $dua ? ' dua-ttd' : ' kanan' ?>">
      <?php foreach ($ttd as $i => $t): ?>
        <?php
        $label = $t['label'] ?? [];
        if (!is_array($label)) { $label = [$label]; }
        $label = array_values(array_filter(array_map('strval', $label), static fn($v) => trim($v) !== ''));
        // Gambar tanda tangan Kepala hanya pada blok tanda tangan Kepala (bukan Kepala TU/guru).
        $tangan = ttd_blok_kepala($label) ? ttd_kepala_img() : '';
        ?>
        <div class="sheet-sign<?= $dua && $i === 0 ? ' sheet-sign-kiri' : ' sheet-sign-kanan' ?>">
          <?php if (!empty($t['tempat'])): ?>
            <div class="ttd-tempat"><?= e(ttd_tempat_teks()) ?></div>
          <?php endif; ?>
          <?php for ($p = 0; $p < $maks - ($pra[$i] ?? 0); $p++): ?>
            <div class="ttd-tempat ttd-kosong">&nbsp;</div>
          <?php endfor; ?>
          <?php foreach ($label as $baris): ?>
            <div class="ttd-label"><?= e($baris) ?></div>
          <?php endforeach; ?>
          <div class="ttd-space"><?= $tangan ?></div>
          <div class="ttd-nama"><?= e($t['nama'] ?? '') ?></div>
          <?php foreach (($t['keterangan'] ?? []) as $k): ?>
            <?php if (trim((string) $k) !== ''): ?><div class="ttd-ket"><?= e((string) $k) ?></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="sheet-cetak-kaki"><?= e($kaki !== '' ? $kaki : 'Dicetak dari ' . APP_NAME . ' v' . APP_VERSION . ' pada ' . tanggal_id(tanggal_hari_ini()) . ' pukul ' . date('H:i')) ?> · <?= e(APP_NAME) ?></div>
</div>
</body>
</html>
<?php
}

/** Tombol "Cetak" kecil untuk toolbar halaman layar. */
function tombol_cetak(string $url, string $label = 'Cetak'): string
{
    return '<a class="btn btn-garis" href="' . e($url) . '" target="_blank" rel="noopener">' . ikon('cetak') . ' ' . e($label) . '</a>';
}

/** Mengirim CSV (pemisah ';' + BOM agar terbaca Excel) dan menghentikan skrip. */
function csv_kirim(string $namaFile, array $header, array $baris): void
{
    $namaFile = preg_replace('/[^A-Za-z0-9._-]+/', '-', $namaFile) ?: 'ekspor.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($baris as $b) {
        fputcsv($out, array_map(static fn($v) => (string) $v, $b), ';');
    }
    fclose($out);
    exit;
}

/** Tanggal ISO untuk berkas ekspor; nilai kosong / bukan tanggal → ''. */
function ekspor_tgl(?string $ymd): string
{
    return tanggal_sah($ymd) ? (string) $ymd : '';
}
