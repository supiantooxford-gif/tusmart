<?php
/**
 * KAFER (sampul) BUKU INDUK SISWA — A4 tegak, 1 halaman.
 *
 * Susunan mengikuti contoh kafer buku induk manual: logo lembaga di tengah atas, judul
 * "BUKU INDUK PESERTA DIDIK" + bentuk lembaga + singkatan + nama lembaga + tahun ajaran,
 * lalu kotak identitas lembaga (nama, NSS/NPSN, alamat, desa/kelurahan, kecamatan,
 * kabupaten/kota, provinsi) dan baris situs/website di bagian bawah.
 *
 * Bentuk & singkatan lembaga diturunkan dari Nama Lembaga (Pengaturan Data Lembaga) dan
 * dapat ditimpa pada kartu "Kafer Buku Induk" di halaman yang sama.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$k = kafer_identitas();
$logo = setting('logo');
$web = trim(setting('website'));
$kotak = [
    'NAMA SEKOLAH'     => lembaga_nama(),
    'NSM'              => trim(setting('nsm')) !== '' ? setting('nsm') : '-',
    'ALAMAT SEKOLAH'   => trim(setting('alamat')) !== '' ? setting('alamat') : '-',
    'DESA/KELURAHAN'   => trim(setting('desa')) !== '' ? setting('desa') : '-',
    'KECAMATAN'        => trim(setting('kecamatan')) !== '' ? setting('kecamatan') : '-',
    'KABUPATEN/KOTA'   => trim(setting('kabupaten')) !== '' ? setting('kabupaten') : '-',
    'PROVINSI'         => trim(setting('provinsi')) !== '' ? setting('provinsi') : '-',
];
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kafer Buku Induk · <?= e(lembaga_nama()) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
@page { size: A4 portrait; margin: 11mm; }
body.cetak { font-size: 12px; }
.kafer-bingkai {
  box-sizing: border-box; border: 2.2px solid #111; border-radius: 3px;
  min-height: 262mm; padding: 15mm 13mm 11mm;
  display: flex; flex-direction: column; gap: 11mm;
}
.kafer-atas { text-align: center; }
.kafer-logo { display: block; margin: 0 auto 7mm; max-width: 46mm; max-height: 46mm; object-fit: contain; }
.kafer-logo-kosong {
  width: 42mm; height: 42mm; margin: 0 auto 7mm; border: 1.2px dashed #999; border-radius: 6px;
  display: flex; align-items: center; justify-content: center; font-size: .66rem; color: #777;
  text-align: center; padding: 3mm;
}
.kafer-judul { font-weight: 800; line-height: 1.3; letter-spacing: .5px; text-transform: uppercase; }
.kafer-judul .b1 { font-size: 1.38rem; }
.kafer-judul .b2 { font-size: 1.26rem; }
.kafer-judul .b3 { font-size: 1.12rem; }
.kafer-judul .b4 { font-size: 1.32rem; }
.kafer-judul .b5 { font-size: 1.2rem; margin-top: 2mm; }
.kafer-kotak {
  border: 2px solid #111; border-radius: 9px; padding: 5mm 6mm; margin-top: auto;
  display: flex; flex-direction: column; gap: 2.6mm;
}
.kafer-baris { display: flex; gap: 7px; align-items: flex-end; font-size: .88rem; line-height: 1.3; }
.kafer-baris .k { width: 44mm; flex: 0 0 auto; font-weight: 800; letter-spacing: .2px; }
.kafer-baris .t { width: 7px; flex: 0 0 auto; }
.kafer-baris .v { flex: 1 1 auto; min-width: 0; border-bottom: 1.4px dashed #333; padding-bottom: 1px; }
.kafer-web {
  border: 2px solid #111; text-align: center; padding: 1.8mm; font-size: .9rem; font-style: italic;
}
@media print {
  body.cetak { background: #fff; }
  body.cetak .lembar { padding: 0; max-width: none; box-shadow: none; }
  .kafer-bingkai { break-inside: avoid; }
}
@media (max-width: 700px) {
  .kafer-bingkai { padding: 8mm 6mm; min-height: 0; gap: 8mm; }
  .kafer-baris .k { width: 34mm; font-size: .78rem; }
  .kafer-baris { font-size: .78rem; }
  .kafer-judul .b1, .kafer-judul .b4 { font-size: 1.05rem; }
  .kafer-judul .b2 { font-size: .96rem; }
}
</style>
</head>
<body class="cetak"<?= isset($_GET['auto']) ? ' data-auto-cetak="1"' : '' ?>>
<div class="lembar">
  <div class="kafer-bingkai">
    <div class="kafer-atas">
      <?php if ($logo !== ''): ?>
        <img class="kafer-logo" src="<?= e($logo) ?>" alt="Logo <?= e(lembaga_nama()) ?>">
      <?php else: ?>
        <div class="kafer-logo-kosong">Logo lembaga<br>(unggah pada Pengaturan Data Lembaga)</div>
      <?php endif; ?>
      <div class="kafer-judul">
        <div class="b1"><?= e($k['judul']) ?></div>
        <div class="b2"><?= e($k['bentuk']) ?></div>
        <?php if ($k['singkat'] !== ''): ?><div class="b3">(<?= e($k['singkat']) ?>)</div><?php endif; ?>
        <div class="b4"><?= e($k['nama']) ?></div>
        <div class="b5"><?= e($k['tahun']) ?></div>
      </div>
    </div>

    <div class="kafer-kotak">
      <?php foreach ($kotak as $label => $nilai): ?>
        <div class="kafer-baris"><span class="k"><?= e($label) ?></span><span class="t">:</span><span class="v"><?= e($nilai) ?></span></div>
      <?php endforeach; ?>
    </div>

    <?php if ($web !== ''): ?>
      <div class="kafer-web">Web : <?= e($web) ?></div>
    <?php endif; ?>
  </div>
</div>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
