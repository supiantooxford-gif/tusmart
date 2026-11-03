<?php
/**
 * Lembar cetak Data Guru & Tenaga Kependidikan (A4 landscape).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$jabatan = f('jabatan');
$status = f('status');
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(nama LIKE ? OR nip LIKE ? OR nuptk LIKE ? OR mapel LIKE ? OR email LIKE ? OR telepon LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k);
}
if ($jabatan !== '') { $w[] = 'jabatan = ?'; $arg[] = $jabatan; }
if ($status !== '') { $w[] = 'aktif = ?'; $arg[] = $status === 'aktif' ? 1 : 0; }
$daftar = semua('SELECT * FROM guru' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY urutan, nama', $arg);

$ketFilter = [];
if ($cari !== '') { $ketFilter[] = 'Kata kunci: ' . $cari; }
if ($jabatan !== '') { $ketFilter[] = 'Jabatan: ' . $jabatan; }
if ($status !== '') { $ketFilter[] = 'Status: ' . ($status === 'aktif' ? 'Aktif' : 'Nonaktif'); }

$laki = 0; $perempuan = 0; $aktifN = 0;
foreach ($daftar as $g) {
    if ($g['jenis_kelamin'] === 'P') { $perempuan++; } else { $laki++; }
    if ((int) $g['aktif'] === 1) { $aktifN++; }
}

cetak_mulai('Data Guru dan Tenaga Kependidikan', 'A4 landscape', [
    'Jumlah Data' => count($daftar) . ' orang (' . $aktifN . ' aktif)',
    'Laki-laki / Perempuan' => $laki . ' / ' . $perempuan,
    'Saringan' => $ketFilter ? implode(' · ', $ketFilter) : 'Seluruh data',
]);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:26px">No</th>
    <th>Nama Lengkap</th>
    <th class="tengah">NIP / NUPTK</th>
    <th class="tengah">JK</th>
    <th>Jabatan</th>
    <th>Mata Pelajaran</th>
    <th>Kontak</th>
    <th class="tengah">TMT</th>
    <th>Keterangan</th>
    <th class="tengah">Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $g): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td><?= e($g['nama']) ?></td>
      <td class="tengah"><?= e(trim(($g['nip'] ?? '') . ' / ' . ($g['nuptk'] ?? ''), ' /') ?: '-') ?></td>
      <td class="tengah"><?= e($g['jenis_kelamin']) ?></td>
      <td><?= e($g['jabatan'] ?: '-') ?></td>
      <td><?= e($g['mapel'] ?: '-') ?></td>
      <td><?= e($g['telepon'] ?: '-') ?><?= $g['email'] ? '<br>' . e($g['email']) : '' ?></td>
      <td class="tengah"><?= e($g['tmt'] ? tanggal_pendek($g['tmt']) : '-') ?></td>
      <td><?= e($g['keterangan'] ?: '-') ?></td>
      <td class="tengah"><?= (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?>
    <tr><td colspan="10" class="tengah">Tidak ada data.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php
// Lembar ini hanya ditandatangani kepala lembaga → baris tempat/tanggal menyertai bloknya.
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : ''], 'tempat' => true],
]);
