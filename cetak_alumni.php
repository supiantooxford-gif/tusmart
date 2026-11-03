<?php
/**
 * Lembar cetak Data Alumni (A4 landscape).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$tahun = preg_match('/^\d{4}$/', f('tahun')) ? f('tahun') : '';
$kelas = f('kelas');
$w = ["s.status = 'lulus'"];
$arg = [];
if ($cari !== '') {
    $w[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR s.nama_wali LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if ($tahun !== '') { $w[] = 'substr(s.tanggal_status,1,4) = ?'; $arg[] = $tahun; }
if ($kelas !== '') { $w[] = 'k.nama = ?'; $arg[] = $kelas; }
$daftar = semua("SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id
    WHERE " . implode(' AND ', $w) . ' ORDER BY s.tanggal_status DESC, s.nama', $arg);

$laki = 0; $perempuan = 0;
foreach ($daftar as $s) { if ($s['jenis_kelamin'] === 'P') { $perempuan++; } else { $laki++; } }
$ket = [];
if ($tahun !== '') { $ket[] = 'Tahun lulus: ' . $tahun; }
if ($kelas !== '') { $ket[] = 'Kelas terakhir: ' . $kelas; }
if ($cari !== '') { $ket[] = 'Kata kunci: ' . $cari; }

cetak_mulai('Data Alumni', 'A4 landscape', [
    'Jumlah Alumni' => count($daftar) . ' orang (L: ' . $laki . ' · P: ' . $perempuan . ')',
    'Saringan' => $ket ? implode(' · ', $ket) : 'Seluruh alumni',
    'Tahun Ajaran' => setting('tahun_ajaran') . ' · Semester ' . setting('semester'),
]);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:24px">No</th><th style="width:56px">NIS</th><th style="width:66px">NISN</th>
    <th>Nama Alumni</th><th class="tengah" style="width:24px">JK</th><th style="width:58px">Kelas Terakhir</th>
    <th>Tempat, Tanggal Lahir</th><th>Nama Wali</th><th class="tengah" style="width:60px">Tanggal Lulus</th>
    <th class="tengah" style="width:42px">Tahun</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $s): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td><?= e($s['nis'] ?: '-') ?></td>
      <td><?= e($s['nisn'] ?: '-') ?></td>
      <td><?= e($s['nama']) ?></td>
      <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
      <td><?= e($s['kelas_nama'] ?: '-') ?></td>
      <td><?= e(trim(($s['tempat_lahir'] ?: '-') . ', ' . ($s['tanggal_lahir'] ? tanggal_id($s['tanggal_lahir']) : '-'), ', ')) ?></td>
      <td><?= e($s['nama_wali'] ?: ($s['nama_ayah'] ?: '-')) ?></td>
      <td class="tengah"><?= e($s['tanggal_status'] ? tanggal_pendek($s['tanggal_status']) : '-') ?></td>
      <td class="tengah"><?= e(substr((string) $s['tanggal_status'], 0, 4) ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="10" class="tengah">Belum ada alumni pada saringan ini.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr>
    <td colspan="4">JUMLAH</td>
    <td class="tengah">L <?= $laki ?></td>
    <td colspan="2">P <?= $perempuan ?></td>
    <td colspan="3" class="tengah">Total <?= count($daftar) ?> alumni</td>
  </tr></tfoot>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
