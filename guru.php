<?php
/**
 * Data Guru & Tenaga Kependidikan: daftar, saring, hapus, ekspor (Excel/CSV), cetak.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

// ---------- hapus ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'hapus') {
    csrf_wajib();
    $id = idf('id');
    $g = satu('SELECT * FROM guru WHERE id = ?', [$id]);
    if (!$g) {
        flash('Data guru tidak ditemukan (mungkin sudah dihapus).', 'err');
    } else {
        q('DELETE FROM guru WHERE id = ?', [$id]);
        log_catat('hapus_guru', $g['nama']);
        flash('Data ' . $g['nama'] . ' dihapus.');
    }
    redirect('guru.php?' . http_build_query(array_filter(['cari' => f('cari'), 'jabatan' => f('jabatan'), 'status' => f('status')])));
}

$cari = f('cari');
$jabatan = f('jabatan');
$status = f('status');

$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(nama LIKE ? OR nip LIKE ? OR nuptk LIKE ? OR mapel LIKE ? OR email LIKE ? OR telepon LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k);
}
if ($jabatan !== '') { $w[] = 'jabatan = ?'; $arg[] = $jabatan; }
if ($status !== '') { $w[] = 'aktif = ?'; $arg[] = $status === 'aktif' ? 1 : 0; }
$sql = 'SELECT * FROM guru' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY urutan, nama';
$daftar = semua($sql, $arg);

$daftarJabatan = [];
foreach (semua("SELECT DISTINCT jabatan FROM guru WHERE jabatan IS NOT NULL AND jabatan <> '' ORDER BY jabatan") as $r) {
    $daftarJabatan[$r['jabatan']] = $r['jabatan'];
}

// ---------- ekspor CSV ----------
if (f('unduh') === 'csv') {
    $baris = [];
    foreach ($daftar as $i => $g) {
        $baris[] = [
            $i + 1, $g['nama'], $g['nip'], $g['nuptk'], $g['jenis_kelamin'], $g['jabatan'], $g['mapel'],
            $g['email'], $g['telepon'], $g['alamat'], ekspor_tgl($g['tmt']), $g['keterangan'], (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif',
        ];
    }
    csv_kirim('data-guru-tenaga-kependidikan', ['No', 'Nama', 'NIP', 'NUPTK', 'Jenis Kelamin', 'Jabatan', 'Mata Pelajaran', 'Email', 'Telepon', 'Alamat', 'TMT', 'Keterangan', 'Status'], $baris);
}

// ---------- data Excel ----------
$headerEkspor = ['No', 'Nama', 'NIP', 'NUPTK', 'Jenis Kelamin', 'Jabatan', 'Mata Pelajaran', 'Email', 'Telepon', 'Alamat', 'TMT', 'Keterangan', 'Status'];
$barisEkspor = [];
foreach ($daftar as $i => $g) {
    $barisEkspor[] = [
        $i + 1, $g['nama'], $g['nip'], $g['nuptk'], $g['jenis_kelamin'], $g['jabatan'], $g['mapel'],
        $g['email'], $g['telepon'], $g['alamat'], ekspor_tgl($g['tmt']), $g['keterangan'], (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif',
    ];
}
$GLOBALS['__butuh_xlsx'] = true;
page_top('Data Guru & Tenaga Kependidikan', 'Administrasi kepegawaian');
?>
<div class="card">
  <div class="card-kepala">
    <div>
      <h2>Daftar Guru &amp; Tenaga Kependidikan</h2>
      <p class="card-ket"><?= count($daftar) ?> data ditampilkan · <?= (int) kolom('SELECT COUNT(*) FROM guru WHERE aktif = 1') ?> aktif dari <?= (int) kolom('SELECT COUNT(*) FROM guru') ?> total.</p>
    </div>
    <div class="card-aksi">
      <a class="btn" href="guru_edit.php"><?= ikon('tambah') ?> Tambah Data</a>
      <a class="btn btn-garis" href="guru_impor.php"><?= ikon('unggah') ?> Impor Excel/CSV</a>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="data-guru-tenaga-kependidikan"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?<?= e(http_build_query(array_filter(['cari' => $cari, 'jabatan' => $jabatan, 'status' => $status, 'unduh' => 'csv']))) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_guru.php?' . http_build_query(array_filter(['cari' => $cari, 'jabatan' => $jabatan, 'status' => $status])), 'Cetak / PDF') ?>
    </div>
  </div>

  <form class="saring" method="get">
    <div class="medan cari">
      <label for="cari">Cari</label>
      <input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama, NIP, mapel, email, telepon…">
    </div>
    <div class="medan">
      <label for="jabatan">Jabatan</label>
      <select id="jabatan" name="jabatan" data-auto><?= opsi($daftarJabatan, $jabatan, 'Semua jabatan') ?></select>
    </div>
    <div class="medan">
      <label for="status">Status</label>
      <select id="status" name="status" data-auto><?= opsi(['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'], $status, 'Semua status') ?></select>
    </div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $jabatan !== '' || $status !== ''): ?>
      <a class="btn btn-garis" href="guru.php">Reset</a>
    <?php endif; ?>
  </form>

  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada data yang cocok. <a href="guru_edit.php">Tambah data baru</a> atau <a href="guru_impor.php">impor dari Excel/CSV</a>.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:44px">No</th><th>Nama / NIP</th><th class="tengah">JK</th>
          <th>Jabatan</th><th>Mata Pelajaran</th><th>Kontak</th><th class="tengah">TMT</th><th class="tengah">Status</th><th style="width:130px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $g): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><b><?= e($g['nama']) ?></b>
              <span class="sel-sub">NIP: <?= e($g['nip'] !== '' && $g['nip'] !== null ? $g['nip'] : '-') ?><?= $g['nuptk'] ? ' · NUPTK: ' . e($g['nuptk']) : '' ?></span>
            </td>
            <td class="tengah"><?= e($g['jenis_kelamin']) ?></td>
            <td><?= e($g['jabatan'] ?: '-') ?><?= $g['keterangan'] ? '<span class="sel-sub">' . e($g['keterangan']) . '</span>' : '' ?></td>
            <td><?= e($g['mapel'] ?: '-') ?></td>
            <td><?= e($g['telepon'] ?: '-') ?><span class="sel-sub"><?= e($g['email'] ?: '') ?></span></td>
            <td class="tengah nowrap"><?= e($g['tmt'] ? tanggal_pendek($g['tmt']) : '-') ?></td>
            <td class="tengah"><span class="pil <?= (int) $g['aktif'] === 1 ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td>
              <div class="aksi">
                <a class="btn btn-garis btn-kecil" href="guru_edit.php?id=<?= (int) $g['id'] ?>"><?= ikon('ubah') ?> Ubah</a>
                <form method="post" data-konfirmasi="Hapus data <?= e($g['nama']) ?>? Tindakan ini tidak dapat dibatalkan.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <input type="hidden" name="cari" value="<?= e($cari) ?>"><input type="hidden" name="jabatan" value="<?= e($jabatan) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
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

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Data Guru dan Tenaga Kependidikan', 'sheet' => 'Data Guru', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
