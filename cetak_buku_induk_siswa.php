<?php
/**
 * Lembar cetak BUKU INDUK SISWA untuk SATU siswa (A4 tegak) — susunan mengikuti formulir
 * buku induk manual: nomor urut/NIS/NISN/NIK di kepala, pas foto di KANAN ATAS, bagian
 * A. Identitas Siswa … F. Keterangan Siswa, lalu riwayat mutasi & riwayat kenaikan/kelulusan
 * di bagian bawah, ditutup tanda tangan kepala & Kepala Tata Usaha.
 *
 * Medan & label diambil dari inc/siswa_induk.php (satu sumber dengan formulir Data Siswa).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$id = idf('id');
$s = $id > 0 ? satu('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.id = ?', [$id]) : null;
if (!$s) {
    flash('Data siswa tidak ditemukan.', 'err');
    redirect('buku_induk.php');
}
$n = array_merge($s, siswa_induk_data((int) $s['id']));
$mutasi = semua('SELECT * FROM mutasi WHERE siswa_id = ? OR (nis <> "" AND nis = ?) ORDER BY tanggal', [(int) $id, (string) $s['nis']]);
$kenaikan = semua('SELECT * FROM kenaikan WHERE siswa_id = ? AND dibatalkan = 0 ORDER BY tanggal', [(int) $id]);
$waliKelas = $s['kelas_id'] ? kelas_by_id((int) $s['kelas_id']) : null;

// Nomor urut = posisi siswa pada daftar Buku Induk (urut kelas, lalu nama) — bisa dikirim ?no=.
$urut = idf('no');
if ($urut <= 0) {
    $daftarId = array_map('intval', array_column(
        semua('SELECT s.id FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id ORDER BY k.urutan, k.nama, s.nama'), 'id'));
    $pos = array_search((int) $s['id'], $daftarId, true);
    $urut = $pos === false ? 1 : $pos + 1;
}

/** Satu baris medan: nomor • label • : • nilai (garis titik-titik seperti formulir). */
function bi_baris(array $b, array $n): string
{
    return '<tr><td class="no">' . e($b['no'] ?? '') . '</td>'
        . '<td class="lbl">' . e($b['label']) . '</td><td class="titik">:</td>'
        . '<td class="nil">' . e(siswa_medan_nilai($n, $b)) . '</td></tr>';
}

