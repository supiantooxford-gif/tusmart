<?php
/**
 * Lembar cetak Inventaris Sarana & Prasarana (A4 landscape) — sumber e-GURU.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$kondisi = f('kondisi');
$jenis = f('jenis');
if (!sarpras_ada()) {
    flash('Data inventaris belum terhubung ke aplikasi e-GURU.', 'err');
    redirect('sarpras.php');
}
$daftar = sarpras_list($cari, $kondisi, $jenis);
$ringkas = sarpras_ringkas($daftar);
$sumber = sarpras_sekolah();
$ket = [];
if ($kondisi !== '') { $ket[] = 'Kondisi: ' . $kondisi; }
if ($jenis !== '') { $ket[] = 'Jenis: ' . $jenis; }
if ($cari !== '') { $ket[] = 'Kata kunci: ' . $cari; }

cetak_mulai('Daftar Inventaris Sarana dan Prasarana', 'A4 landscape', [
    'Jumlah' => $ringkas['baris'] . ' jenis barang · ' . $ringkas['unit'] . ' unit',
    'Kondisi' => 'Baik: ' . (int) ($ringkas['kondisi']['Baik'] ?? 0) . ' · Rusak Ringan: ' . (int) ($ringkas['kondisi']['Rusak Ringan'] ?? 0) . ' · Rusak Berat: ' . (int) ($ringkas['kondisi']['Rusak Berat'] ?? 0),
    'Saringan' => $ket ? implode(' · ', $ket) : 'Seluruh inventaris',
    'Sumber Data' => 'Aplikasi e-GURU · ' . $sumber['nama'],
]);
?>
<table class="tbl-cetak">
  <thead><tr>
    <th class="tengah" style="width:22px">No</th><th>Nama Barang</th><th>Spesifikasi</th><th>Lokasi</th>
    <th class="tengah" style="width:52px">Kondisi</th><th class="tengah" style="width:40px">Jumlah</th>
    <th>Sumber Dana</th><th class="tengah" style="width:54px">Jenis</th><th>Keterangan</th>
  </tr></thead>
  <tbody>
  <?php foreach ($daftar as $i => $b): ?>
    <tr>
      <td class="tengah"><?= $i + 1 ?></td>
      <td><?= e($b['nama']) ?></td>
      <td><?= e($b['spesifikasi'] ?: '-') ?></td>
      <td><?= e($b['lokasi'] ?: '-') ?></td>
      <td class="tengah"><?= e($b['kondisi'] ?: '-') ?></td>
      <td class="tengah"><?= (int) $b['jumlah'] ?></td>
      <td><?= e($b['sumber_dana'] ?: '-') ?></td>
      <td class="tengah"><?= e($b['jenis'] ?: '-') ?></td>
      <td><?= e($b['keterangan'] ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftar): ?><tr><td colspan="9" class="tengah">Tidak ada data.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr>
    <td colspan="5">JUMLAH UNIT</td>
    <td class="tengah"><?= (int) $ringkas['unit'] ?></td>
    <td colspan="3"><?= $ringkas['baris'] ?> jenis barang</td>
  </tr></tfoot>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
