<?php
/**
 * Lembar surat keluar resmi (kop surat + isi sesuai JENIS + tanda tangan) — A4 portrait.
 *
 * v1.6.0: kop resmi (yayasan → nama madrasah → NPSN/NSM/terakreditasi → alamat → email/situs)
 * dan tiga template surat (Rekomendasi Pindah, Keterangan Pindah, Persetujuan Pindah/Lolos Butuh).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/surat_template.php';
require_login();

$id = idf('id');
$s = $id > 0 ? satu('SELECT * FROM surat_keluar WHERE id = ?', [$id]) : null;
if (!$s) {
    flash('Surat keluar tidak ditemukan.', 'err');
    redirect('surat_keluar.php');
}
$jenis = surat_jenis_sah((string) ($s['jenis'] ?? SURAT_JENIS_UMUM));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(surat_jenis_label($jenis)) ?> <?= e($s['no_surat']) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
@page { size: A4 portrait; margin: 15mm 18mm; }
.surat-kop-resmi { display: flex; align-items: center; gap: 14px; }
.surat-kop-resmi .kop-gambar { width: 66px; height: 66px; }
.surat-kop-resmi .kop-teks { text-align: center; flex: 1; }
.surat-kop-resmi .kop-yayasan { font-size: .78rem; font-weight: 700; letter-spacing: .3px; }
.surat-kop-resmi .kop-baris { font-size: .73rem; line-height: 1.42; }
.surat-garis { border: none; border-top: 3px solid #12356b; margin: 6px 0 1px; }
.surat-garis2 { border: none; border-top: 1px solid #12356b; margin: 2px 0 14px; }
.surat-judul { font-size: 1.02rem; font-weight: 800; letter-spacing: .5px; text-align: center; margin: 4px 0 2px; text-transform: uppercase; }
.surat-nomor-teks { text-align: center; font-size: .88rem; margin: 0 0 16px; }
.surat-identitas { width: 100%; font-size: .86rem; border-collapse: collapse; margin-bottom: 10px; }
.surat-identitas td { padding: 1px 3px; vertical-align: top; }
.surat-identitas td.lbl { width: 168px; }
.surat-identitas td.titik { width: 10px; }
.surat-p { font-size: .9rem; line-height: 1.7; margin: 10px 0 4px; }
.surat-alamat { font-size: .9rem; line-height: 1.7; margin: 6px 0 12px; }
.surat-isi { font-size: .9rem; line-height: 1.72; text-align: justify; margin: 10px 0; }
.surat-syarat { font-size: .87rem; line-height: 1.55; margin: 4px 0 10px; padding-left: 22px; }
.surat-syarat li { margin-bottom: 1px; }
table.surat-proses { width: 100%; border-collapse: collapse; font-size: .74rem; margin: 6px 0 12px; }
table.surat-proses th, table.surat-proses td { border: 1px solid #8c9ab1; padding: 3px 5px; vertical-align: middle; }
table.surat-proses th { background: #e8eef7; font-size: .7rem; text-align: center; }
table.surat-proses td.tengah { text-align: center; }
.surat-tutup { margin-top: 20px; display: flex; justify-content: flex-end; }
.surat-ttd { text-align: center; font-size: .88rem; min-width: 235px; }
.surat-ttd .ttd-tempat { font-size: .86rem; }
.surat-ttd .spasi { height: 62px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.surat-ttd .surat-ttd-gambar { max-height: 100%; max-width: 200px; width: auto; display: block; object-fit: contain; }
.surat-ttd .nama { font-weight: 700; text-decoration: underline; }
.surat-tembusan { margin-top: 16px; font-size: .72rem; color: #333; }
.surat-tembusan ol { margin: 3px 0 0; padding-left: 18px; }
/* Kaki "Dicetak dari …" diletakkan di DASAR halaman (permintaan pemilik) — halaman tetap 1 lembar
   karena lembar dibuat flex setinggi area cetak dan kaki didorong ke bawah dengan margin-top:auto. */
body.cetak .lembar { display: flex; flex-direction: column; min-height: 258mm; }
.sheet-cetak-kaki { margin-top: auto; }
/* Surat Lolos Butuh memuat DUA daftar bernomor + tabel proses; dirapatkan agar tetap
   muat SATU halaman A4 seperti dokumen aslinya. */
body.padat .surat-identitas { font-size: .8rem; margin-bottom: 4px; }
body.padat .surat-identitas td { padding: 0 3px; }
body.padat .surat-p, body.padat .surat-isi { font-size: .83rem; line-height: 1.42; margin: 4px 0; }
body.padat .surat-syarat { font-size: .78rem; line-height: 1.28; margin: 1px 0 4px; padding-left: 19px; }
body.padat table.surat-proses { font-size: .68rem; margin: 3px 0 6px; }
body.padat table.surat-proses th, body.padat table.surat-proses td { padding: 1px 3px; }
body.padat .surat-judul { margin: 2px 0 0; }
body.padat .surat-nomor-teks { margin-bottom: 10px; }
body.padat .surat-tutup { margin-top: 8px; }
body.padat .surat-ttd .spasi { height: 40px; }

@media print {
  .surat-tutup { break-inside: avoid; }
  table.surat-proses { break-inside: avoid; }
  /* Tinggi lembar = area cetak A4 tegak (297mm − margin 15mm atas/bawah ≈ 267mm) supaya kaki
     "Dicetak dari …" jatuh tepat di dasar halaman tanpa menambah halaman kedua. */
  body.cetak .lembar { min-height: 262mm; }
}
</style>
</head>
<body class="cetak<?= $jenis === 'lolos_butuh' ? ' padat' : '' ?>"<?= isset($_GET['auto']) ? ' data-auto-cetak="1"' : '' ?>>
<div class="lembar">
  <?= surat_template_html($s) ?>

  <?php if ($jenis === SURAT_JENIS_UMUM): ?>
    <div class="surat-tembusan">
      <b>Tembusan:</b>
      <ol>
        <li>Arsip</li>
        <li>Yang bersangkutan</li>
      </ol>
    </div>
  <?php endif; ?>
  <div class="sheet-cetak-kaki">Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · <?= e(surat_jenis_label($jenis)) ?> · status: <?= e(status_surat_keluar()[$s['status']] ?? $s['status']) ?><?= $s['verifikator'] ? ' · diverifikasi ' . e($s['verifikator']) : '' ?></div>
</div>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