$grup = siswa_medan_grup();
$logo = setting('logo');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buku Induk — <?= e($s['nama']) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
@page { size: A4 portrait; margin: 8mm 12mm; }
body.cetak { font-size: 11px; }
.bi-kepala { display: flex; align-items: center; gap: 10px; }
.bi-kepala .kop-teks { text-align: center; flex: 1; }
.bi-kepala .kop-nama { font-size: 1rem; }
.bi-kepala .kop-baris { font-size: .64rem; line-height: 1.28; }
.bi-garis { border: none; border-top: 2.5px solid #12356b; margin: 5px 0 1px; }
.bi-garis2 { border: none; border-top: .8px solid #12356b; margin: 2px 0 6px; }
.bi-judul { text-align: center; text-transform: uppercase; letter-spacing: .8px; font-size: .9rem; margin: 0; font-weight: 800; }
.bi-sub { text-align: center; font-size: .64rem; color: #333; margin-bottom: 2px; }

/* Kepala lembar: Nomor Urut / NIS Lokal / NISN / NIK */
table.bi-kop { width: 100%; border-collapse: collapse; font-size: .72rem; margin-bottom: 2px; }
table.bi-kop td { padding: 1px 3px; vertical-align: bottom; }
table.bi-kop td.k { width: 74px; }
table.bi-kop td.k.sp { padding-left: 22px; }
table.bi-kop td.t { width: 8px; }
table.bi-kop td.v { border-bottom: 1px dotted #667085; }

/* Badan: bagian A–F di kiri, pas foto di KANAN ATAS */
.bi-atas { display: flex; gap: 12px; align-items: flex-start; }
.bi-isi { flex: 1 1 auto; min-width: 0; }
.bi-kanan { flex: 0 0 auto; }
.bi-foto { width: 26mm; height: 34mm; border: .8px solid #6b7a90; display: flex; align-items: center;
  justify-content: center; text-align: center; font-size: .6rem; color: #667085; overflow: hidden; }
.bi-foto img { width: 100%; height: 100%; object-fit: cover; }
.bi-foto-ket { font-size: .58rem; color: #667085; text-align: center; margin-top: 2px; }
.bi-blok { break-inside: avoid; }
.bi-blok h3 { font-size: .68rem; font-weight: 800; font-style: italic; letter-spacing: .2px;
  margin: 2px 0 0; color: #123a6b; }
table.bi-tabel { width: 100%; border-collapse: collapse; font-size: .62rem; line-height: 1.18; }
table.bi-tabel td { padding: 0 3px; vertical-align: bottom; }
table.bi-tabel td.no { width: 15px; color: #333; }
table.bi-tabel td.lbl { width: 45%; }
table.bi-tabel td.titik { width: 7px; }
table.bi-tabel td.nil { border-bottom: 1px dotted #7a869a; }
table.bi-tabel tr.sub td { font-weight: 700; font-style: italic; padding-top: 1px; font-size: .66rem; }

/* Riwayat (bagian bawah) */
table.bi-grid { width: 100%; border-collapse: collapse; font-size: .68rem; margin-top: 2px; }
table.bi-grid th, table.bi-grid td { border: .7px solid #6b7a90; padding: 1.5px 4px; vertical-align: top; }
table.bi-grid thead th { background: #eef4fd; text-align: center; font-size: .64rem; text-transform: uppercase; }
.bi-kaki { display: flex; justify-content: space-between; gap: 20px; margin-top: 10px; break-inside: avoid; }
.bi-ttd { text-align: center; font-size: .74rem; min-width: 185px; }
.bi-ttd .tempat { font-size: .72rem; }
.bi-ttd .spasi { height: 38px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.bi-ttd .bi-ttd-gambar { max-height: 100%; max-width: 150px; width: auto; display: block; object-fit: contain; }
.bi-ttd .nama { font-weight: 700; text-decoration: underline; }
.bi-bawah { display: flex; justify-content: space-between; align-items: center; gap: 12px;
  margin-top: 3px; border-top: .6px solid #ccc; padding-top: 1px; }
.bi-catatan { font-size: .62rem; color: #555; }
@media print {
  .bi-foto { border-color: #333; }
  table.bi-grid thead { display: table-header-group; }
  table.bi-grid tr { break-inside: avoid; }
}
</style>
</head>
<body class="cetak"<?= isset($_GET['auto']) ? ' data-auto-cetak="1"' : '' ?>>
<div class="lembar">
  <div class="bi-hal1">
  <div class="bi-kepala">
    <?php if ($logo !== ''): ?><img class="kop-gambar" src="<?= e($logo) ?>" alt="Logo" style="width:46px;height:46px;object-fit:contain"><?php endif; ?>
    <div class="kop-teks">
      <div class="kop-nama"><?= e(lembaga_nama()) ?></div>
      <?php foreach (kop_baris() as $b): ?><div class="kop-baris"><?= e($b) ?></div><?php endforeach; ?>
    </div>
  </div>
  <hr class="bi-garis"><hr class="bi-garis2">

  <div class="bi-judul">Buku Induk Siswa</div>
  <div class="bi-sub">Tahun Ajaran <?= e(setting('tahun_ajaran')) ?> · Semester <?= e(setting('semester')) ?>
    · Kelas <?= e($s['kelas_nama'] ?: '-') ?><?= ($waliKelas['wali'] ?? '') !== '' ? ' · Wali Kelas ' . e($waliKelas['wali']) : '' ?></div>

  <table class="bi-kop">
    <tr>
      <td class="k">Nomor Urut</td><td class="t">:</td><td class="v"><?= (int) $urut ?></td>
      <td class="k sp">NISN</td><td class="t">:</td><td class="v"><?= e($s['nisn'] ?: '-') ?></td>
    </tr>
    <tr>
      <td class="k">NIS Lokal</td><td class="t">:</td><td class="v"><?= e($s['nis'] ?: '-') ?></td>
      <td class="k sp">NIK Siswa</td><td class="t">:</td><td class="v"><?= e(trim((string) ($n['nik'] ?? '')) !== '' ? (string) $n['nik'] : '-') ?></td>
    </tr>
  </table>

  <div class="bi-atas">
    <div class="bi-isi">
      <?php foreach ($grup as $kode => $g): ?>
        <?php if (array_key_exists('cetak', $g) && !$g['cetak']) { continue; } ?>
        <div class="bi-blok">
          <h3><?= e($kode) ?>. <?= e($g['judul']) ?></h3>
          <table class="bi-tabel">
            <?php foreach ($g['baris'] as $b): ?>
              <?php if (isset($b['sub'])): ?>
                <tr class="sub"><td></td><td colspan="3"><?= e($b['sub']) ?></td></tr>
              <?php else: ?>
                <?= bi_baris($b, $n) ?>
              <?php endif; ?>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="bi-kanan">
      <div class="bi-foto"><?php if (!empty($s['foto'])): ?><img src="<?= e($s['foto']) ?>" alt="Pas foto siswa"><?php else: ?>PAS FOTO<br>3 x 4<?php endif; ?></div>
      <div class="bi-foto-ket">Pas Foto 3 x 4</div>
    </div>
  </div>

  </div><!-- /halaman 1 -->

  <div class="pecah"></div>

  <div class="bi-hal2">
  <div class="bi-blok">
    <h3>G. Riwayat Mutasi</h3>
    <table class="bi-grid">
      <thead><tr>
        <th style="width:22px">No</th><th style="width:64px">Tanggal</th><th style="width:48px">Jenis</th>
        <th>Kelas Asal</th><th>Kelas Tujuan</th><th>Sekolah Asal / Tujuan</th><th>No. Surat</th><th>Alasan / Keterangan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($mutasi as $i => $m): ?>
        <tr>
          <td style="text-align:center"><?= $i + 1 ?></td>
          <td style="text-align:center"><?= e(tanggal_pendek((string) $m['tanggal'])) ?></td>
          <td style="text-align:center"><?= e(ucfirst((string) $m['jenis'])) ?></td>
          <td><?= e($m['kelas_asal'] ?: '-') ?></td>
          <td><?= e($m['kelas_tujuan'] ?: '-') ?></td>
          <td><?= e($m['asal_sekolah'] ?: '-') ?></td>
          <td><?= e($m['no_surat'] ?: '-') ?></td>
          <td><?= e(trim(($m['alasan'] ?: '') . ' ' . ($m['keterangan'] ?: '')) ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mutasi): ?><tr><td colspan="8" style="text-align:center">— tidak ada mutasi —</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="bi-blok">
    <h3>H. Riwayat Kenaikan Kelas / Kelulusan</h3>
    <table class="bi-grid">
      <thead><tr>
        <th style="width:22px">No</th><th style="width:64px">Tanggal</th><th style="width:60px">Jenis</th>
        <th>Kelas Asal</th><th>Kelas Tujuan / Status</th><th style="width:78px">Tahun Ajaran</th><th>Diproses Oleh</th>
      </tr></thead>
      <tbody>
      <?php foreach ($kenaikan as $i => $k): ?>
        <tr>
          <td style="text-align:center"><?= $i + 1 ?></td>
          <td style="text-align:center"><?= e(tanggal_pendek((string) $k['tanggal'])) ?></td>
          <td style="text-align:center"><?= $k['jenis'] === 'lulus' ? 'Lulus' : 'Naik' ?></td>
          <td><?= e($k['kelas_asal'] ?: '-') ?></td>
          <td><?= e($k['kelas_tujuan'] ?: '-') ?></td>
          <td style="text-align:center"><?= e($k['ta'] ?: '-') ?></td>
          <td><?= e($k['oleh'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kenaikan): ?><tr><td colspan="7" style="text-align:center">— belum ada catatan kenaikan/kelulusan —</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="bi-kaki">
    <div class="bi-ttd">
      <div>Mengetahui,</div>
      <div><?= e('Kepala ' . istilah_lembaga()) ?></div>
      <div class="spasi"><?= ttd_kepala_img('bi-ttd-gambar') ?></div>
      <div class="nama"><?= e(kepala_lembaga()) ?></div>
      <?php if (nip_kepala() !== ''): ?><div>NIP. <?= e(nip_kepala()) ?></div><?php endif; ?>
    </div>
    <div class="bi-ttd">
      <div class="tempat"><?= e(ttd_tempat_teks()) ?></div>
      <div>Kepala Tata Usaha</div>
      <div class="spasi"></div>
      <div class="nama"><?= e(current_user()['nama']) ?></div>
    </div>
  </div>
  <div class="bi-bawah">
    <div class="bi-catatan">Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada
      <?= e(tanggal_id(tanggal_hari_ini())) ?> pukul <?= e(date('H:i')) ?></div>
  </div>
  </div><!-- /halaman 2 -->
</div>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</body>
</html>
