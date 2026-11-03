<?php
/**
 * Inventaris Sarana & Prasarana — HANYA LIHAT, dibaca langsung dari basis data e-GURU.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$cari = f('cari');
$kondisi = f('kondisi');
$jenis = f('jenis');
$ada = sarpras_ada();
$sumber = sarpras_sekolah();
$daftar = $ada ? sarpras_list($cari, $kondisi, $jenis) : [];
$ringkas = sarpras_ringkas($daftar);

$headerEkspor = ['No', 'Nama Barang', 'Spesifikasi', 'Lokasi', 'Kondisi', 'Jumlah', 'Sumber Dana', 'Jenis', 'Keterangan'];
$barisEkspor = [];
foreach ($daftar as $i => $b) {
    $barisEkspor[] = [$i + 1, $b['nama'], $b['spesifikasi'], $b['lokasi'], $b['kondisi'], (int) $b['jumlah'], $b['sumber_dana'], $b['jenis'], $b['keterangan']];
}
if (f('unduh') === 'csv') {
    csv_kirim('inventaris-sarpras', $headerEkspor, $barisEkspor);
}
$qCetak = http_build_query(array_filter(['cari' => $cari, 'kondisi' => $kondisi, 'jenis' => $jenis]));

$GLOBALS['__butuh_xlsx'] = true;
page_top('Inventaris Sarana & Prasarana', 'Data dibaca langsung dari aplikasi e-GURU (hanya lihat)');
?>
<div class="alert alert-info">
  <div><b>Sumber data:</b> menu <i>Tugas Tambahan → Sarana &amp; Prasarana</i> aplikasi e-GURU.
    <?php if ($ada): ?>Terhubung ke data <b><?= e($sumber['nama']) ?></b> (sekolah id <?= (int) $sumber['id'] ?>).<?php else: ?>
      Belum terhubung — berkas basis data e-GURU tidak ditemukan atau sekolahnya tidak cocok. Periksa pada <a href="pengaturan_lembaga.php">Pengaturan Data Lembaga</a>.<?php endif; ?>
    Data di sini <b>tidak dapat diubah</b> dari TUSmart; perubahan dilakukan di e-GURU dan langsung terlihat di sini.</div>
</div>

<?php if ($ada): ?>
<div class="grid g4">
  <?= kartu('Jenis Barang', (string) $ringkas['baris'], 'Baris inventaris', 'sarpras', 'biru') ?>
  <?= kartu('Total Unit', (string) $ringkas['unit'], 'Jumlah seluruh barang', 'sarpras', 'hijau') ?>
  <?= kartu('Kondisi Baik', (string) $ringkas['baik'], 'Unit siap pakai', 'cek', 'hijau') ?>
  <?= kartu('Perlu Perbaikan', (string) $ringkas['rusak'], 'Rusak ringan & berat', 'ubah', 'merah') ?>
</div>

<div class="card mt16">
  <div class="card-kepala">
    <div><h2>Daftar Inventaris Barang</h2><p class="card-ket"><?= count($daftar) ?> baris ditampilkan.</p></div>
    <div class="card-aksi">
      <a class="btn btn-garis" href="perpustakaan.php"><?= ikon('buku') ?> Inventaris Perpustakaan</a>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="inventaris-sarpras"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_sarpras.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>
  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama barang, spesifikasi, lokasi…"></div>
    <div class="medan"><label for="kondisi">Kondisi</label><select id="kondisi" name="kondisi" data-auto><?= opsi(array_combine(sarpras_pilihan_kondisi(), sarpras_pilihan_kondisi()), $kondisi, 'Semua kondisi') ?></select></div>
    <div class="medan"><label for="jenis">Jenis</label><select id="jenis" name="jenis" data-auto><?= opsi(array_combine(sarpras_pilihan_jenis(), sarpras_pilihan_jenis()), $jenis, 'Semua jenis') ?></select></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $kondisi !== '' || $jenis !== ''): ?><a class="btn btn-garis" href="sarpras.php">Reset</a><?php endif; ?>
  </form>

  <?php if (!$daftar): ?>
    <div class="kosong">Tidak ada barang inventaris yang cocok dengan saringan ini.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th>Nama Barang</th><th>Spesifikasi</th><th>Lokasi</th>
          <th class="tengah">Kondisi</th><th class="tengah">Jumlah</th><th>Sumber Dana</th><th>Jenis</th><th>Keterangan</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $b): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><b><?= e($b['nama']) ?></b></td>
            <td><?= e($b['spesifikasi'] ?: '-') ?></td>
            <td><?= e($b['lokasi'] ?: '-') ?></td>
            <td class="tengah"><span class="chip <?= $b['kondisi'] === 'Baik' ? 'hijau' : ($b['kondisi'] === 'Rusak Berat' ? 'merah' : 'emas') ?>"><?= e($b['kondisi'] ?: '-') ?></span></td>
            <td class="tengah"><b><?= (int) $b['jumlah'] ?></b></td>
            <td><?= e($b['sumber_dana'] ?: '-') ?></td>
            <td><?= e($b['jenis'] ?: '-') ?></td>
            <td><?= e($b['keterangan'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="5">JUMLAH UNIT</td>
          <td class="tengah"><?= (int) $ringkas['unit'] ?></td>
          <td colspan="3"><?= count($daftar) ?> jenis barang</td>
        </tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="grid g3 mt16">
  <section class="card">
    <div class="card-kepala"><div><h2>Rekap per Kondisi</h2></div></div>
    <table class="tbl">
      <thead><tr><th>Kondisi</th><th class="num">Unit</th></tr></thead>
      <tbody><?php foreach ($ringkas['kondisi'] as $k => $n): ?><tr><td><?= e($k) ?></td><td class="num"><?= (int) $n ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </section>
  <section class="card">
    <div class="card-kepala"><div><h2>Rekap per Jenis</h2></div></div>
    <table class="tbl">
      <thead><tr><th>Jenis</th><th class="num">Baris</th></tr></thead>
      <tbody><?php foreach ($ringkas['jenis'] as $k => $n): ?><tr><td><?= e($k) ?></td><td class="num"><?= (int) $n ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </section>
  <section class="card">
    <div class="card-kepala"><div><h2>Rekap per Lokasi</h2></div></div>
    <table class="tbl">
      <thead><tr><th>Lokasi</th><th class="num">Baris</th></tr></thead>
      <tbody><?php foreach (array_slice($ringkas['lokasi'], 0, 12, true) as $k => $n): ?><tr><td><?= e($k) ?></td><td class="num"><?= (int) $n ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </section>
</div>

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Inventaris Sarana dan Prasarana', 'sheet' => 'Inventaris', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php page_bottom(); ?>
