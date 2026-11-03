<?php
/**
 * Inventaris Perpustakaan — 4 laporan e-PERPUS (pengunjung, data buku, peminjaman,
 * pengembalian) yang dibaca OTOMATIS & hanya-baca dari basis data e-PERPUS.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$ada = perpus_ada();
$jenis = f('jenis', 'pengunjung');
if (!perpus_jenis_sah($jenis)) { $jenis = 'pengunjung'; }
$f = perpus_filter();
$L = perpus_laporan($jenis, $f);
$info = perpus_info();
$ringkas = perpus_ringkas();

// ---------- ekspor CSV ----------
if (f('unduh') === 'csv' && $ada) {
    csv_kirim($L['berkas'], $L['kolom'], $L['rows']);
}

$qCetak = perpus_qs($f, ['jenis' => $jenis]);
$jumlahKolom = count($L['kolom']);
$perKelas = $jenis === 'pengunjung' ? perpus_pilihan('kelas', 'pengunjung') : [];
$perKeperluan = $jenis === 'pengunjung' ? perpus_pilihan('keperluan', 'pengunjung') : [];
$perKategori = $jenis === 'buku' ? perpus_pilihan('kategori', 'buku') : [];

$GLOBALS['__butuh_xlsx'] = true;
page_top('Inventaris Perpustakaan', 'Administrasi sarana prasarana · data dibaca otomatis dari e-PERPUS');
?>
<div class="alert alert-info">
  <div><b>Sumber data:</b> aplikasi <b>e-PERPUS</b><?= !empty($info['nama_perpus']) ? ' — ' . e($info['nama_perpus']) : '' ?>.
    Laporan di halaman ini <b>terisi otomatis</b> setiap kali isi e-PERPUS diperbarui (data dibaca langsung,
    hanya-baca — TUSmart tidak dapat mengubah data perpustakaan).
    <?php if (!$ada): ?>
      <br><b>Belum terhubung:</b> berkas basis data e-PERPUS tidak ditemukan.
      Periksa jalurnya pada <a href="pengaturan_lembaga.php">Pengaturan Data Lembaga</a>.
    <?php endif; ?>
  </div>
</div>

<?php if ($ada): ?>
<div class="grid g4">
  <?= kartu('Anggota Aktif', (string) $ringkas['anggota'], 'Terdaftar di perpustakaan', 'pengguna', 'biru') ?>
  <?= kartu('Judul Buku', (string) $ringkas['buku'], $ringkas['eksemplar'] . ' eksemplar', 'buku', 'hijau') ?>
  <?= kartu('Sedang Dipinjam', (string) $ringkas['dipinjam'], 'Buku belum dikembalikan', 'surat_keluar', 'emas') ?>
  <?= kartu('Kunjungan Tercatat', (string) $ringkas['kunjungan'], 'Seluruh buku tamu', 'absen', 'biru') ?>
</div>

<div class="tab mt16">
  <?php foreach (perpus_jenis() as $k => $label): ?>
    <a href="perpustakaan.php?jenis=<?= e($k) ?>&<?= e(perpus_qs($f)) ?>" class="<?= $jenis === $k ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <div class="card-kepala">
    <div>
      <h2><?= e($L['judul']) ?></h2>
      <p class="card-ket"><?= count($L['rows']) ?> baris ditampilkan · sumber: e-PERPUS (otomatis).</p>
    </div>
    <div class="card-aksi">
      <?php if ($L['rows']): ?>
        <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="<?= e($L['berkas']) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
        <a class="btn btn-garis" href="perpustakaan.php?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
        <?= tombol_cetak('cetak_perpus.php?' . $qCetak, 'Cetak / PDF') ?>
      <?php endif; ?>
    </div>
  </div>

  <form class="saring" method="get">
    <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
    <div class="medan cari"><label for="cari">Cari</label>
      <input type="search" id="cari" name="cari" value="<?= e($f['cari']) ?>" placeholder="<?= $jenis === 'pengunjung' ? 'Nama pengunjung, kelas, keperluan…' : ($jenis === 'buku' ? 'Judul, kode, pengarang…' : 'Kode, anggota, judul buku…') ?>"></div>
    <div class="medan"><label for="dari">Dari Tanggal</label><input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>" data-auto></div>
    <div class="medan"><label for="sampai">Sampai</label><input type="date" id="sampai" name="sampai" value="<?= e($f['sampai']) ?>" data-auto></div>
    <?php if ($jenis === 'pengunjung'): ?>
      <div class="medan"><label for="keperluan">Keperluan</label><select id="keperluan" name="keperluan" data-auto><?= opsi(array_combine($perKeperluan, $perKeperluan), $f['keperluan'], 'Semua keperluan') ?></select></div>
      <div class="medan"><label for="kelas">Kelas</label><select id="kelas" name="kelas" data-auto><?= opsi(array_combine($perKelas, $perKelas), $f['kelas'], 'Semua kelas') ?></select></div>
    <?php elseif ($jenis === 'buku'): ?>
      <div class="medan"><label for="kategori">Kategori</label><select id="kategori" name="kategori" data-auto><?= opsi(array_combine($perKategori, $perKategori), $f['kategori'], 'Semua kategori') ?></select></div>
    <?php else: ?>
      <div class="medan"><label for="status">Status</label>
        <select id="status" name="status" data-auto><?= opsi(['dipinjam' => 'Dipinjam', 'kembali' => 'Kembali', 'terlambat' => 'Terlambat'], $f['status'], 'Semua status') ?></select></div>
    <?php endif; ?>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if (perpus_qs($f) !== ''): ?><a class="btn btn-garis" href="perpustakaan.php?jenis=<?= e($jenis) ?>">Reset</a><?php endif; ?>
  </form>

  <?php if ($L['ringkas']): ?>
    <div class="tabel-ringkas">
      <?php foreach ($L['ringkas'] as $label => $nilai): ?>
        <span class="chip"><?= e($label) ?>: <b><?= e((string) $nilai) ?></b></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$L['rows']): ?>
    <div class="kosong"><?= e($L['kosong']) ?></div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <?php foreach ($L['kolom'] as $i => $judul): ?>
            <th class="<?= ($L['align'][$i] ?? 'l') === 'c' ? 'tengah' : (($L['align'][$i] ?? 'l') === 'r' ? 'num' : '') ?>"><?= e($judul) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($L['rows'] as $baris): ?>
          <tr>
            <?php foreach ($baris as $i => $sel): ?>
              <td class="<?= ($L['align'][$i] ?? 'l') === 'c' ? 'tengah' : (($L['align'][$i] ?? 'l') === 'r' ? 'num' : '') ?>"><?= $i === 1 && $jenis !== 'pengunjung' && $jenis !== 'buku' ? '<span class="mono">' . e((string) $sel) . '</span>' : e((string) $sel) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="kecil mut mt8 mb0">Data ini <b>bukan salinan</b>: bila e-PERPUS menambah/mengubah data, halaman ini ikut berubah otomatis.</p>
  <?php endif; ?>
</section>

<script type="application/json" id="dataEkspor"><?= json_encode([
    'judul' => $L['judul'],
    'sheet' => substr(str_replace('Laporan ', '', $L['judul']), 0, 28),
    'header' => $L['kolom'],
    'baris' => $L['rows'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php page_bottom(); ?>
