<?php
/**
 * Lembar cetak register Surat Masuk (A4 landscape).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$sifat = f('sifat');
$dari = tanggal_sah(f('dari')) ? f('dari') : '';
$sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(no_agenda LIKE ? OR no_surat LIKE ? OR pengirim LIKE ? OR perihal LIKE ? OR disposisi LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k);
}
if ($sifat !== '') { $w[] = 'sifat = ?'; $arg[] = $sifat; }
if ($dari !== '') { $w[] = 'tanggal_terima >= ?'; $arg[] = $dari; }
if ($sampai !== '') { $w[] = 'tanggal_terima <= ?'; $arg[] = $sampai; }
$daftar = semua('SELECT * FROM surat_masuk' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY COALESCE(tanggal_terima, tanggal_surat), id', $arg);

$meta = ['Jumlah Surat' => count($daftar) . ' surat'];
if ($dari !== '' || $sampai !== '') {
    $meta['Periode Terima'] = ($dari !== '' ? tanggal_id($dari) : '…') . ' s.d. ' . ($sampai !== '' ? tanggal_id($sampai) : '…');
}
if ($sifat !== '') { $meta['Sifat'] = sifat_surat_list()[$sifat] ?? $sifat; }
if ($cari !== '') { $meta['Kata kunci'] = $cari; }
$meta['Surat Berkas'] = (int) kolom('SELECT COUNT(*) FROM surat_masuk WHERE berkas_url IS NOT NULL AND berkas_url <> \'\'') . ' surat';

cetak_mulai('Register Surat Masuk', 'A4 landscape', $meta);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:24px">No</th>
    <th class="tengah" style="width:66px">No. Agenda</th>
    <th style="width:80px">No. Surat</th>
    <th class="tengah" style="width:62px">Tgl Surat</th>
    <th class="tengah" style="width:62px">Tgl Terima</th>
    <th>Pengirim</th><th>Perihal</th>
    <th class="tengah" style="width:46px">Sifat</th>
    <th class="tengah" style="width:50px">Lampiran</th>
    <th style="width:82px">Disposisi</th>
    <th class="tengah" style="width:60px">Berkas</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $s): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td class="tengah"><?= e($s['no_agenda']) ?></td>
      <td><?= e($s['no_surat'] ?: '-') ?></td>
      <td class="tengah"><?= e($s['tanggal_surat'] ? tanggal_pendek($s['tanggal_surat']) : '-') ?></td>
      <td class="tengah"><?= e($s['tanggal_terima'] ? tanggal_pendek($s['tanggal_terima']) : '-') ?></td>
      <td><?= e($s['pengirim'] ?: '-') ?></td>
      <td><?= e($s['perihal']) ?><?= $s['keterangan'] ? '<br><span style="font-size:.62rem">' . e($s['keterangan']) . '</span>' : '' ?></td>
      <td class="tengah"><?= e(sifat_surat_list()[$s['sifat']] ?? $s['sifat']) ?></td>
      <td class="tengah"><?= e($s['lampiran'] ?: '-') ?></td>
      <td><?= e($s['disposisi'] ?: '-') ?></td>
      <td class="tengah"><?= $s['berkas_url'] ? 'Ada' : '-' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="11" class="tengah">Tidak ada surat masuk pada saringan ini.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
