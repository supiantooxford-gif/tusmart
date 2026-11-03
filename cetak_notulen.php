<?php
/**
 * Lembar cetak Notulen Rapat — EMPAT bagian terpisah (sama seperti aplikasi e-GURU):
 *   ?bagian=berita → Berita Acara Rapat (A4 tegak)   ttd: Pimpinan Rapat (kiri) & Notulis (kanan)
 *   ?bagian=hadir  → Daftar Hadir Rapat (A4 tegak)   ttd: Kepala (kanan)
 *   ?bagian=isi    → Isi Notulen Rapat  (A4 tegak, BENTUK TABEL) ttd: Kepala (kiri) & Notulen (kanan)
 *   ?bagian=foto   → Foto Dokumentasi Kegiatan (A4 melintang, seluruh foto 1 lembar) ttd: Kepala (kanan)
 *
 * Tanda tangan mengikuti aturan aplikasi: label "Mengetahui," + jabatan dua baris, baris
 * "tempat, tanggal" di atas blok pihak kedua, dan nama kedua pihak tercetak sejajar.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/notulen_ttd.php';   // tanda tangan digital (layar sentuh)
require_login();

$id = idf('id');
$n = notulen_by_id($id);
if (!$n) {
    flash('Notulen tidak ditemukan.', 'err');
    redirect('notulen.php');
}

$bagianDiminta = f('bagian', 'berita');
$bagian = in_array($bagianDiminta, ['hadir', 'isi', 'foto'], true) ? $bagianDiminta : 'berita';
$judulBagian = [
    'berita' => 'Berita Acara Rapat',
    'hadir'  => 'Daftar Hadir Rapat',
    'isi'    => 'Isi Notulen Rapat',
    'foto'   => 'Foto Dokumentasi Kegiatan Rapat',
][$bagian];

$peserta = notulen_peserta($id);
$jmlHadir = count($peserta);
$fotoDok = notulen_foto($id);
if ($bagian === 'foto' && !$fotoDok) {
    flash('Belum ada foto bukti kegiatan pada notulen ini, jadi lembar foto dokumentasi belum dapat dicetak.', 'warning');
    redirect('notulen_form.php?id=' . $id . '#cardFotoNotulen');
}

$tanggal = (string) $n['tanggal'];
$hari = nama_hari();
$tanggalPanjang = ($hari[(int) date('N', strtotime($tanggal))] ?? '') . ', ' . tanggal_id($tanggal);
$fotoUk = foto_cetak_ukuran();
$logo = setting('logo');

// Paragraf pembuka berita acara.
$pembuka = 'Pada hari ini ' . $tanggalPanjang . ',';
if (trim((string) $n['waktu']) !== '') { $pembuka .= ' pukul ' . trim((string) $n['waktu']) . ','; }
$pembuka .= ' bertempat di ' . (trim((string) $n['tempat']) !== '' ? trim((string) $n['tempat']) : '-')
    . ', telah dilaksanakan rapat ' . istilah_lembaga() . ' ' . lembaga_nama()
    . ' dengan topik “' . trim((string) $n['judul']) . '”, dihadiri oleh ' . $jmlHadir
    . ' guru (daftar hadir terlampir).';

/** Mencetak satu bagian berjudul (baris kosong dilewati). */
function not_bagian(string $judul, string $isi): void
{
    $isi = trim($isi);
    if ($isi === '') { return; }
    echo '<div class="not-bagian"><h3>' . e($judul) . '</h3>';
    foreach (preg_split('/\r\n|\r|\n/', $isi) ?: [] as $baris) {
        $baris = trim($baris);
        if ($baris !== '') { echo '<p>' . e($baris) . '</p>'; }
    }
    echo '</div>';
}

