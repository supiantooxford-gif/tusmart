<?php
/**
 * Laporan Absensi-Guru — SELURUH laporan aplikasi **Absensi Guru** (kehadiran guru
 * berbasis foto & titik koordinat), dibaca OTOMATIS & hanya-baca dari basis datanya.
 *
 * Laporan: Absensi Hari Ini · Per Hari · Rekap Per Bulan (semua guru) · Per Bulan per Guru ·
 * Matriks Harian per Guru · Overtime · Kunjungan.
 *
 * Karena dibaca langsung, setiap absensi yang diisi guru pada aplikasi Absensi Guru
 * langsung muncul di sini — tidak ada proses kirim/sinkronisasi yang perlu dijalankan.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$ada = absguru_ada();
$jenis = f('jenis', 'hari_ini');
if (!absguru_jenis_sah($jenis)) { $jenis = 'hari_ini'; }
$fl = absguru_filter();
$info = absguru_info();
$daftarGuru = $ada ? absguru_guru(true) : [];
$ringkas = $ada ? absguru_ringkas() : ['guru' => 0, 'hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'alpha' => 0,
    'kunjungan' => 0, 'overtime' => 0, 'overtime_n' => 0, 'tanggal' => tanggal_hari_ini(), 'bulan' => date('Y-m')];

// Guru terpilih: tab "Per Bulan per Guru" WAJIB satu guru (bila belum dipilih, pakai yang pertama).
// Tab lain (matriks, overtime, kunjungan) tetap bisa "Semua guru" — jangan dipaksa satu guru.
$guruPilih = absguru_guru_by_id((int) $fl['guru']);
if ($guruPilih === null && $jenis === 'guru') {
    $guruPilih = $daftarGuru[0] ?? null;
}
$fl['guru'] = (int) ($guruPilih['id'] ?? 0);

$L = $ada ? absguru_laporan($jenis, $fl) : ['judul' => absguru_jenis()[$jenis], 'berkas' => 'laporan',
    'meta' => [], 'kartu' => [], 'tabel' => [], 'kosong' => 'Basis data aplikasi Absensi Guru belum terhubung.', 'matriks' => null];

// ------------------------------- ekspor CSV -------------------------------
if ($ada && f('unduh') === 'csv') {
    $E = absguru_ekspor($L);
    $baris = [];
    foreach ($E['meta'] as $label => $nilai) {
        $baris[] = [$label, (string) $nilai];
    }
    if ($baris) { $baris[] = ['', '']; }
    $baris[] = $E['header'];
    foreach ($E['rows'] as $r) {
        $baris[] = $r;
    }
    csv_kirim($E['berkas'], ['Laporan', (string) $E['judul']], $baris);
}

$qCetak = absguru_qs($fl, ['jenis' => $jenis]);
$E = $ada ? absguru_ekspor($L) : ['judul' => '', 'sheet' => '', 'header' => [], 'rows' => [], 'berkas' => '', 'meta' => []];
$GLOBALS['__butuh_xlsx'] = $ada && $E['rows'] !== [];
$butuhTanggal = in_array($jenis, ['hari_ini', 'hari'], true);
$butuhBulan = !$butuhTanggal;
$butuhGuru = in_array($jenis, ['guru', 'matriks', 'overtime', 'kunjungan'], true);

page_top('Laporan Absensi-Guru', 'Administrasi kepegawaian · seluruh laporan aplikasi Absensi Guru');
?>
<div class="alert alert-info">
  <div>
    <b>Sumber data:</b> aplikasi <b>Absensi Guru</b><?= $ada ? ' — ' . e($info['nama']) : '' ?>.
    Seluruh laporan di halaman ini <b>terisi otomatis</b> setiap kali guru mengisi absensi pada aplikasi
    Absensi Guru (data dibaca langsung &amp; hanya-baca — TUSmart tidak dapat mengubah data absensi).
    <?php if (!$ada): ?>
      <br><b>Belum terhubung:</b> berkas basis data aplikasi Absensi Guru tidak ditemukan pada
      <span class="mono"><?= e(absguru_sumber_aktif() !== '' ? absguru_sumber_aktif() : setting('absguru_sumber')) ?></span>.
      Periksa jalurnya pada <a href="pengaturan_lembaga.php">Pengaturan Data Lembaga</a> (khusus Super Admin).
    <?php endif; ?>
  </div>
</div>

<?php if ($ada): ?>
<div class="grid g4">
  <?= kartu('Guru Aktif', (string) $ringkas['guru'], 'terdaftar di aplikasi absensi', 'guru', 'biru') ?>
  <?= kartu('Hadir Hari Ini', (string) $ringkas['hadir'], 'terlambat ' . $ringkas['terlambat'] . ' guru', 'absen', 'hijau') ?>
  <?= kartu('Izin / Cuti / Sakit', (string) $ringkas['izin'], 'belum absen ' . $ringkas['alpha'], 'surat_masuk', 'emas') ?>
  <?= kartu('Kunjungan Bulan Ini', (string) $ringkas['kunjungan'], 'overtime ' . absguru_menit((int) $ringkas['overtime']), 'arsip', 'merah') ?>
</div>

<div class="tab mt16">
  <?php foreach (absguru_jenis() as $k => $label): ?>
    <a href="laporan_absensi_guru.php?jenis=<?= e($k) ?>&<?= e(absguru_qs(['bulan' => $fl['bulan'], 'tanggal' => $fl['tanggal'], 'guru' => $fl['guru']])) ?>" class="<?= $jenis === $k ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <div class="card-kepala">
    <div>
      <h2><?= e($L['judul']) ?></h2>
      <p class="card-ket">
        <?php
        $metaTeks = [];
        foreach ($L['meta'] as $label => $nilai) {
            $metaTeks[] = $label . ': ' . $nilai;
        }
        echo e(implode(' · ', $metaTeks));
        ?>
      </p>
    </div>
    <div class="card-aksi">
      <?php if ($E['rows'] !== []): ?>
        <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="<?= e(preg_replace('/\.csv$/', '', $E['berkas'])) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
        <a class="btn btn-garis" href="laporan_absensi_guru.php?unduh=csv&jenis=<?= e($jenis) ?>&<?= e(absguru_qs($fl)) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?php endif; ?>
      <?= tombol_cetak('cetak_laporan_absensi_guru.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>

  <form class="saring" method="get">
    <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
    <?php if ($butuhTanggal): ?>
      <div class="medan"><label for="tanggal">Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($fl['tanggal']) ?>" data-auto></div>
    <?php endif; ?>
    <?php if ($butuhBulan): ?>
      <div class="medan"><label for="bulan">Bulan</label>
        <input type="month" id="bulan" name="bulan" value="<?= e($fl['bulan']) ?>" data-auto></div>
    <?php endif; ?>
    <?php if ($butuhGuru): ?>
      <div class="medan"><label for="guru">Guru</label>
        <select id="guru" name="guru" data-auto>
          <?php if ($jenis !== 'guru'): ?><option value="0">Semua guru</option><?php endif; ?>
          <?php foreach ($daftarGuru as $g): ?>
            <option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === (int) $fl['guru'] ? ' selected' : '' ?>><?= e((string) $g['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($jenis === 'bulan'): ?>
      <a class="btn btn-garis" href="laporan_absensi_guru.php?jenis=guru&bulan=<?= e($fl['bulan']) ?>"><?= ikon('guru') ?> Rincian per guru</a>
    <?php elseif ($jenis === 'guru'): ?>
      <a class="btn btn-garis" href="laporan_absensi_guru.php?jenis=bulan&bulan=<?= e($fl['bulan']) ?>"><?= ikon('buku') ?> Rekap semua guru</a>
    <?php elseif ($jenis === 'hari_ini'): ?>
      <a class="btn btn-garis" href="laporan_absensi_guru.php?jenis=hari&tanggal=<?= e($fl['tanggal']) ?>"><?= ikon('absen') ?> Pilih tanggal lain</a>
    <?php endif; ?>
  </form>

  <?php if (($L['kartu'] ?? []) !== []): ?>
    <div class="grid g4 mb16">
      <?php foreach ($L['kartu'] as $k): ?>
        <?= kartu((string) $k[0], (string) $k[1], (string) $k[2], 'cek', (string) ($k[3] ?? '')) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$ada): ?>
    <div class="kosong">Basis data aplikasi Absensi Guru belum terhubung.</div>
  <?php elseif ($jenis === 'matriks'): ?>
    <?php if (($L['matriks']['guru'] ?? []) === []): ?>
      <div class="kosong"><?= e($L['kosong']) ?></div>
    <?php else: ?>
      <?= absguru_matriks_html($L['matriks']) ?>
    <?php endif; ?>
  <?php else: ?>
    <?php foreach ($L['tabel'] as $t): ?>
      <?= absguru_tabel_html($t) ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <p class="kecil mut mt12 mb0">
    Data ini <b>bukan salinan</b>: bila guru mengisi/mengubah absensi pada aplikasi Absensi Guru,
    halaman ini ikut berubah otomatis. Sumber: <span class="mono"><?= e(absguru_sumber_aktif()) ?></span>
  </p>
</section>

<script type="application/json" id="dataEkspor"><?= json_encode([
    'judul' => $E['judul'],
    'sheet' => $E['sheet'],
    'header' => $E['header'],
    'baris' => $E['rows'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php page_bottom(); ?>
