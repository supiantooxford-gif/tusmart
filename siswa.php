<?php
/**
 * Data Siswa: daftar, saring, hapus, ekspor (Excel/CSV), cetak, dan impor.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'hapus') {
    csrf_wajib();
    $id = idf('id');
    $s = satu('SELECT * FROM siswa WHERE id = ?', [$id]);
    if (!$s) {
        flash('Data siswa tidak ditemukan.', 'err');
    } else {
        q('DELETE FROM siswa WHERE id = ?', [$id]);
        log_catat('hapus_siswa', $s['nama']);
        flash('Data siswa ' . $s['nama'] . ' dihapus.');
    }
    redirect('siswa.php?' . http_build_query(array_filter(['cari' => f('cari'), 'kelas' => f('kelas'), 'status' => f('status')])));
}

$cari = f('cari');
$kelasId = idf('kelas');
$status = f('status');
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR s.nama_wali LIKE ? OR s.nama_ayah LIKE ? OR s.nama_ibu LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k);
}
if ($kelasId > 0) { $w[] = 's.kelas_id = ?'; $arg[] = $kelasId; }
if ($status !== '') { $w[] = 's.status = ?'; $arg[] = $status; }
$daftar = semua('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id'
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY k.urutan, k.nama, s.nama', $arg);

$ringkasStatus = [];
foreach (semua('SELECT status, COUNT(*) AS c FROM siswa GROUP BY status') as $r) {
    $ringkasStatus[$r['status']] = (int) $r['c'];
}

$headerEkspor = ['No', 'NIS', 'NISN', 'Nama Lengkap', 'Jenis Kelamin', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Nama Ayah', 'Nama Ibu', 'Nama Wali', 'Telepon', 'Alamat', 'Status'];
$barisEkspor = [];
foreach ($daftar as $i => $s) {
    $barisEkspor[] = [
        $i + 1, $s['nis'], $s['nisn'], $s['nama'], $s['jenis_kelamin'], $s['kelas_nama'] ?: '-',
        $s['tempat_lahir'], ekspor_tgl($s['tanggal_lahir']), $s['nama_ayah'], $s['nama_ibu'], $s['nama_wali'],
        $s['telepon'], $s['alamat'], status_siswa_label($s['status']),
    ];
}

if (f('unduh') === 'csv') {
    csv_kirim('data-siswa', $headerEkspor, $barisEkspor);
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Data Siswa', 'Administrasi kesiswaan');
?>
<div class="grid g4">
  <?= kartu('Siswa Aktif', (string) ($ringkasStatus['aktif'] ?? 0), 'Terdata di seluruh kelas', 'siswa', 'hijau') ?>
  <?= kartu('Lulus', (string) ($ringkasStatus['lulus'] ?? 0), 'Arsip kelulusan', 'buku', 'biru') ?>
  <?= kartu('Pindah / Keluar', (string) (($ringkasStatus['pindah'] ?? 0) + ($ringkasStatus['keluar'] ?? 0)), 'Mutasi keluar tercatat', 'surat_keluar', 'emas') ?>
  <?= kartu('Total Data', (string) array_sum($ringkasStatus), 'Seluruh siswa terdata', 'siswa', 'biru') ?>
</div>

<div class="card mt16">
  <div class="card-kepala">
    <div><h2>Daftar Siswa</h2>
      <p class="card-ket"><?= count($daftar) ?> data ditampilkan. Data induk, mutasi, dan buku klapper ada di menu <a href="buku_induk.php">Buku Induk &amp; Mutasi</a>.</p></div>
    <div class="card-aksi">
      <a class="btn" href="siswa_edit.php"><?= ikon('tambah') ?> Tambah Siswa</a>
      <a class="btn btn-garis" href="siswa_impor.php"><?= ikon('unggah') ?> Impor Excel/CSV</a>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="data-siswa"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?<?= e(http_build_query(array_filter(['cari' => $cari, 'kelas' => $kelasId ?: '', 'status' => $status, 'unduh' => 'csv']))) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_siswa.php?' . http_build_query(array_filter(['cari' => $cari, 'kelas' => $kelasId ?: '', 'status' => $status])), 'Cetak / PDF') ?>
    </div>
  </div>

  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label>
      <input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama, NIS, NISN, wali…"></div>
    <div class="medan"><label for="kelas">Kelas</label>
      <select id="kelas" name="kelas" data-auto>
        <option value="">Semua kelas</option>
        <?php foreach (kelas_list() as $k): ?><option value="<?= (int) $k['id'] ?>"<?= $kelasId === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="medan"><label for="status">Status</label>
      <select id="status" name="status" data-auto><?= opsi(status_siswa_list(), $status, 'Semua status') ?></select></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $kelasId > 0 || $status !== ''): ?><a class="btn btn-garis" href="siswa.php">Reset</a><?php endif; ?>
  </form>

  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada data siswa yang cocok. <a href="siswa_edit.php">Tambah siswa</a> atau <a href="siswa_impor.php">impor dari Excel/CSV</a>.</div>
  <?php else: ?>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr>
        <th class="tengah" style="width:42px">No</th><th>Nama / NIS</th><th class="tengah">JK</th><th>Kelas</th>
        <th>Tempat, Tanggal Lahir</th><th>Orang Tua / Wali</th><th>Kontak</th><th class="tengah">Status</th><th style="width:206px">Aksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $s): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($s['nama']) ?></b><span class="sel-sub">NIS: <?= e($s['nis'] ?: '-') ?> · NISN: <?= e($s['nisn'] ?: '-') ?></span></td>
          <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
          <td><?= e($s['kelas_nama'] ?: '-') ?></td>
          <td><?= e(trim(($s['tempat_lahir'] ?: '-') . ', ' . ($s['tanggal_lahir'] ? tanggal_id($s['tanggal_lahir']) : '-'), ', ')) ?></td>
          <td><?= e($s['nama_wali'] ?: ($s['nama_ayah'] ?: '-')) ?><span class="sel-sub"><?= e(trim('Ayah: ' . ($s['nama_ayah'] ?: '-') . ' · Ibu: ' . ($s['nama_ibu'] ?: '-'))) ?></span></td>
          <td><?= e($s['telepon'] ?: '-') ?></td>
          <td class="tengah"><span class="pil <?= $s['status'] === 'aktif' ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= e(status_siswa_label($s['status'])) ?></span></td>
          <td>
            <div class="aksi">
              <a class="btn btn-garis btn-kecil" href="cetak_buku_induk_siswa.php?id=<?= (int) $s['id'] ?>" target="_blank" rel="noopener"
                title="Cetak buku induk siswa (1 lembar)"><?= ikon('cetak') ?> Buku Induk</a>
              <a class="btn btn-garis btn-kecil" href="siswa_edit.php?id=<?= (int) $s['id'] ?>"><?= ikon('ubah') ?> Ubah</a>
              <form method="post" data-konfirmasi="Hapus data siswa <?= e($s['nama']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="cari" value="<?= e($cari) ?>"><input type="hidden" name="kelas" value="<?= $kelasId ?: '' ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
                <button class="btn btn-bahaya btn-garis btn-kecil" type="submit"><?= ikon('hapus') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Data Siswa', 'sheet' => 'Data Siswa', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
