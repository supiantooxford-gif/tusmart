<?php
/**
 * Lembar cetak Data Siswa (A4 landscape, 13 kolom + alamat di bawah telepon).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$kelasId = idf('kelas');
$status = f('status');
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR s.nama_wali LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if ($kelasId > 0) { $w[] = 's.kelas_id = ?'; $arg[] = $kelasId; }
if ($status !== '') { $w[] = 's.status = ?'; $arg[] = $status; }
$daftar = semua('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id'
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY k.urutan, k.nama, s.nama', $arg);

$laki = 0; $perempuan = 0;
foreach ($daftar as $s) { if ($s['jenis_kelamin'] === 'P') { $perempuan++; } else { $laki++; } }
$ketFilter = [];
if ($cari !== '') { $ketFilter[] = 'Kata kunci: ' . $cari; }
if ($kelasId > 0) { $ketFilter[] = 'Kelas: ' . kelas_nama($kelasId); }
if ($status !== '') { $ketFilter[] = 'Status: ' . status_siswa_label($status); }

cetak_mulai('Data Siswa', 'A4 landscape', [
    'Jumlah Data' => count($daftar) . ' siswa (L: ' . $laki . ' · P: ' . $perempuan . ')',
    'Saringan' => $ketFilter ? implode(' · ', $ketFilter) : 'Seluruh data',
    'Tahun Ajaran' => setting('tahun_ajaran') . ' · Semester ' . setting('semester'),
]);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:24px">No</th>
    <th style="width:56px">NIS</th>
    <th style="width:66px">NISN</th>
    <th>Nama Lengkap</th>
    <th class="tengah" style="width:26px">JK</th>
    <th>Tempat Lahir</th>
    <th class="tengah" style="width:64px">Tgl Lahir</th>
    <th style="width:96px">Telepon / Alamat</th>
    <th>Nama Ayah</th>
    <th>Nama Ibu</th>
    <th>Wali</th>
    <th style="width:56px">Kelas</th>
    <th class="tengah" style="width:52px">Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $s): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td><?= e($s['nis'] ?: '-') ?></td>
      <td><?= e($s['nisn'] ?: '-') ?></td>
      <td><?= e($s['nama']) ?></td>
      <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
      <td><?= e($s['tempat_lahir'] ?: '-') ?></td>
      <td class="tengah"><?= e($s['tanggal_lahir'] ? tanggal_pendek($s['tanggal_lahir']) : '-') ?></td>
      <td><?= e($s['telepon'] ?: '-') ?><?= $s['alamat'] ? '<br><span style="font-size:.62rem">' . e($s['alamat']) . '</span>' : '' ?></td>
      <td><?= e($s['nama_ayah'] ?: '-') ?></td>
      <td><?= e($s['nama_ibu'] ?: '-') ?></td>
      <td><?= e($s['nama_wali'] ?: '-') ?></td>
      <td><?= e($s['kelas_nama'] ?: '-') ?></td>
      <td class="tengah"><?= e(status_siswa_label($s['status'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="13" class="tengah">Tidak ada data.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr>
    <td colspan="4">JUMLAH</td>
    <td class="tengah">L <?= $laki ?></td>
    <td colspan="2">P <?= $perempuan ?></td>
    <td colspan="6" class="tengah">Total <?= count($daftar) ?> siswa</td>
  </tr></tfoot>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
