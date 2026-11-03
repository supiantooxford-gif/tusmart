<?php
/**
 * Dashboard TUSmart: ringkasan kepegawaian, kesiswaan, persuratan, sarpras, dan rapat.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$hariIni = tanggal_hari_ini();
$awalBulan = date('Y-m-01');
$b = bulan_id();

$guruAktif = (int) kolom("SELECT COUNT(*) FROM guru WHERE aktif = 1");
$tendik = (int) kolom("SELECT COUNT(*) FROM guru WHERE aktif = 1 AND jabatan IS NOT NULL AND jabatan <> '' AND jabatan NOT LIKE 'Guru%'");
$siswaAktif = (int) kolom("SELECT COUNT(*) FROM siswa WHERE status = 'aktif'");
$rombel = (int) kolom('SELECT COUNT(*) FROM kelas WHERE aktif = 1');
$kelasBerguru = (int) kolom("SELECT COUNT(DISTINCT kelas_id) FROM siswa WHERE status = 'aktif' AND kelas_id IS NOT NULL");
$suratMasukBulan = (int) kolom('SELECT COUNT(*) FROM surat_masuk WHERE substr(tanggal_terima,1,7) = ?', [date('Y-m')]);
$suratKeluarBulan = (int) kolom('SELECT COUNT(*) FROM surat_keluar WHERE substr(tanggal_surat,1,7) = ?', [date('Y-m')]);
$belumVerif = (int) kolom("SELECT COUNT(*) FROM surat_keluar WHERE status = 'draf'");
$arsipAktif = (int) kolom("SELECT COUNT(*) FROM arsip WHERE status = 'aktif'");
$arsipInaktif = (int) kolom("SELECT COUNT(*) FROM arsip WHERE status = 'inaktif'");
$notulenJumlah = (int) kolom('SELECT COUNT(*) FROM notulen');
$ringkasAbsen = absensi_hari_ini_ringkas($hariIni);
$absenHariIni = (int) $ringkasAbsen['direkam'];
$hadirHariIni = (int) $ringkasAbsen['hadir'];
$jadwalDash = jam_kerja_hari($hariIni);
$mutasiBulan = (int) kolom('SELECT COUNT(*) FROM mutasi WHERE substr(tanggal,1,7) = ?', [date('Y-m')]);

$sarprasAda = sarpras_ada();
$sarprasRingkas = ['baris' => 0, 'unit' => 0, 'rusak' => 0];
if ($sarprasAda) {
    $sarprasRingkas = sarpras_ringkas(sarpras_list());
}

// --- grafik surat 6 bulan terakhir ---
$labelBulan = [];
$dataMasuk = [];
$dataKeluar = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . ' -' . $i . ' month');
    $ym = date('Y-m', $ts);
    $labelBulan[] = substr($b[(int) date('n', $ts)], 0, 3) . ' ' . date('y', $ts);
    $dataMasuk[] = (int) kolom('SELECT COUNT(*) FROM surat_masuk WHERE substr(tanggal_terima,1,7) = ?', [$ym]);
    $dataKeluar[] = (int) kolom('SELECT COUNT(*) FROM surat_keluar WHERE substr(tanggal_surat,1,7) = ?', [$ym]);
}

// --- grafik siswa per kelas ---
$labelKelas = [];
$dataKelas = [];
foreach (kelas_list(true) as $k) {
    $labelKelas[] = $k['nama'];
    $dataKelas[] = (int) kolom("SELECT COUNT(*) FROM siswa WHERE kelas_id = ? AND status = 'aktif'", [(int) $k['id']]);
}

$masukTerbaru = semua('SELECT * FROM surat_masuk ORDER BY COALESCE(tanggal_terima, tanggal_surat) DESC, id DESC LIMIT 5');
$keluarTerbaru = semua('SELECT * FROM surat_keluar ORDER BY COALESCE(tanggal_surat, dibuat) DESC, id DESC LIMIT 5');
$notulenTerbaru = semua('SELECT * FROM notulen ORDER BY tanggal DESC, id DESC LIMIT 4');

$GLOBALS['__butuh_xlsx'] = false;
page_top('Dashboard', 'Ringkasan tata usaha ' . lembaga_nama());
?>

<div class="grid g4">
  <?= kartu('Guru & Tendik', (string) $guruAktif, $tendik . ' tenaga kependidikan', 'guru', 'biru') ?>
  <?= kartu('Siswa Aktif', (string) $siswaAktif, $kelasBerguru . ' dari ' . $rombel . ' kelas terisi', 'siswa', 'hijau') ?>
  <?= kartu('Surat Masuk Bulan Ini', (string) $suratMasukBulan, $b[(int) date('n')] . ' ' . date('Y'), 'surat_masuk', 'emas') ?>
  <?= kartu('Surat Keluar Bulan Ini', (string) $suratKeluarBulan, $belumVerif . ' draf menunggu verifikasi', 'surat_keluar', 'merah') ?>
</div>

<div class="grid g4 mt16">
  <?= kartu('Absensi Hari Ini', $absenHariIni > 0 ? $hadirHariIni . '/' . $guruAktif : ($jadwalDash['libur'] ? 'Libur' : 'Belum direkam'),
      $absenHariIni > 0 ? 'Telat ' . (int) $ringkasAbsen['telat'] . ' · Pulang cepat ' . (int) $ringkasAbsen['cepat']
        : ($jadwalDash['libur'] ? $jadwalDash['keterangan'] . ' — absensi tidak wajib'
          : $jadwalDash['nama'] . ': masuk ≤ ' . $jadwalDash['masuk'] . ' · pulang ≥ ' . $jadwalDash['pulang']),
      'absen', $absenHariIni > 0 ? 'hijau' : ($jadwalDash['libur'] ? 'biru' : 'emas')) ?>
  <?= kartu('Arsip Digital', (string) $arsipAktif, $arsipInaktif . ' arsip inaktif', 'arsip', 'biru') ?>
  <?= kartu('Inventaris Sarpras', $sarprasAda ? (string) $sarprasRingkas['baris'] : '-', $sarprasAda ? $sarprasRingkas['unit'] . ' unit · ' . $sarprasRingkas['rusak'] . ' unit rusak' : 'Sumber e-GURU belum terhubung', 'sarpras', 'hijau') ?>
  <?= kartu('Notulen Rapat', (string) $notulenJumlah, $mutasiBulan . ' mutasi siswa bulan ini', 'notulen', 'emas') ?>
</div>

<div class="grid g21 mt16">
  <section class="card">
    <div class="card-kepala">
      <div><h2>Arus Surat 6 Bulan Terakhir</h2><p class="card-ket">Jumlah surat masuk dan surat keluar per bulan.</p></div>
    </div>
    <div style="position:relative;height:260px"><canvas id="chSurat" aria-label="Grafik arus surat" role="img"></canvas></div>
  </section>
  <section class="card">
    <div class="card-kepala"><div><h2>Aksi Cepat</h2></div></div>
    <div class="form-grid">
      <a class="btn btn-garis" href="surat_masuk.php?aksi=baru"><?= ikon('surat_masuk') ?> Registrasi Surat Masuk</a>
      <a class="btn btn-garis" href="surat_keluar.php?aksi=baru"><?= ikon('surat_keluar') ?> Buat Surat Keluar</a>
      <a class="btn btn-garis" href="absensi_guru.php"><?= ikon('absen') ?> Rekam Absensi Hari Ini</a>
      <a class="btn btn-garis" href="laporan_absensi.php"><?= ikon('cetak') ?> Laporan Absensi</a>
      <a class="btn btn-garis" href="siswa.php?aksi=baru"><?= ikon('siswa') ?> Tambah Data Siswa</a>
      <a class="btn btn-garis" href="notulen_form.php"><?= ikon('notulen') ?> Buat Notulen Rapat</a>
    </div>
    <?php if ($jadwalDash['libur']): ?>
      <div class="alert alert-info mt16 mb0"><b><?= e(tanggal_id($hariIni, true)) ?> hari libur</b> —
        <?= e($jadwalDash['keterangan']) ?>. Absensi tidak wajib direkam.</div>
    <?php elseif ($absenHariIni < $guruAktif): ?>
      <div class="alert alert-warn mt16 mb0">Absensi <b><?= e(tanggal_id($hariIni, true)) ?></b> belum lengkap
        (<?= $absenHariIni ?> dari <?= $guruAktif ?> guru/tenaga kependidikan).
        <a href="absensi_guru.php?tanggal=<?= e($hariIni) ?>">Lengkapi sekarang</a>.</div>
    <?php endif; ?>
  </section>
</div>

<div class="grid g2 mt16">
  <section class="card">
    <div class="card-kepala"><div><h2>Sebaran Siswa per Kelas</h2><p class="card-ket">Siswa berstatus aktif.</p></div></div>
    <div style="position:relative;height:240px"><canvas id="chKelas" aria-label="Grafik siswa per kelas" role="img"></canvas></div>
  </section>
  <section class="card">
    <div class="card-kepala">
      <div><h2>Surat Masuk Terbaru</h2></div>
      <div class="card-aksi"><a class="btn btn-garis btn-kecil" href="surat_masuk.php">Semua</a></div>
    </div>
    <?php if (!$masukTerbaru): ?>
      <div class="kosong">Belum ada surat masuk yang diregistrasi.</div>
    <?php else: ?>
      <div class="daftar-rapi">
        <?php foreach ($masukTerbaru as $s): ?>
          <li><?= ikon('surat_masuk') ?><span><b><?= e($s['no_agenda']) ?></b> · <?= e($s['perihal']) ?><br>
            <span class="mut kecil"><?= e($s['pengirim'] ?: '-') ?> · <?= e(tanggal_id($s['tanggal_terima'] ?: $s['tanggal_surat'])) ?></span></span></li>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<div class="grid g2 mt16">
  <section class="card">
    <div class="card-kepala">
      <div><h2>Surat Keluar Terbaru</h2></div>
      <div class="card-aksi"><a class="btn btn-garis btn-kecil" href="surat_keluar.php">Semua</a></div>
    </div>
    <?php if (!$keluarTerbaru): ?>
      <div class="kosong">Belum ada surat keluar.</div>
    <?php else: ?>
      <div class="daftar-rapi">
        <?php foreach ($keluarTerbaru as $s): $st = status_surat_keluar()[$s['status']] ?? 'Draf'; ?>
          <li><?= ikon('surat_keluar') ?><span><b><?= e($s['no_surat']) ?></b> · <?= e($s['perihal']) ?>
            <?php if (surat_jenis_sah((string) ($s['jenis'] ?? '')) !== SURAT_JENIS_UMUM): ?>
              <span class="chip emas"><?= e(surat_jenis_label($s['jenis'] ?? '')) ?></span>
            <?php endif; ?><br>
            <span class="mut kecil"><?= e($s['tujuan'] ?: '-') ?> · <?= e(tanggal_id($s['tanggal_surat'])) ?> · <span class="pil pil-<?= e($s['status']) ?>"><?= e($st) ?></span></span></span></li>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="card">
    <div class="card-kepala">
      <div><h2>Notulen Rapat Terbaru</h2></div>
      <div class="card-aksi"><a class="btn btn-garis btn-kecil" href="notulen.php">Semua</a></div>
    </div>
    <?php if (!$notulenTerbaru): ?>
      <div class="kosong">Belum ada notulen rapat.</div>
    <?php else: ?>
      <div class="daftar-rapi">
        <?php foreach ($notulenTerbaru as $n): ?>
          <li><?= ikon('notulen') ?><span><b><?= e($n['judul']) ?></b><br>
            <span class="mut kecil"><?= e(tanggal_id($n['tanggal'], true)) ?> · <?= e($n['tempat'] ?: '-') ?></span></span></li>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<script type="application/json" id="dataGrafik"><?= json_encode([
    'surat' => ['label' => $labelBulan, 'masuk' => $dataMasuk, 'keluar' => $dataKeluar],
    'kelas' => ['label' => $labelKelas, 'data' => $dataKelas],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function () {
  var el = document.getElementById('dataGrafik');
  if (!el || typeof Chart === 'undefined') { return; }
  var d = {};
  try { d = JSON.parse(el.textContent || '{}'); } catch (e) { return; }
  var biru = '#1d4ed8', emas = '#d4a72c', hijau = '#0f7a52';
  Chart.defaults.font.family = 'Inter, Segoe UI, system-ui, sans-serif';
  var cs = document.getElementById('chSurat');
  if (cs && d.surat) {
    new Chart(cs, {
      type: 'line',
      data: {
        labels: d.surat.label,
        datasets: [
          { label: 'Surat Masuk', data: d.surat.masuk, borderColor: biru, backgroundColor: 'rgba(29,78,216,.14)', tension: .32, fill: true, borderWidth: 2.4, pointRadius: 3 },
          { label: 'Surat Keluar', data: d.surat.keluar, borderColor: emas, backgroundColor: 'rgba(212,167,44,.12)', tension: .32, fill: true, borderWidth: 2.4, pointRadius: 3 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }
  var ck = document.getElementById('chKelas');
  if (ck && d.kelas) {
    new Chart(ck, {
      type: 'bar',
      data: { labels: d.kelas.label, datasets: [{ label: 'Siswa aktif', data: d.kelas.data, backgroundColor: hijau, borderRadius: 8, maxBarThickness: 54 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }
})();
</script>
<?php page_bottom(); ?>