/**
 * Blok tanda tangan satu pihak.
 *
 * $o = [
 *   'nama'       => string,
 *   'label'      => string[]   label jabatan (boleh dua baris: "Mengetahui," + "Kepala Madrasah"),
 *   'tempat'     => bool       cetak baris "tempat, tanggal" DI ATAS label,
 *   'penyeimbang'=> bool       cetak baris KOSONG setinggi baris tempat/tanggal,
 *   'keterangan' => string[]   mis. "NIP. …",
 *   'pihak'      => string     kunci tanda tangan ('' = tidak dapat ditandatangani),
 *   'gambar'     => string     HTML <img> tanda tangan,
 *   'filler'     => int        jumlah baris kosong TAMBAHAN (untuk menyelaraskan nama),
 * ]
 */
function not_ttd(array $o): string
{
    $nama = (string) ($o['nama'] ?? '');
    $label = (array) ($o['label'] ?? []);
    $tempat = !empty($o['tempat']);
    $penyeimbang = !empty($o['penyeimbang']);
    $keterangan = (array) ($o['keterangan'] ?? []);
    $pihak = (string) ($o['pihak'] ?? '');
    $gambar = (string) ($o['gambar'] ?? '');
    $gambarDigital = $gambar !== '';
    // Blok tanda tangan KEPALA: bila belum ditandatangani secara digital di layar sentuh,
    // pakai gambar tanda tangan Kepala hasil unggahan (Pengaturan Data Lembaga) bila diaktifkan.
    if ($gambar === '' && $pihak === 'kepala') { $gambar = ttd_kepala_img('ttd-img'); }
    $filler = max(0, (int) ($o['filler'] ?? 0));

    $out = '<div class="not-ttd">';
    // Slot atas SELALU satu baris (tempat/tanggal atau baris kosong) supaya kedua pihak mulai
    // dari ketinggian yang sama.
    if ($tempat) {
        $out .= '<div class="not-ttd-tempat">' . e(ttd_tempat_teks()) . '</div>';
    } else {
        // Baris kosong setinggi baris "tempat, tanggal" agar NAMA kedua pihak tercetak SEJAJAR.
        $out .= '<div class="not-ttd-tempat not-ttd-kosong" aria-hidden="true">&nbsp;</div>';
    }
    foreach ($label as $l) { $out .= '<div class="not-ttd-label">' . e((string) $l) . '</div>'; }
    // Baris penyeimbang: menyamakan jumlah baris teks kedua pihak.
    for ($i = 0; $i < $filler; $i++) {
        $out .= '<div class="not-ttd-label not-ttd-kosong" aria-hidden="true">&nbsp;</div>';
    }
    // Ruang tanda tangan: tanda tangan digital (bila ada) + tombol pembuka (hanya layar sentuh).
    $out .= '<div class="not-ttd-spasi" data-ttd-sel="' . e($pihak) . '">' . $gambar;
    if ($pihak !== '') { $out .= notulen_ttd_tombol($pihak, $gambarDigital); }
    $out .= '</div>';
    $out .= '<div class="not-ttd-nama">' . e($nama) . '</div>';
    foreach ($keterangan as $k) {
        if (trim((string) $k) !== '') { $out .= '<div class="not-ttd-ket">' . e((string) $k) . '</div>'; }
    }
    return $out . '</div>';
}

/**
 * Sepasang blok tanda tangan yang NAMA-nya dijamin SEJAJAR.
 *
 * Jumlah baris teks di atas ruang tanda tangan dihitung untuk kedua pihak (slot atas + label),
 * lalu blok yang lebih pendek diberi baris penyeimbang (`filler`) sebanyak selisihnya. Cara ini
 * menggantikan penyeimbang manual yang mudah meleset ketika label kiri dua baris
 * ("Mengetahui," + "Kepala Madrasah") sedangkan label kanan satu baris ("Notulis") — dulu nama
 * kedua pihak berbeda 17px.
 *
 * Baris "tempat, tanggal" SELALU dicetak pada blok pihak KEDUA (kanan, mis. Notulis) sesuai
 * permintaan pemilik; blok kiri memakai baris kosong setinggi itu.
 */
