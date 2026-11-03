<?php
/**
 * Lembar cetak Buku Induk Siswa (?bagian=induk) dan Buku Klapper (?bagian=klapper).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$bagian = f('bagian', 'induk') === 'klapper' ? 'klapper' : 'induk';
$kelasId = idf('kelas');
$status = f('status');
$cari = f('cari');

$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR s.nama_wali LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if ($kelasId > 0) { $w[] = 's.kelas_id = ?'; $arg[] = $kelasId; }
if ($status !== '') { $w[] = 's.status = ?'; $arg[] = $status; }
$where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

if ($bagian === 'klapper') {
    $kelas = semua('SELECT * FROM kelas ORDER BY urutan, nama');
    $data = [];
    foreach ($kelas as $k) {
        $data[] = ['nama' => $k['nama'], 'siswa' => semua('SELECT * FROM siswa WHERE kelas_id = ? ORDER BY nama', [(int) $k['id']])];
    }
    $tanpa = semua('SELECT * FROM siswa WHERE kelas_id IS NULL ORDER BY nama');
    if ($tanpa) { $data[] = ['nama' => 'Belum berkelas', 'siswa' => $tanpa]; }
    cetak_mulai('Buku Klapper Siswa', 'A4 portrait', [
        'Tahun Ajaran' => setting('tahun_ajaran') . ' · Semester ' . setting('semester'),
        'Jumlah Kelas' => count($data) . ' kelas',
    ]);
    foreach ($data as $blok) {
        if (!$blok['siswa']) { continue; }
        $l = 0; $p = 0;
        foreach ($blok['siswa'] as $s) { if ($s['jenis_kelamin'] === 'P') { $p++; } else { $l++; } }
        ?>
        <h3 style="font-size:.86rem;margin:12px 0 2px"><?= e($blok['nama']) ?></h3>
        <p class="ket-cetak"><?= count($blok['siswa']) ?> siswa (L: <?= $l ?> · P: <?= $p ?>)</p>
        <table class="tbl-cetak padat">
          <thead><tr>
            <th class="tengah" style="width:24px">No</th><th>Nama</th><th style="width:60px">NIS</th><th style="width:70px">NISN</th>
            <th class="tengah" style="width:24px">JK</th><th>Tempat, Tanggal Lahir</th><th>Wali</th>
          </tr></thead>
          <tbody>
          <?php foreach ($blok['siswa'] as $i => $s): ?>
            <tr><td class="tengah"><?= $i + 1 ?></td><td><?= e($s['nama']) ?></td><td><?= e($s['nis'] ?: '-') ?></td><td><?= e($s['nisn'] ?: '-') ?></td>
              <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
              <td><?= e(trim(($s['tempat_lahir'] ?: '-') . ', ' . ($s['tanggal_lahir'] ? tanggal_id($s['tanggal_lahir']) : '-'), ', ')) ?></td>
              <td><?= e($s['nama_wali'] ?: '-') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php
    }
    cetak_selesai([
        ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
        ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
    ]);
    exit;
}

$daftar = semua('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id' . $where . ' ORDER BY k.urutan, k.nama, s.nama', $arg);
$ket = [];
if ($kelasId > 0) { $ket[] = 'Kelas: ' . kelas_nama($kelasId); }
if ($status !== '') { $ket[] = 'Status: ' . status_siswa_label($status); }
if ($cari !== '') { $ket[] = 'Kata kunci: ' . $cari; }

cetak_mulai('Buku Induk Siswa', 'A4 landscape', [
    'Jumlah Data' => count($daftar) . ' siswa',
    'Saringan' => $ket ? implode(' · ', $ket) : 'Seluruh data',
    'Tahun Ajaran' => setting('tahun_ajaran') . ' · Semester ' . setting('semester'),
]);
?>
<table class="tbl-cetak padat">
  <thead><tr>
    <th class="tengah" style="width:22px">No</th><th style="width:52px">NIS</th><th style="width:62px">NISN</th><th>Nama Lengkap</th>
    <th class="tengah" style="width:22px">JK</th><th style="width:48px">Kelas</th><th>Tempat Lahir</th>
    <th class="tengah" style="width:58px">Tgl Lahir</th><th>Nama Ayah</th><th>Nama Ibu</th><th>Wali</th>
    <th style="width:70px">Telepon</th><th>Alamat</th><th class="tengah" style="width:44px">Status</th>
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
      <td><?= e($s['tempat_lahir'] ?: '-') ?></td>
      <td class="tengah"><?= e($s['tanggal_lahir'] ? tanggal_pendek($s['tanggal_lahir']) : '-') ?></td>
      <td><?= e($s['nama_ayah'] ?: '-') ?></td>
      <td><?= e($s['nama_ibu'] ?: '-') ?></td>
      <td><?= e($s['nama_wali'] ?: '-') ?></td>
      <td><?= e($s['telepon'] ?: '-') ?></td>
      <td><?= e($s['alamat'] ?: '-') ?></td>
      <td class="tengah"><?= e(status_siswa_label($s['status'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="14" class="tengah">Tidak ada data.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
