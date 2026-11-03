<?php
/**
 * Lembar cetak Riwayat Mutasi Siswa (A4 landscape).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$jenis = in_array(f('jenis'), ['masuk', 'keluar'], true) ? f('jenis') : '';
$dari = tanggal_sah(f('dari')) ? f('dari') : '';
$sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
$w = [];
$arg = [];
if ($jenis !== '') { $w[] = 'jenis = ?'; $arg[] = $jenis; }
if ($dari !== '') { $w[] = 'tanggal >= ?'; $arg[] = $dari; }
if ($sampai !== '') { $w[] = 'tanggal <= ?'; $arg[] = $sampai; }
$daftar = semua('SELECT * FROM mutasi' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY tanggal, id', $arg);

$masuk = 0; $keluar = 0;
foreach ($daftar as $m) { if ($m['jenis'] === 'masuk') { $masuk++; } else { $keluar++; } }
$meta = [
    'Jumlah Mutasi' => count($daftar) . ' catatan (masuk: ' . $masuk . ' · keluar: ' . $keluar . ')',
    'Jenis' => $jenis !== '' ? ucfirst($jenis) : 'Masuk & keluar',
];
if ($dari !== '' || $sampai !== '') {
    $meta['Periode'] = ($dari !== '' ? tanggal_id($dari) : '…') . ' s.d. ' . ($sampai !== '' ? tanggal_id($sampai) : '…');
}
cetak_mulai('Riwayat Mutasi Siswa', 'A4 landscape', $meta);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:24px">No</th><th class="tengah">Jenis</th><th class="tengah">Tanggal</th>
    <th>Nama Siswa</th><th class="tengah">NIS / NISN</th><th class="tengah">JK</th>
    <th>Kelas Asal</th><th>Kelas Tujuan</th><th>Sekolah Asal / Tujuan</th><th class="tengah">No. Surat</th><th>Alasan / Keterangan</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $m): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td class="tengah"><?= e(ucfirst($m['jenis'])) ?></td>
      <td class="tengah"><?= e(tanggal_id($m['tanggal'])) ?></td>
      <td><?= e($m['nama']) ?></td>
      <td class="tengah"><?= e(($m['nis'] ?: '-') . ' / ' . ($m['nisn'] ?: '-')) ?></td>
      <td class="tengah"><?= e($m['jenis_kelamin'] ?: '-') ?></td>
      <td><?= e($m['kelas_asal'] ?: '-') ?></td>
      <td><?= e($m['kelas_tujuan'] ?: '-') ?></td>
      <td><?= e($m['asal_sekolah'] ?: '-') ?></td>
      <td class="tengah"><?= e($m['no_surat'] ?: '-') ?></td>
      <td><?= e(trim(($m['alasan'] ?: '') . ' ' . ($m['keterangan'] ?: '')) ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="11" class="tengah">Tidak ada catatan mutasi.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