function not_ttd_pasangan(array $kiri, array $kanan, string $kelas = ''): string
{
    $barisKiri = 1 + count((array) ($kiri['label'] ?? [])) + max(0, (int) ($kiri['filler'] ?? 0));
    $barisKanan = 1 + count((array) ($kanan['label'] ?? [])) + max(0, (int) ($kanan['filler'] ?? 0));
    if ($barisKiri < $barisKanan) {
        $kiri['filler'] = max(0, (int) ($kiri['filler'] ?? 0)) + ($barisKanan - $barisKiri);
    } elseif ($barisKanan < $barisKiri) {
        $kanan['filler'] = max(0, (int) ($kanan['filler'] ?? 0)) + ($barisKiri - $barisKanan);
    }
    // Tempat/tanggal hanya pada blok KANAN; blok kiri memakai baris kosong penyeimbang.
    $kiri['tempat'] = false;
    $kiri['penyeimbang'] = true;
    $kanan['tempat'] = true;
    $kanan['penyeimbang'] = false;
    return '<div class="not-kaki-ttd' . ($kelas !== '' ? ' ' . $kelas : '') . '">'
        . not_ttd($kiri) . not_ttd($kanan) . '</div>';
}

$kepalaBlok = ['Mengetahui,', 'Kepala ' . istilah_lembaga()];
$nipKepala = nip_kepala();

