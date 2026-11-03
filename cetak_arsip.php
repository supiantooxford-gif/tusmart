<?php
/**
 * Lembar cetak Daftar Arsip Digital (A4 portrait).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$status = f('status');
$sumber = f('sumber');
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(kode LIKE ? OR judul LIKE ? OR berkas_nama LIKE ? OR keterangan LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if (in_array($status, ['aktif', 'inaktif'], true)) { $w[] = 'status = ?'; $arg[] = $status; }
if (in_array($sumber, ['masuk', 'keluar'], true)) { $w[] = 'sumber = ?'; $arg[] = $sumber; }
$daftar = semua('SELECT * FROM arsip' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY tanggal, id', $arg);

$aktif = 0; $inaktif = 0;
foreach ($daftar as $a) { if ($a['status'] === 'aktif') { $aktif++; } else { $inaktif++; } }
$meta = [
    'Jumlah Arsip' => count($daftar) . ' arsip (aktif: ' . $aktif . ' · inaktif: ' . $inaktif . ')',
    'Saringan' => trim((($status !== '' ? 'Status: ' . ucfirst($status) . ' ' : '') . ($sumber !== '' ? 'Sumber: ' . ucfirst($sumber) . ' ' : '') . ($cari !== '' ? 'Kata kunci: ' . $cari : ''))) ?: 'Seluruh arsip',
];
cetak_mulai('Daftar Arsip Digital', 'A4 portrait', $meta);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:22px">No</th><th class="tengah" style="width:78px">Kode</th><th>Judul Arsip</th>
    <th class="tengah" style="width:44px">Sumber</th><th class="tengah" style="width:58px">Tanggal</th>
    <th class="tengah" style="width:46px">Status</th><th>Berkas</th><th>Keterangan</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $a): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td class="tengah"><?= e($a['kode']) ?></td>
      <td><?= e($a['judul']) ?></td>
      <td class="tengah"><?= e(ucfirst((string) $a['sumber'])) ?></td>
      <td class="tengah"><?= e($a['tanggal'] ? tanggal_pendek($a['tanggal']) : '-') ?></td>
      <td class="tengah"><?= $a['status'] === 'aktif' ? 'Aktif' : 'Inaktif' ?></td>
      <td><?= e($a['berkas_nama'] ?: '-') ?></td>
      <td><?= e($a['keterangan'] ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="8" class="tengah">Belum ada arsip.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
