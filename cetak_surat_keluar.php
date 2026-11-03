<?php
/**
 * Lembar cetak register Surat Keluar (A4 landscape).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$status = f('status');
$jenisSaring = f('jenis_surat');
$petaJenis = surat_jenis_list();
$dari = tanggal_sah(f('dari')) ? f('dari') : '';
$sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(no_surat LIKE ? OR no_agenda LIKE ? OR tujuan LIKE ? OR perihal LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if ($status !== '') { $w[] = 'status = ?'; $arg[] = $status; }
if ($jenisSaring !== '' && array_key_exists($jenisSaring, $petaJenis)) { $w[] = 'jenis = ?'; $arg[] = $jenisSaring; }
if ($dari !== '') { $w[] = 'tanggal_surat >= ?'; $arg[] = $dari; }
if ($sampai !== '') { $w[] = 'tanggal_surat <= ?'; $arg[] = $sampai; }
$daftar = semua('SELECT * FROM surat_keluar' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY tanggal_surat, id', $arg);
$petaStatus = status_surat_keluar();

$rekap = [];
foreach ($daftar as $s) { $rekap[$s['status']] = ($rekap[$s['status']] ?? 0) + 1; }
$meta = ['Jumlah Surat' => count($daftar) . ' surat'];
$bagian = [];
foreach ($petaStatus as $k => $label) { if (!empty($rekap[$k])) { $bagian[] = $label . ': ' . $rekap[$k]; } }
if ($bagian) { $meta['Rekap Status'] = implode(' · ', $bagian); }
if ($dari !== '' || $sampai !== '') {
    $meta['Periode Surat'] = ($dari !== '' ? tanggal_id($dari) : '…') . ' s.d. ' . ($sampai !== '' ? tanggal_id($sampai) : '…');
}
if ($cari !== '') { $meta['Kata kunci'] = $cari; }
if ($jenisSaring !== '' && array_key_exists($jenisSaring, $petaJenis)) { $meta['Jenis Surat'] = $petaJenis[$jenisSaring]; }

cetak_mulai('Register Surat Keluar', 'A4 landscape', $meta);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:24px">No</th>
    <th class="tengah" style="width:86px">Nomor Surat</th>
    <th class="tengah" style="width:62px">Tanggal</th>
    <th style="width:118px">Jenis Surat</th>
    <th>Tujuan</th><th>Perihal</th>
    <th class="tengah" style="width:46px">Sifat</th>
    <th class="tengah" style="width:46px">Lamp.</th>
    <th class="tengah" style="width:58px">Status</th>
    <th style="width:90px">Verifikator</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $s): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td class="tengah"><?= e($s['no_surat']) ?></td>
      <td class="tengah"><?= e($s['tanggal_surat'] ? tanggal_pendek($s['tanggal_surat']) : '-') ?></td>
      <td><?= e(surat_jenis_label($s['jenis'] ?? '')) ?></td>
      <td><?= e($s['tujuan'] ?: '-') ?></td>
      <td><?= e($s['perihal']) ?></td>
      <td class="tengah"><?= e(sifat_surat_list()[$s['sifat']] ?? $s['sifat']) ?></td>
      <td class="tengah"><?= e($s['lampiran'] ?: '-') ?></td>
      <td class="tengah"><?= e($petaStatus[$s['status']] ?? $s['status']) ?></td>
      <td><?= e($s['verifikator'] ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="10" class="tengah">Tidak ada surat keluar pada saringan ini.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