// ---------- TANDA TANGAN DIGITAL (layar sentuh) ----------
// Pihak yang boleh menandatangani: Kepala, Pimpinan Rapat, Notulis, dan SETIAP nama pada daftar
// hadir. Tombol tanda tangan hanya ditampilkan oleh skrip bila perangkat berlayar sentuh.
$ttdPihak = notulen_ttd_pihak($n, $peserta);
$ttdPeta = notulen_ttd_peta($id);
$ttdJumlah = count($ttdPeta);
$adaSentuh = true;   // penentu akhir ada di peramban (navigator.maxTouchPoints)
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judulBagian) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
@page { size: A4 <?= $bagian === 'foto' ? 'landscape' : 'portrait' ?>; margin: <?= $bagian === 'foto' ? '10mm' : '13mm' ?>; }
body.cetak { background: #eef2f8; }
.lembar { max-width: <?= $bagian === 'foto' ? '297mm' : '210mm' ?>; }
.not-toolbar { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; margin: 0 auto 14px; max-width: <?= $bagian === 'foto' ? '277mm' : '186mm' ?>; }
.not-judul { text-align: center; text-transform: uppercase; letter-spacing: .6px; font-size: 1.02rem; font-weight: 800; margin: 2px 0 2px; }
.not-nomor { text-align: center; font-size: .78rem; color: #444; margin: 0 0 12px; }
.sheet-meta { margin: 4px 0 10px; }
.not-pembuka { font-size: .86rem; line-height: 1.7; text-align: justify; margin: 0 0 10px; }
.not-bagian { margin: 0 0 10px; }
.not-bagian h3 { margin: 0 0 4px; font-size: .82rem; text-transform: uppercase; letter-spacing: .4px; color: #12356b; }
.not-bagian p { margin: 0 0 3px; font-size: .84rem; line-height: 1.6; text-align: justify; }
.not-tutup { font-size: .85rem; line-height: 1.7; text-align: justify; margin: 12px 0 0; }
.not-ket { font-size: .72rem; color: #444; margin-top: 8px; line-height: 1.5; }
.not-kaki-ttd { display: flex; justify-content: space-between; gap: 24px; margin-top: 14px; }
.not-kaki-ttd.satu { justify-content: flex-end; }
.not-ttd { text-align: center; font-size: .8rem; flex: 1 1 0; min-width: 0; }
.not-ttd-tempat, .not-ttd-label { font-size: .8rem; line-height: 1.35; }
/* Baris teks KOSONG (penyeimbang) — tetap memakan ruang supaya NAMA kedua pihak sejajar. */
.not-ttd-kosong { visibility: hidden; }
/* Ruang tanda tangan: tinggi TETAP & pemusatan flex, sehingga gambar tanda tangan tidak pernah
   mengubah tinggi blok (dulu <img> inline membuat blok kiri lebih tinggi 17px → nama meleset). */
.not-ttd-spasi {
  height: 72px; display: flex; align-items: center; justify-content: center;
  overflow: hidden; position: relative;
}
.not-ttd-spasi .ttd-img { display: block; max-height: 68px; max-width: 100%; width: auto; height: auto; }
.not-ttd-nama { font-weight: 700; text-decoration: underline; font-size: .82rem; }
.not-ttd-ket { font-size: .76rem; }
table.not-hadir th, table.not-hadir td { padding: 5px 6px; }
/* Kolom tanda tangan daftar hadir: gambar lebih besar, muat & terpusat di selnya. */
table.not-hadir td.ttd-sel { padding: 2px 5px; }
table.not-hadir td.ttd-sel .ttd-img { display: block; max-height: 30px; max-width: 100%; width: auto; height: auto; margin: 0 auto; }
table.not-isi { width: 100%; border-collapse: collapse; font-size: .8rem; table-layout: fixed; }
table.not-isi th, table.not-isi td { border: .8px solid #6b7a90; padding: 5px 7px; vertical-align: top; line-height: 1.55; }
table.not-isi thead th { background: #dce8fa; text-align: left; font-size: .72rem; text-transform: uppercase; }
table.not-isi thead th.ctr { text-align: center; }
table.not-isi td.label { font-weight: 700; color: #12356b; background: #f6f9fe; }
table.not-isi td.ctr { text-align: center; }
/* Lembar foto dokumentasi: seluruh foto muat SATU halaman A4 melintang */
.foto-dok-grid { display: grid; grid-template-columns: repeat(<?= (int) $fotoUk['kolom'] ?>, <?= e((string) $fotoUk['lebar']) ?>mm); gap: 8mm; justify-content: center; margin: 0 0 3mm; }
.foto-dok { display: flex; flex-direction: column; gap: 1.5mm; align-items: center; break-inside: avoid; }
.foto-dok-kotak { width: <?= e((string) $fotoUk['lebar']) ?>mm; height: <?= e((string) $fotoUk['tinggi']) ?>mm; border: 1px solid #9aa79f; display: grid; place-items: center; overflow: hidden; padding: 1.5mm; background: #fff; }
.foto-dok-kotak img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.foto-dok-ket { width: <?= e((string) $fotoUk['lebar']) ?>mm; text-align: center; font-size: .72rem; line-height: 1.4; }
.lembar-foto { padding: 10mm; }
.lembar-foto .kop-nama { font-size: .95rem; }
.lembar-foto .kop-baris { font-size: .68rem; }
.lembar-foto .not-judul { font-size: .95rem; margin-top: 4px; }
.lembar-foto .sheet-meta { font-size: .78rem; margin-bottom: 6px; }
.lembar-foto .not-kaki-ttd { margin-top: 5mm; }
.lembar-foto .not-ttd-spasi { height: 46px; }
@media print {
  body.cetak { background: #fff; }
  .lembar-foto { padding: 0; }
  .lembar-foto .kop-gambar { width: 42px; height: 42px; }
  .lembar-foto .sheet-head { margin-bottom: 2px; }
  .lembar-foto .kop-garis { margin: 3px 0 1px; }
  .lembar-foto .kop-garis2 { margin: 1px 0 4px; }
  .lembar-foto .not-judul { margin: 2px 0 1px; }
  .lembar-foto .not-nomor { margin: 0 0 4px; }
  .lembar-foto .sheet-meta { margin: 2px 0 4px; }
  .lembar-foto .sheet-meta td { padding: 0 4px; }
  .lembar-foto .not-ket { margin-top: 3px; }
  .lembar-foto .not-kaki-ttd { margin-top: 4mm; }
  .lembar-foto .not-ttd-spasi { height: 42px; }
  .not-toolbar, .ttd-tombol, .ttd-dialog, .ttd-catatan { display: none !important; }
  .lembar { box-shadow: none; padding: 0; max-width: none; }
  .not-kaki-ttd { flex-direction: row; flex-wrap: nowrap; break-inside: avoid; }
  table.not-hadir thead { display: table-header-group; }
}
</style>
</head>
<body class="cetak"<?= isset($_GET['auto']) ? ' data-auto-cetak="1"' : '' ?>>
<div class="not-toolbar no-print">
  <a class="btn btn-garis btn-kecil" href="notulen.php">Kembali</a>
  <a class="btn btn-kecil <?= $bagian === 'berita' ? '' : 'btn-garis' ?>" href="cetak_notulen.php?id=<?= $id ?>&bagian=berita">Berita Acara</a>
  <a class="btn btn-kecil <?= $bagian === 'hadir' ? '' : 'btn-garis' ?>" href="cetak_notulen.php?id=<?= $id ?>&bagian=hadir">Daftar Hadir</a>
  <a class="btn btn-kecil <?= $bagian === 'isi' ? '' : 'btn-garis' ?>" href="cetak_notulen.php?id=<?= $id ?>&bagian=isi">Isi Notulen</a>
  <?php if ($fotoDok): ?>
    <a class="btn btn-kecil <?= $bagian === 'foto' ? '' : 'btn-garis' ?>" href="cetak_notulen.php?id=<?= $id ?>&bagian=foto">Foto Dokumentasi</a>
  <?php endif; ?>
  <a class="btn btn-kecil btn-ok" href="cetak_notulen.php?<?= e(http_build_query(['id' => $id, 'bagian' => $bagian, 'auto' => 1])) ?>"><?= ikon('cetak') ?> Cetak / Simpan PDF</a>
</div>
<p class="ttd-catatan no-print" id="ttdCatatan" hidden>
  <?= ikon('ubah') ?> <b>Tanda tangan digital aktif.</b> Tekan tombol <i>Tanda Tangan</i> pada kolom tanda tangan
  (atau pada blok Kepala/Notulis) untuk menandatangani langsung di layar sentuh.
  Sudah ditandatangani: <b data-ttd-jumlah><?= $ttdJumlah ?></b> dari <?= count($ttdPihak) ?> pihak.
</p>

<div class="lembar<?= $bagian === 'foto' ? ' lembar-foto' : '' ?>">
  <div class="sheet-head<?= $logo !== '' ? ' kop-logo' : '' ?>">
    <?php if ($logo !== ''): ?><img class="kop-gambar" src="<?= e($logo) ?>" alt="Logo"><?php endif; ?>
    <div class="kop-teks">
      <div class="kop-nama"><?= e(lembaga_nama()) ?></div>
      <?php foreach (kop_baris() as $b): ?><div class="kop-baris"><?= e($b) ?></div><?php endforeach; ?>
    </div>
  </div>
  <hr class="kop-garis"><hr class="kop-garis2">

<?php if ($bagian === 'berita'): ?>
  <div class="not-judul">Berita Acara Rapat</div>
  <p class="not-nomor">Tanggal: <?= e(tanggal_id($tanggal, true)) ?>, Tahun Ajaran <?= e(setting('tahun_ajaran')) ?>, Semester <?= e(setting('semester')) ?></p>

  <table class="sheet-meta">
    <tr><td class="lbl">Topik Rapat</td><td class="titik">:</td><td><?= e(trim((string) $n['judul']) !== '' ? (string) $n['judul'] : '-') ?></td></tr>
    <tr><td class="lbl">Hari / Tanggal</td><td class="titik">:</td><td><?= e($tanggalPanjang) ?></td></tr>
    <tr><td class="lbl">Waktu</td><td class="titik">:</td><td><?= e(trim((string) $n['waktu']) !== '' ? (string) $n['waktu'] : '-') ?></td></tr>
    <tr><td class="lbl">Tempat</td><td class="titik">:</td><td><?= e(trim((string) $n['tempat']) !== '' ? (string) $n['tempat'] : '-') ?></td></tr>
    <tr><td class="lbl">Jumlah Peserta</td><td class="titik">:</td><td><?= $jmlHadir ?> guru</td></tr>
    <tr><td class="lbl">Pimpinan Rapat</td><td class="titik">:</td><td><?= e(trim((string) $n['pimpinan']) !== '' ? (string) $n['pimpinan'] . (trim((string) $n['pimpinan_jabatan']) !== '' ? ' (' . $n['pimpinan_jabatan'] . ')' : '') : '-') ?></td></tr>
    <tr><td class="lbl">Notulis</td><td class="titik">:</td><td><?= e(trim((string) $n['notulis']) !== '' ? (string) $n['notulis'] : '-') ?></td></tr>
  </table>

  <p class="not-pembuka"><?= e($pembuka) ?></p>
  <?php
  not_bagian('Agenda Rapat', (string) $n['agenda']);
  not_bagian('Hasil Pembahasan', (string) $n['pembahasan']);
  not_bagian('Keputusan / Kesimpulan Rapat', (string) $n['keputusan']);
  ?>
  <p class="not-tutup">Demikian berita acara rapat ini dibuat dengan sebenarnya dan disetujui bersama, untuk dipergunakan sebagaimana mestinya.</p>

  <?= not_ttd_pasangan(
      ['nama' => trim((string) $n['pimpinan']) !== '' ? (string) $n['pimpinan'] : '-',
       'label' => ['Pimpinan Rapat'], 'pihak' => 'pimpinan',
       'gambar' => notulen_ttd_gambar($ttdPeta, 'pimpinan')],
      ['nama' => trim((string) $n['notulis']) !== '' ? (string) $n['notulis'] : '-',
       'label' => ['Notulis'], 'pihak' => 'notulis',
       'gambar' => notulen_ttd_gambar($ttdPeta, 'notulis')]
  ) ?>

<?php elseif ($bagian === 'isi'): ?>
  <?php
  // Isi Notulen dalam BENTUK TABEL (No / Uraian / Keterangan) seperti e-GURU.
  $barisIsi = [];
  $teksAtau = static fn(string $v): string => trim($v) !== '' ? trim($v) : '-';
  $pimpinanIsi = $teksAtau((string) $n['pimpinan']);
  if ($pimpinanIsi !== '-' && trim((string) $n['pimpinan_jabatan']) !== '') {
      $pimpinanIsi .= ' (' . trim((string) $n['pimpinan_jabatan']) . ')';
  }
  $barisIsi[] = ['Judul / Topik Rapat', $teksAtau((string) $n['judul']), false];
  $barisIsi[] = ['Hari / Tanggal', $tanggalPanjang, false];
  $barisIsi[] = ['Waktu', $teksAtau((string) $n['waktu']), false];
  $barisIsi[] = ['Tempat', $teksAtau((string) $n['tempat']), false];
  $barisIsi[] = ['Pimpinan Rapat', $pimpinanIsi, false];
  $barisIsi[] = ['Jumlah Peserta Rapat', $jmlHadir . ' guru', false];
  $barisIsi[] = ['Notulis', $teksAtau((string) $n['notulis']), false];
  $barisIsi[] = ['Hasil Pembahasan', trim((string) $n['pembahasan']), true];
  if (trim((string) $n['agenda']) !== '') { $barisIsi[] = ['Agenda Rapat', trim((string) $n['agenda']), true]; }
  if (trim((string) $n['keputusan']) !== '') { $barisIsi[] = ['Keputusan / Kesimpulan Rapat', trim((string) $n['keputusan']), true]; }
  ?>
  <div class="not-judul">Isi Notulen Rapat</div>
  <p class="not-nomor">Tahun Ajaran <?= e(setting('tahun_ajaran')) ?> · Semester <?= e(setting('semester')) ?></p>

  <table class="not-isi">
    <thead><tr>
      <th class="ctr" style="width:32px">No</th><th style="width:30%">Uraian</th><th>Keterangan</th>
    </tr></thead>
    <tbody>
    <?php $noIsi = 0; foreach ($barisIsi as [$label, $nilaiIsi, $berbaris]): $noIsi++; $kosong = $nilaiIsi === ''; ?>
      <tr>
        <td class="ctr"><?= $noIsi ?></td>
        <td class="label"><?= e($label) ?></td>
        <td<?= $kosong ? ' class="mut"' : '' ?>><?= $kosong ? 'belum diisi' : ($berbaris ? nl2br(e($nilaiIsi)) : e($nilaiIsi)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="not-ket">Notulen ini dibuat melalui aplikasi <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada
    <?= e(tanggal_id(tanggal_hari_ini())) ?> oleh <?= e(current_user()['nama'] ?? '-') ?>.</div>

  <?= not_ttd_pasangan(
      ['nama' => kepala_lembaga(), 'label' => $kepalaBlok, 'pihak' => 'kepala',
       'keterangan' => [$nipKepala !== '' ? 'NIP. ' . $nipKepala : ''],
       'gambar' => notulen_ttd_gambar($ttdPeta, 'kepala')],
      ['nama' => trim((string) $n['notulis']) !== '' ? (string) $n['notulis'] : '-',
       'label' => ['Notulen'], 'pihak' => 'notulis',
       'gambar' => notulen_ttd_gambar($ttdPeta, 'notulis')]
  ) ?>

<?php elseif ($bagian === 'foto'): ?>
  <div class="not-judul">Foto Dokumentasi Kegiatan Rapat</div>
  <p class="not-nomor"><?= e(trim((string) $n['judul']) !== '' ? (string) $n['judul'] : '-') ?>, <?= e($tanggalPanjang) ?></p>

  <table class="sheet-meta">
    <tr><td class="lbl">Topik Rapat</td><td class="titik">:</td><td><?= e(trim((string) $n['judul']) !== '' ? (string) $n['judul'] : '-') ?></td></tr>
    <tr><td class="lbl">Hari / Tanggal</td><td class="titik">:</td><td><?= e($tanggalPanjang) ?></td></tr>
    <tr><td class="lbl">Tempat</td><td class="titik">:</td><td><?= e(trim((string) $n['tempat']) !== '' ? (string) $n['tempat'] : '-') ?></td></tr>
  </table>

  <div class="foto-dok-grid">
    <?php foreach ($fotoDok as $i => $f): ?>
      <div class="foto-dok">
        <div class="foto-dok-kotak"><img src="<?= e((string) $f['url']) ?>" alt="Foto dokumentasi kegiatan rapat <?= $i + 1 ?>"></div>
        <div class="foto-dok-ket"><b>Foto <?= $i + 1 ?></b><?= trim((string) ($f['keterangan'] ?? '')) !== '' ? ' — ' . e((string) $f['keterangan']) : '' ?>
          <div>Foto kegiatan rapat <?= e(lembaga_nama()) ?> — <?= e(tanggal_id($tanggal, true)) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="not-ket">Keterangan: foto di atas adalah bukti dokumentasi kegiatan rapat dan menjadi lampiran yang
    tidak terpisahkan dari berita acara rapat ini. Seluruh dokumentasi dicetak dalam satu lembar.</div>

  <div class="not-kaki-ttd satu">
    <?= not_ttd(['nama' => kepala_lembaga(), 'label' => $kepalaBlok, 'tempat' => true,
        'keterangan' => [$nipKepala !== '' ? 'NIP. ' . $nipKepala : ''], 'pihak' => 'kepala',
        'gambar' => notulen_ttd_gambar($ttdPeta, 'kepala')]) ?>
  </div>

<?php else: ?>
  <div class="not-judul">Daftar Hadir Rapat</div>
  <p class="not-nomor"><?= e(trim((string) $n['judul']) !== '' ? (string) $n['judul'] : '-') ?>, <?= e(tanggal_id($tanggal, true)) ?></p>

  <table class="sheet-meta">
    <tr><td class="lbl">Hari / Tanggal</td><td class="titik">:</td><td><?= e($tanggalPanjang) ?></td></tr>
    <tr><td class="lbl">Waktu</td><td class="titik">:</td><td><?= e(trim((string) $n['waktu']) !== '' ? (string) $n['waktu'] : '-') ?></td></tr>
    <tr><td class="lbl">Tempat</td><td class="titik">:</td><td><?= e(trim((string) $n['tempat']) !== '' ? (string) $n['tempat'] : '-') ?></td></tr>
    <tr><td class="lbl">Topik Rapat</td><td class="titik">:</td><td><?= e(trim((string) $n['judul']) !== '' ? (string) $n['judul'] : '-') ?></td></tr>
    <tr><td class="lbl">Jumlah Hadir</td><td class="titik">:</td><td><?= $jmlHadir ?> guru</td></tr>
  </table>

  <?php if (!$peserta): ?>
    <p class="not-ket">Belum ada guru yang tercatat menghadiri rapat ini.</p>
  <?php else: ?>
    <table class="tbl-cetak not-hadir">
      <thead><tr>
        <th class="tengah" style="width:26px">No</th><th style="width:36%">Nama Guru</th>
        <th style="width:22%">NIP / NUPTK</th><th style="width:20%">Jabatan</th>
        <th class="tengah" style="width:22%">Tanda Tangan</th>
      </tr></thead>
      <tbody>
      <?php $no = 0; foreach ($peserta as $h): $no++; ?>
        <tr>
          <td class="tengah"><?= $no ?></td>
          <td><b><?= e((string) $h['nama']) ?></b></td>
          <td><?= e(trim((string) $h['nip']) !== '' ? (string) $h['nip'] : '-') ?></td>
          <td><?= e(trim((string) $h['jabatan']) !== '' ? (string) $h['jabatan'] : '-') ?></td>
          <?php
          // Kunci pihak memakai ID guru (lihat notulen_ttd_pihak()) agar tanda tangan tetap
          // menempel walau daftar hadir notulen disunting ulang.
          $gidBaris = (int) ($h['guru_id'] ?? 0);
          $pihakBaris = $gidBaris > 0 ? 'hadir:g' . $gidBaris : 'hadir:h' . (int) $h['id'];
          ?>
          <td class="tengah ttd-sel" style="height:24px" data-ttd-sel="<?= e($pihakBaris) ?>">
            <?= notulen_ttd_gambar($ttdPeta, $pihakBaris) ?>
            <?= notulen_ttd_tombol($pihakBaris, isset($ttdPeta[$pihakBaris])) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="4" class="tengah">Jumlah Hadir</td><td class="tengah"><?= $jmlHadir ?> guru</td>
      </tr></tfoot>
    </table>
    <div class="not-ket">Keterangan: daftar hadir memuat guru yang mengikuti rapat pada tanggal tersebut.
      Kolom tanda tangan dapat diisi tangan, atau <b>tanda tangan digital</b> pada perangkat layar sentuh
      (sudah ditandatangani: <b data-ttd-jumlah><?= $ttdJumlah ?></b> pihak).</div>
  <?php endif; ?>

  <?= not_ttd_pasangan(
      ['nama' => kepala_lembaga(), 'label' => $kepalaBlok, 'pihak' => 'kepala',
       'keterangan' => [$nipKepala !== '' ? 'NIP. ' . $nipKepala : ''],
       'gambar' => notulen_ttd_gambar($ttdPeta, 'kepala')],
      ['nama' => trim((string) $n['notulis']) !== '' ? (string) $n['notulis'] : '-',
       'label' => ['Notulis'], 'pihak' => 'notulis',
       'gambar' => notulen_ttd_gambar($ttdPeta, 'notulis')]
  ) ?>
<?php endif; ?>

  <div class="sheet-cetak-kaki">Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada
    <?= e(tanggal_id(tanggal_hari_ini())) ?> pukul <?= e(date('H:i')) ?> · <?= e(APP_NAME) ?></div>
</div>

<?php if ($ttdPihak) : ?>
  <?= notulen_ttd_dialog($id) ?>
  <script type="application/json" id="ttdData"><?= notulen_ttd_skrip_json($id, $ttdPihak, $ttdPeta) ?></script>
<?php endif; ?>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
