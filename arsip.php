<?php
/**
 * Arsip Digital: penyimpanan arsip aktif & inaktif (otomatis dari berkas surat yang diunggah).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();
    $id = idf('id');
    $a = $id > 0 ? satu('SELECT * FROM arsip WHERE id = ?', [$id]) : null;
    if (!$a) {
        flash('Arsip tidak ditemukan.', 'err');
        redirect('arsip.php');
    }
    if ($aksi === 'toggle') {
        $baru = $a['status'] === 'aktif' ? 'inaktif' : 'aktif';
        q('UPDATE arsip SET status = ? WHERE id = ?', [$baru, $id]);
        log_catat('status_arsip', $a['kode'] . ' → ' . $baru);
        flash('Arsip ' . $a['kode'] . ' ditandai ' . ($baru === 'aktif' ? 'AKTIF' : 'INAKTIF') . '.');
    } elseif ($aksi === 'hapus') {
        q('DELETE FROM arsip WHERE id = ?', [$id]);
        log_catat('hapus_arsip', $a['kode']);
        flash('Arsip ' . $a['kode'] . ' dihapus dari daftar (berkas surat aslinya tidak terhapus).');
    } elseif ($aksi === 'keterangan') {
        q('UPDATE arsip SET keterangan = ? WHERE id = ?', [f('keterangan'), $id]);
        flash('Keterangan arsip ' . $a['kode'] . ' disimpan.');
    }
    redirect('arsip.php?' . http_build_query(array_filter(['cari' => f('cari'), 'status' => f('status'), 'sumber' => f('sumber')])));
}

$cari = f('cari');
$status = f('status');
$sumber = f('sumber');
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(kode LIKE ? OR judul LIKE ? OR berkas_nama LIKE ? OR keterangan LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if (in_array($status, ['aktif', 'inaktif'], true)) { $w[] = 'status = ?'; $arg[] = $status; }
if (in_array($sumber, ['masuk', 'keluar'], true)) { $w[] = 'sumber = ?'; $arg[] = $sumber; }
$daftar = semua('SELECT * FROM arsip' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY tanggal DESC, id DESC', $arg);

$aktifN = (int) kolom("SELECT COUNT(*) FROM arsip WHERE status = 'aktif'");
$inaktifN = (int) kolom("SELECT COUNT(*) FROM arsip WHERE status = 'inaktif'");
$totalN = (int) kolom('SELECT COUNT(*) FROM arsip');

$headerEkspor = ['No', 'Kode Arsip', 'Judul', 'Sumber', 'Tanggal', 'Status', 'Nama Berkas', 'Ukuran', 'Keterangan', 'Ditambahkan'];
$barisEkspor = [];
foreach ($daftar as $i => $a) {
    $barisEkspor[] = [$i + 1, $a['kode'], $a['judul'], ucfirst((string) $a['sumber']), ekspor_tgl($a['tanggal']),
        $a['status'] === 'aktif' ? 'Aktif' : 'Inaktif', $a['berkas_nama'], ukuran_teks((int) $a['berkas_ukuran']), $a['keterangan'], $a['dibuat']];
}
if (f('unduh') === 'csv') {
    csv_kirim('arsip-digital', $headerEkspor, $barisEkspor);
}
$qCetak = http_build_query(array_filter(['cari' => $cari, 'status' => $status, 'sumber' => $sumber]));

$GLOBALS['__butuh_xlsx'] = true;
page_top('Arsip Digital', 'Administrasi persuratan · arsip aktif & inaktif');
?>
<div class="grid g4">
  <?= kartu('Arsip Aktif', (string) $aktifN, 'Masih sering dipakai', 'arsip', 'hijau') ?>
  <?= kartu('Arsip Inaktif', (string) $inaktifN, 'Sudah jarang dipakai', 'arsip', 'emas') ?>
  <?= kartu('Total Arsip', (string) $totalN, 'Seluruh berkas terarsip', 'arsip', 'biru') ?>
  <?= kartu('Berkas dari Surat', (string) (int) kolom("SELECT COUNT(*) FROM arsip WHERE sumber IN ('masuk','keluar')"), 'Sumber: surat masuk & keluar', 'surat_masuk', 'biru') ?>
</div>

<div class="card mt16">
  <div class="card-kepala">
    <div><h2>Daftar Arsip</h2>
      <p class="card-ket">Arsip dibuat otomatis saat berkas surat masuk/keluar diunggah. Tandai <b>inaktif</b> bila arsip sudah lama (mis. lebih dari 2 tahun) namun tetap disimpan.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="arsip-digital"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_arsip.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>
  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Kode, judul, nama berkas…"></div>
    <div class="medan"><label for="status">Status Arsip</label><select id="status" name="status" data-auto><?= opsi(['aktif' => 'Aktif', 'inaktif' => 'Inaktif'], $status, 'Semua status') ?></select></div>
    <div class="medan"><label for="sumber">Sumber</label><select id="sumber" name="sumber" data-auto><?= opsi(['masuk' => 'Surat Masuk', 'keluar' => 'Surat Keluar'], $sumber, 'Semua sumber') ?></select></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $status !== '' || $sumber !== ''): ?><a class="btn btn-garis" href="arsip.php">Reset</a><?php endif; ?>
  </form>

  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada arsip. Unggah berkas pada <a href="surat_masuk.php">Surat Masuk</a> — arsipnya muncul di sini otomatis.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:36px">No</th><th class="tengah">Kode</th><th>Judul Arsip</th><th class="tengah">Sumber</th>
          <th class="tengah">Tanggal</th><th>Berkas</th><th class="tengah">Status</th><th>Keterangan</th><th style="width:150px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $a): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah mono"><?= e($a['kode']) ?></td>
            <td><b><?= e($a['judul']) ?></b><span class="sel-sub">Ditambahkan <?= e(waktu_id($a['dibuat'])) ?> oleh <?= e($a['oleh'] ?: '-') ?></span></td>
            <td class="tengah"><span class="chip <?= $a['sumber'] === 'masuk' ? 'hijau' : 'mut' ?>"><?= e(ucfirst((string) $a['sumber'])) ?></span></td>
            <td class="tengah nowrap"><?= e(tanggal_id($a['tanggal'])) ?></td>
            <td><?php if ($a['berkas_url']): ?><a href="<?= e($a['berkas_url']) ?>" target="_blank" rel="noopener"><?= e($a['berkas_nama'] ?: 'Buka berkas') ?></a><span class="sel-sub"><?= e(ukuran_teks((int) $a['berkas_ukuran'])) ?></span><?php else: ?><span class="mut">-</span><?php endif; ?></td>
            <td class="tengah"><span class="pil <?= $a['status'] === 'aktif' ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= $a['status'] === 'aktif' ? 'Aktif' : 'Inaktif' ?></span></td>
            <td>
              <form method="post" style="display:flex;gap:4px">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="keterangan"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="cari" value="<?= e($cari) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="hidden" name="sumber" value="<?= e($sumber) ?>">
                <input type="text" name="keterangan" value="<?= e($a['keterangan']) ?>" maxlength="200" placeholder="keterangan" style="min-width:120px">
                <button class="btn btn-garis btn-kecil" type="submit">Simpan</button>
              </form>
            </td>
            <td>
              <div class="aksi">
                <form method="post" data-konfirmasi="Tandai arsip ini sebagai <?= $a['status'] === 'aktif' ? 'INAKTIF' : 'AKTIF' ?>?">
                  <?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <input type="hidden" name="cari" value="<?= e($cari) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="hidden" name="sumber" value="<?= e($sumber) ?>">
                  <button class="btn btn-garis btn-kecil" type="submit"><?= $a['status'] === 'aktif' ? 'Inaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" data-konfirmasi="Hapus arsip <?= e($a['kode']) ?> dari daftar?">
                  <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <input type="hidden" name="cari" value="<?= e($cari) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="hidden" name="sumber" value="<?= e($sumber) ?>">
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

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Arsip Digital', 'sheet' => 'Arsip', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
