<?php
/**
 * Buku Induk, Buku Klapper, dan Mutasi Siswa (masuk/keluar).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$tab = f('tab', 'induk');
if (!in_array($tab, ['induk', 'mutasi', 'klapper'], true)) { $tab = 'induk'; }
$kelasId = idf('kelas');
$cari = f('cari');
$status = f('status');
$pesanGalat = [];

// ---------- aksi ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();

    if ($aksi === 'mutasi_simpan') {
        $jenis = f('jenis') === 'keluar' ? 'keluar' : 'masuk';
        $tanggal = f('tanggal');
        if (!tanggal_sah($tanggal)) { $pesanGalat[] = 'Tanggal mutasi wajib diisi dengan benar.'; }
        $siswaId = idf('siswa_id');
        $nama = f('nama');
        $kelasAsal = f('kelas_asal');
        $kelasTujuan = f('kelas_tujuan');
        $nis = f('nis');
        $nisn = f('nisn');
        $jk = f('jenis_kelamin') === 'P' ? 'P' : 'L';

        if ($jenis === 'keluar') {
            $s = $siswaId > 0 ? satu('SELECT * FROM siswa WHERE id = ?', [$siswaId]) : null;
            if ($s) {
                if ($nama === '') { $nama = $s['nama']; }
                if ($nis === '') { $nis = (string) $s['nis']; }
                if ($nisn === '') { $nisn = (string) $s['nisn']; }
                $jk = $s['jenis_kelamin'] ?: $jk;
                $kelasAsal = $kelasAsal !== '' ? $kelasAsal : kelas_nama($s['kelas_id'] ? (int) $s['kelas_id'] : 0);
            }
        } else {
            $kelasIdTujuan = 0;
            foreach (kelas_list() as $k) { if ($k['nama'] === $kelasTujuan) { $kelasIdTujuan = (int) $k['id']; } }
            if ($nama === '') { $pesanGalat[] = 'Nama siswa wajib diisi untuk mutasi masuk.'; }
        }
        if (!$pesanGalat && $nama === '') { $pesanGalat[] = 'Nama siswa wajib diisi.'; }

        if (!$pesanGalat) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $idSiswaBaru = $siswaId > 0 ? $siswaId : null;
                if ($jenis === 'masuk') {
                    $kelasIdTujuan = 0;
                    foreach (kelas_list() as $k) { if ($k['nama'] === $kelasTujuan) { $kelasIdTujuan = (int) $k['id']; } }
                    $ada = $nis !== '' ? satu('SELECT id FROM siswa WHERE nis = ?', [$nis]) : null;
                    if ($ada) {
                        $idSiswaBaru = (int) $ada['id'];
                        q("UPDATE siswa SET kelas_id = ?, status = 'aktif', aktif = 1, tanggal_status = NULL, diubah = ? WHERE id = ?",
                            [$kelasIdTujuan ?: null, date('Y-m-d H:i:s'), $idSiswaBaru]);
                    } else {
                        q('INSERT INTO siswa (nis, nisn, nama, jenis_kelamin, kelas_id, status, aktif, urutan, dibuat) VALUES (?,?,?,?,?,?,1,?,?)',
                            [$nis, $nisn, $nama, $jk, $kelasIdTujuan ?: null, 'aktif', (int) kolom('SELECT COALESCE(MAX(urutan),0)+10 FROM siswa'), date('Y-m-d H:i:s')]);
                        $idSiswaBaru = (int) $pdo->lastInsertId();
                    }
                } else {
                    if ($siswaId > 0) {
                        q("UPDATE siswa SET status = 'keluar', aktif = 0, tanggal_status = ?, diubah = ? WHERE id = ?",
                            [$tanggal, date('Y-m-d H:i:s'), $siswaId]);
                    }
                }
                q('INSERT INTO mutasi (jenis, siswa_id, nis, nisn, nama, jenis_kelamin, kelas_asal, kelas_tujuan, tanggal, no_surat, asal_sekolah, alasan, keterangan, oleh, dibuat)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $jenis, $idSiswaBaru, $nis, $nisn, $nama, $jk, $kelasAsal, $kelasTujuan, $tanggal,
                    f('no_surat'), f('asal_sekolah'), f('alasan'), f('keterangan'), current_user()['nama'], date('Y-m-d H:i:s'),
                ]);
                $pdo->exec('COMMIT');
                log_catat('mutasi_' . $jenis, $nama);
                flash('Mutasi ' . $jenis . ' untuk ' . $nama . ' tersimpan.');
                redirect('buku_induk.php?tab=mutasi');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                $pesanGalat[] = 'Gagal menyimpan mutasi: ' . $ex->getMessage();
            }
        }
        $tab = 'mutasi';
    }

    if ($aksi === 'mutasi_hapus') {
        $id = idf('id');
        $m = satu('SELECT * FROM mutasi WHERE id = ?', [$id]);
        if ($m) {
            q('DELETE FROM mutasi WHERE id = ?', [$id]);
            log_catat('hapus_mutasi', $m['nama']);
            flash('Catatan mutasi ' . $m['nama'] . ' dihapus.');
        } else {
            flash('Catatan mutasi tidak ditemukan.', 'err');
        }
        redirect('buku_induk.php?tab=mutasi');
    }
}

// ---------- data ----------
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR s.nama_wali LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k);
}
if ($kelasId > 0) { $w[] = 's.kelas_id = ?'; $arg[] = $kelasId; }
if ($status !== '') { $w[] = 's.status = ?'; $arg[] = $status; }
$siswa = semua('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id'
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY k.urutan, k.nama, s.nama', $arg);

$mutasi = semua('SELECT * FROM mutasi ORDER BY tanggal DESC, id DESC');

$klapper = [];
foreach (kelas_list() as $k) { $klapper[(int) $k['id']] = ['nama' => $k['nama'], 'siswa' => []]; }
$klapper[0] = ['nama' => 'Belum berkelas', 'siswa' => []];
foreach (semua('SELECT s.*, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id ORDER BY s.nama') as $s) {
    $klapper[(int) ($s['kelas_id'] ?? 0)]['siswa'][] = $s;
}
foreach ($klapper as $kid => $blok) { if (!$blok['siswa']) { unset($klapper[$kid]); } }

// ---------- ekspor CSV ----------
if (f('unduh') === 'csv') {
    if ($tab === 'mutasi') {
        $baris = [];
        foreach ($mutasi as $i => $m) {
            $baris[] = [$i + 1, ucfirst($m['jenis']), tanggal_pendek($m['tanggal']), $m['nama'], $m['nis'], $m['nisn'], $m['jenis_kelamin'], $m['kelas_asal'], $m['kelas_tujuan'], $m['asal_sekolah'], $m['no_surat'], $m['alasan'], $m['keterangan']];
        }
        csv_kirim('mutasi-siswa', ['No', 'Jenis', 'Tanggal', 'Nama', 'NIS', 'NISN', 'JK', 'Kelas Asal', 'Kelas Tujuan', 'Asal Sekolah', 'No Surat', 'Alasan', 'Keterangan'], $baris);
    }
    $baris = [];
    foreach ($siswa as $i => $s) {
        $baris[] = [$i + 1, $s['nis'], $s['nisn'], $s['nama'], $s['jenis_kelamin'], $s['kelas_nama'] ?: '-', $s['tempat_lahir'], ekspor_tgl($s['tanggal_lahir']), $s['nama_ayah'], $s['nama_ibu'], $s['nama_wali'], $s['telepon'], $s['alamat'], status_siswa_label($s['status']), $s['tanggal_status']];
    }
    csv_kirim('buku-induk-siswa', ['No', 'NIS', 'NISN', 'Nama', 'JK', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Nama Ayah', 'Nama Ibu', 'Wali', 'Telepon', 'Alamat', 'Status', 'Tanggal Status'], $baris);
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Buku Induk & Mutasi', 'Administrasi kesiswaan · data induk, mutasi, dan buku klapper');
?>
<div class="tab">
  <a href="?tab=induk" class="<?= $tab === 'induk' ? 'on' : '' ?>">Buku Induk</a>
  <a href="?tab=klapper" class="<?= $tab === 'klapper' ? 'on' : '' ?>">Buku Klapper</a>
  <a href="?tab=mutasi" class="<?= $tab === 'mutasi' ? 'on' : '' ?>">Mutasi Masuk / Keluar</a>
</div>

<?php if ($tab === 'induk'): ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Buku Induk Siswa</h2><p class="card-ket">Data induk lengkap seluruh siswa (<?= count($siswa) ?> baris).
        Tombol <b>Cetak</b> pada tiap baris menghasilkan 1 lembar buku induk per siswa (bagian A–F + riwayat
        mutasi &amp; kenaikan), sedangkan <b>Cetak Kafer</b> mencetak sampul buku induk.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="buku-induk-siswa"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=induk&unduh=csv&<?= e(http_build_query(array_filter(['kelas' => $kelasId ?: '', 'status' => $status, 'cari' => $cari]))) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <a class="btn btn-garis" href="cetak_kafer.php" target="_blank" rel="noopener" title="Cetak kafer (sampul) buku induk"><?= ikon('buku') ?> Cetak Kafer</a>
      <?= tombol_cetak('cetak_buku_induk.php?' . http_build_query(array_filter(['kelas' => $kelasId ?: '', 'status' => $status, 'cari' => $cari])), 'Cetak / PDF') ?>
    </div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="induk">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama, NIS, NISN, wali…"></div>
    <div class="medan"><label for="kelas">Kelas</label>
      <select id="kelas" name="kelas" data-auto><option value="">Semua kelas</option>
        <?php foreach (kelas_list() as $k): ?><option value="<?= (int) $k['id'] ?>"<?= $kelasId === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="medan"><label for="status">Status</label>
      <select id="status" name="status" data-auto><?= opsi(status_siswa_list(), $status, 'Semua status') ?></select></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
  </form>
  <?php if (!$siswa): ?>
    <div class="kosong">Belum ada data siswa.</div>
  <?php else: ?>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr>
        <th class="tengah" style="width:40px">No</th><th>Nama</th><th class="tengah">NIS / NISN</th><th class="tengah">JK</th>
        <th>Kelas</th><th>Tempat, Tanggal Lahir</th><th>Ayah</th><th>Ibu</th><th>Wali</th><th>Alamat / Kontak</th><th class="tengah">Status</th>
        <th class="tengah" style="width:82px">Aksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($siswa as $i => $s): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($s['nama']) ?></b></td>
          <td class="tengah"><?= e($s['nis'] ?: '-') ?><span class="sel-sub"><?= e($s['nisn'] ?: '-') ?></span></td>
          <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
          <td><?= e($s['kelas_nama'] ?: '-') ?></td>
          <td><?= e($s['tempat_lahir'] ?: '-') ?><span class="sel-sub"><?= e($s['tanggal_lahir'] ? tanggal_pendek($s['tanggal_lahir']) : '-') ?></span></td>
          <td><?= e($s['nama_ayah'] ?: '-') ?></td>
          <td><?= e($s['nama_ibu'] ?: '-') ?></td>
          <td><?= e($s['nama_wali'] ?: '-') ?></td>
          <td><?= e($s['alamat'] ?: '-') ?><span class="sel-sub"><?= e($s['telepon'] ?: '-') ?></span></td>
          <td class="tengah"><span class="pil <?= $s['status'] === 'aktif' ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= e(status_siswa_label($s['status'])) ?></span>
            <?php if ($s['tanggal_status']): ?><span class="sel-sub"><?= e(tanggal_pendek($s['tanggal_status'])) ?></span><?php endif; ?></td>
          <td class="tengah"><a class="btn btn-garis btn-kecil" href="cetak_buku_induk_siswa.php?id=<?= (int) $s['id'] ?>&no=<?= $i + 1 ?>"
            target="_blank" rel="noopener" title="Cetak buku induk siswa ini (1 lembar)"><?= ikon('cetak') ?> Cetak</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php
$headerEkspor = ['No', 'NIS', 'NISN', 'Nama', 'JK', 'Kelas', 'Tempat Lahir', 'Tanggal Lahir', 'Nama Ayah', 'Nama Ibu', 'Wali', 'Telepon', 'Alamat', 'Status', 'Tanggal Status'];
$barisEkspor = [];
foreach ($siswa as $i => $s) {
    $barisEkspor[] = [$i + 1, $s['nis'], $s['nisn'], $s['nama'], $s['jenis_kelamin'], $s['kelas_nama'] ?: '-', $s['tempat_lahir'], ekspor_tgl($s['tanggal_lahir']), $s['nama_ayah'], $s['nama_ibu'], $s['nama_wali'], $s['telepon'], $s['alamat'], status_siswa_label($s['status']), ekspor_tgl($s['tanggal_status'])];
}
?>
<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Buku Induk Siswa', 'sheet' => 'Buku Induk', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php elseif ($tab === 'klapper'): ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Buku Klapper</h2><p class="card-ket">Daftar siswa per kelas, urut abjad (nama) — seperti buku klapper manual.</p></div>
    <div class="card-aksi"><?= tombol_cetak('cetak_buku_induk.php?bagian=klapper', 'Cetak / PDF') ?></div>
  </div>
  <?php if (!$klapper): ?>
    <div class="kosong">Belum ada data siswa untuk diklapper.</div>
  <?php else: ?>
    <?php foreach ($klapper as $blok): $l = 0; $p = 0; foreach ($blok['siswa'] as $s) { if ($s['jenis_kelamin'] === 'P') { $p++; } else { $l++; } } ?>
      <div class="card" style="box-shadow:none;background:#fbfdff">
        <div class="card-kepala mb0"><div><h3><?= e($blok['nama']) ?></h3>
          <p class="card-ket"><?= count($blok['siswa']) ?> siswa (L: <?= $l ?> · P: <?= $p ?>)</p></div></div>
        <div class="tabel-bungkus">
          <table class="tbl">
            <thead><tr><th class="tengah" style="width:40px">No</th><th>Nama</th><th>NIS</th><th>NISN</th><th class="tengah">JK</th><th>Tempat, Tanggal Lahir</th><th>Wali</th><th class="tengah">Status</th></tr></thead>
            <tbody>
            <?php foreach ($blok['siswa'] as $i => $s): ?>
              <tr><td class="tengah"><?= $i + 1 ?></td><td><?= e($s['nama']) ?></td><td><?= e($s['nis'] ?: '-') ?></td><td><?= e($s['nisn'] ?: '-') ?></td>
                <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
                <td><?= e(trim(($s['tempat_lahir'] ?: '-') . ', ' . ($s['tanggal_lahir'] ? tanggal_id($s['tanggal_lahir']) : '-'), ', ')) ?></td>
                <td><?= e($s['nama_wali'] ?: '-') ?></td><td class="tengah"><?= e(status_siswa_label($s['status'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php else: ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Mutasi Siswa Masuk / Keluar</h2><p class="card-ket">Mutasi masuk menambahkan siswa ke kelas tujuan; mutasi keluar menandai siswa keluar (datanya tetap tersimpan di buku induk).</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEksporMutasi" data-nama="mutasi-siswa"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=mutasi&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_mutasi.php', 'Cetak / PDF') ?>
    </div>
  </div>

  <?php foreach ($pesanGalat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

  <form method="post" id="formMutasi">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="mutasi_simpan">
    <div class="form-grid f4">
      <div class="medan"><label for="jenis">Jenis Mutasi</label>
        <select id="jenis" name="jenis" data-jenis-mutasi>
          <option value="masuk">Masuk (dari sekolah lain)</option>
          <option value="keluar">Keluar (pindah / berhenti)</option>
        </select></div>
      <div class="medan"><label for="tanggal">Tanggal Mutasi</label><input type="date" id="tanggal" name="tanggal" value="<?= e(tanggal_hari_ini()) ?>" required></div>
      <div class="medan"><label for="no_surat">No. Surat Mutasi</label><input type="text" id="no_surat" name="no_surat" maxlength="80"></div>
      <div class="medan"><label for="asal_sekolah">Asal / Sekolah Tujuan</label><input type="text" id="asal_sekolah" name="asal_sekolah" maxlength="150" placeholder="mis. MTsN 1 Gorontalo"></div>

      <div class="medan" data-hanya-keluar style="grid-column:1/-1"><label for="siswa_id">Pilih Siswa (mutasi keluar)</label>
        <select id="siswa_id" name="siswa_id"><option value="">— isi manual di bawah —</option>
          <?php foreach (semua("SELECT s.id, s.nama, s.nis, s.nisn, s.jenis_kelamin, k.nama AS kelas_nama FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.status = 'aktif' ORDER BY s.nama") as $s): ?>
            <option value="<?= (int) $s['id'] ?>" data-nama="<?= e($s['nama']) ?>" data-nis="<?= e($s['nis']) ?>" data-nisn="<?= e($s['nisn']) ?>" data-jk="<?= e($s['jenis_kelamin']) ?>" data-kelas="<?= e($s['kelas_nama']) ?>">
              <?= e($s['nama'] . ' · ' . ($s['kelas_nama'] ?: '-') . ' · ' . ($s['nis'] ?: '-')) ?></option>
          <?php endforeach; ?>
        </select></div>

      <div class="medan"><label for="nama">Nama Siswa</label><input type="text" id="nama" name="nama" maxlength="150"></div>
      <div class="medan"><label for="nis">NIS</label><input type="text" id="nis" name="nis" maxlength="30"></div>
      <div class="medan"><label for="nisn">NISN</label><input type="text" id="nisn" name="nisn" maxlength="30"></div>
      <div class="medan"><label for="jenis_kelamin">Jenis Kelamin</label><select id="jenis_kelamin" name="jenis_kelamin"><?= opsi(['L' => 'Laki-laki', 'P' => 'Perempuan'], 'L') ?></select></div>
      <div class="medan"><label for="kelas_asal">Kelas Asal</label><input type="text" id="kelas_asal" name="kelas_asal" maxlength="60" placeholder="mis. Kelas X"></div>
      <div class="medan"><label for="kelas_tujuan">Kelas Tujuan</label>
        <select id="kelas_tujuan" name="kelas_tujuan"><option value="">— pilih kelas —</option>
          <?php foreach (kelas_list() as $k): ?><option value="<?= e($k['nama']) ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="medan" style="grid-column:1/3"><label for="alasan">Alasan Mutasi</label><input type="text" id="alasan" name="alasan" maxlength="200" placeholder="mis. mengikuti orang tua pindah domisili"></div>
      <div class="medan" style="grid-column:3/5"><label for="keterangan">Keterangan</label><input type="text" id="keterangan" name="keterangan" maxlength="200"></div>
    </div>
    <div class="tombol-baris mt12"><button class="btn" type="submit"><?= ikon('cek') ?> Simpan Mutasi</button></div>
  </form>
</section>

<script>
(function () {
  var jenis = document.querySelector('[data-jenis-mutasi]');
  var blokKeluar = document.querySelector('[data-hanya-keluar]');
  var siswa = document.getElementById('siswa_id');
  function atur() {
    var keluar = jenis.value === 'keluar';
    blokKeluar.hidden = !keluar;
  }
  if (jenis) { jenis.addEventListener('change', atur); atur(); }
  if (siswa) {
    siswa.addEventListener('change', function () {
      var o = siswa.options[siswa.selectedIndex];
      if (!o || !o.value) { return; }
      document.getElementById('nama').value = o.dataset.nama || '';
      document.getElementById('nis').value = o.dataset.nis || '';
      document.getElementById('nisn').value = o.dataset.nisn || '';
      document.getElementById('jenis_kelamin').value = o.dataset.jk || 'L';
      document.getElementById('kelas_asal').value = o.dataset.kelas || '';
    });
  }
})();
</script>

<section class="card">
  <div class="card-kepala"><div><h2>Riwayat Mutasi</h2><p class="card-ket"><?= count($mutasi) ?> catatan.</p></div></div>
  <?php if (!$mutasi): ?>
    <div class="kosong">Belum ada catatan mutasi.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th class="tengah">Jenis</th><th class="tengah">Tanggal</th><th>Nama</th>
          <th class="tengah">NIS / NISN</th><th>Kelas Asal → Tujuan</th><th>Sekolah Asal/Tujuan</th><th>Alasan / Keterangan</th><th style="width:60px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($mutasi as $i => $m): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah"><span class="chip <?= $m['jenis'] === 'masuk' ? 'hijau' : 'merah' ?>"><?= e(ucfirst($m['jenis'])) ?></span></td>
            <td class="tengah nowrap"><?= e(tanggal_id($m['tanggal'])) ?></td>
            <td><b><?= e($m['nama']) ?></b><?= $m['no_surat'] ? '<span class="sel-sub">Surat: ' . e($m['no_surat']) . '</span>' : '' ?></td>
            <td class="tengah"><?= e($m['nis'] ?: '-') ?><span class="sel-sub"><?= e($m['nisn'] ?: '-') ?></span></td>
            <td><?= e(($m['kelas_asal'] ?: '-') . ' → ' . ($m['kelas_tujuan'] ?: '-')) ?></td>
            <td><?= e($m['asal_sekolah'] ?: '-') ?></td>
            <td><?= e($m['alasan'] ?: '-') ?><?= $m['keterangan'] ? '<span class="sel-sub">' . e($m['keterangan']) . '</span>' : '' ?></td>
            <td>
              <form method="post" data-konfirmasi="Hapus catatan mutasi <?= e($m['nama']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="mutasi_hapus"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn-bahaya btn-garis btn-kecil" type="submit"><?= ikon('hapus') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
$headM = ['No', 'Jenis', 'Tanggal', 'Nama', 'NIS', 'NISN', 'JK', 'Kelas Asal', 'Kelas Tujuan', 'Asal Sekolah', 'No Surat', 'Alasan', 'Keterangan'];
$barisM = [];
foreach ($mutasi as $i => $m) {
    $barisM[] = [$i + 1, ucfirst($m['jenis']), ekspor_tgl($m['tanggal']), $m['nama'], $m['nis'], $m['nisn'], $m['jenis_kelamin'], $m['kelas_asal'], $m['kelas_tujuan'], $m['asal_sekolah'], $m['no_surat'], $m['alasan'], $m['keterangan']];
}
?>
<script type="application/json" id="dataEksporMutasi"><?= json_encode(['judul' => 'Mutasi Siswa', 'sheet' => 'Mutasi', 'header' => $headM, 'baris' => $barisM], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php page_bottom(); ?>
