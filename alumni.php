<?php
/**
 * Menu Alumni — siswa yang telah DILULUSKAN (status 'lulus').
 * Dilengkapi pencarian, saringan tahun lulus/kelas, ekspor Excel & CSV, serta cetak.
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

$tahunAda = semua("SELECT DISTINCT substr(tanggal_status,1,4) AS th FROM siswa WHERE status = 'lulus' AND IFNULL(tanggal_status,'') <> '' ORDER BY th DESC");
$kelasAda = semua("SELECT DISTINCT k.nama FROM siswa s JOIN kelas k ON k.id = s.kelas_id WHERE s.status = 'lulus' ORDER BY k.nama");
$totalAlumni = (int) kolom("SELECT COUNT(*) FROM siswa WHERE status = 'lulus'");
$tahunIni = (int) kolom("SELECT COUNT(*) FROM siswa WHERE status = 'lulus' AND substr(tanggal_status,1,4) = ?", [date('Y')]);

$headerEkspor = ['No', 'NIS', 'NISN', 'Nama Lengkap', 'Jenis Kelamin', 'Kelas Terakhir', 'Tempat Lahir', 'Tanggal Lahir', 'Nama Ayah', 'Nama Ibu', 'Telepon', 'Alamat', 'Tanggal Lulus', 'Tahun Lulus'];
$barisEkspor = [];
foreach ($daftar as $i => $s) {
    $barisEkspor[] = [
        $i + 1, $s['nis'], $s['nisn'], $s['nama'], $s['jenis_kelamin'], $s['kelas_nama'] ?: '-',
        $s['tempat_lahir'], ekspor_tgl($s['tanggal_lahir']), $s['nama_ayah'], $s['nama_ibu'],
        $s['telepon'], $s['alamat'], ekspor_tgl($s['tanggal_status']), substr((string) $s['tanggal_status'], 0, 4),
    ];
}
if (f('unduh') === 'csv') {
    csv_kirim('data-alumni', $headerEkspor, $barisEkspor);
}
$qCetak = http_build_query(array_filter(['cari' => $cari, 'tahun' => $tahun, 'kelas' => $kelas]));

$GLOBALS['__butuh_xlsx'] = true;
page_top('Alumni', 'Administrasi kesiswaan · siswa yang telah diluluskan');
?>
<div class="grid g4">
  <?= kartu('Total Alumni', (string) $totalAlumni, 'Seluruh siswa lulus', 'pengguna', 'biru') ?>
  <?= kartu('Lulus Tahun ' . date('Y'), (string) $tahunIni, 'Tahun berjalan', 'buku', 'hijau') ?>
  <?= kartu('Tampil Sekarang', (string) count($daftar), 'Sesuai saringan', 'saring', 'emas') ?>
  <?= kartu('Tahun Lulus', (string) count($tahunAda), 'Tahun tercatat', 'absen', 'biru') ?>
</div>

<section class="card mt16">
  <div class="card-kepala">
    <div><h2>Daftar Alumni</h2>
      <p class="card-ket">Siswa masuk ke daftar ini setelah diproses pada menu <a href="kenaikan.php">Kenaikan &amp; Kelulusan</a>.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="data-alumni"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="alumni.php?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_alumni.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>

  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama, NIS, NISN, wali…"></div>
    <div class="medan"><label for="tahun">Tahun Lulus</label>
      <select id="tahun" name="tahun" data-auto>
        <option value="">Semua tahun</option>
        <?php foreach ($tahunAda as $t): ?><option value="<?= e($t['th']) ?>"<?= $tahun === $t['th'] ? ' selected' : '' ?>><?= e($t['th']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="medan"><label for="kelas">Kelas Terakhir</label>
      <select id="kelas" name="kelas" data-auto>
        <option value="">Semua kelas</option>
        <?php foreach ($kelasAda as $k): ?><option value="<?= e($k['nama']) ?>"<?= $kelas === $k['nama'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option><?php endforeach; ?>
      </select></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $tahun !== '' || $kelas !== ''): ?><a class="btn btn-garis" href="alumni.php">Reset</a><?php endif; ?>
  </form>

  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada alumni<?= $totalAlumni > 0 ? ' yang cocok dengan saringan ini' : '' ?>.
      <?php if ($totalAlumni === 0): ?>Luluskan siswa lewat menu <a href="kenaikan.php">Kenaikan &amp; Kelulusan</a>.<?php endif; ?></div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th>Nama Alumni</th><th class="tengah">NIS / NISN</th>
          <th class="tengah">JK</th><th>Kelas Terakhir</th><th class="tengah">Tanggal Lulus</th>
          <th class="tengah">Tahun Lulus</th><th>Tempat, Tanggal Lahir</th><th>Kontak</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $s): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><b><?= e($s['nama']) ?></b><span class="sel-sub">Wali: <?= e($s['nama_wali'] ?: ($s['nama_ayah'] ?: '-')) ?></span></td>
            <td class="tengah"><?= e($s['nis'] ?: '-') ?><span class="sel-sub"><?= e($s['nisn'] ?: '-') ?></span></td>
            <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
            <td><?= e($s['kelas_nama'] ?: '-') ?></td>
            <td class="tengah nowrap"><?= e($s['tanggal_status'] ? tanggal_id($s['tanggal_status']) : '-') ?></td>
            <td class="tengah"><span class="chip hijau"><?= e(substr((string) $s['tanggal_status'], 0, 4) ?: '-') ?></span></td>
            <td><?= e(trim(($s['tempat_lahir'] ?: '-') . ', ' . ($s['tanggal_lahir'] ? tanggal_id($s['tanggal_lahir']) : '-'), ', ')) ?></td>
            <td><?= e($s['telepon'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="3">JUMLAH ALUMNI</td>
          <td colspan="6" class="tengah"><?= count($daftar) ?> orang</td>
        </tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</section>

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Data Alumni', 'sheet' => 'Alumni', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
