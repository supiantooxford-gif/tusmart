<?php
/**
 * Kenaikan Kelas & Kelulusan Siswa.
 *
 * - Naik kelas : siswa terpilih dipindahkan ke kelas tujuan (status tetap aktif).
 * - Luluskan   : siswa menjadi status 'lulus' + tidak aktif; data induk & riwayatnya TETAP,
 *                dan siswa muncul pada menu Alumni.
 * Setiap proses dicatat pada tabel `kenaikan` dan DAPAT DIBATALKAN (mengembalikan kelas/status).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$ta = setting('tahun_ajaran', TA_DEFAULT);
$kelasId = idf('kelas');
$kelas = $kelasId > 0 ? kelas_by_id($kelasId) : null;
if ($kelasId > 0 && !$kelas) {
    flash('Kelas tidak ditemukan.', 'err');
    redirect('kenaikan.php');
}
$pesanGalat = [];

// ---------- proses ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();

    if ($aksi === 'proses') {
        $jenis = f('jenis') === 'lulus' ? 'lulus' : 'naik';
        $asalId = idf('kelas_id');
        $asal = $asalId > 0 ? kelas_by_id($asalId) : null;
        $dipilih = is_array($_POST['siswa'] ?? null) ? array_map('intval', $_POST['siswa']) : [];
        $tujuanId = idf('kelas_tujuan');
        $tujuan = $tujuanId > 0 ? kelas_by_id($tujuanId) : null;

        if (!$asal) { $pesanGalat[] = 'Pilih kelas asal terlebih dahulu.'; }
        if (!$dipilih) { $pesanGalat[] = 'Centang minimal satu siswa.'; }
        if ($jenis === 'naik' && !$tujuan) { $pesanGalat[] = 'Pilih kelas tujuan untuk kenaikan kelas.'; }
        if ($jenis === 'naik' && $tujuan && $asal && (int) $tujuan['id'] === (int) $asal['id']) {
            $pesanGalat[] = 'Kelas tujuan harus berbeda dari kelas asal.';
        }

        $daftar = [];
        if (!$pesanGalat) {
            foreach ($dipilih as $sid) {
                // penjaga: hanya siswa AKTIF yang benar-benar berada di kelas asal (anti salah kelas)
                $s = satu("SELECT * FROM siswa WHERE id = ? AND status = 'aktif' AND kelas_id = ?", [$sid, (int) $asal['id']]);
                if ($s) { $daftar[] = $s; }
            }
            if (!$daftar) { $pesanGalat[] = 'Tidak ada siswa aktif pada kelas asal yang cocok dengan pilihan Anda.'; }
        }

        if (!$pesanGalat) {
            $tanggal = tanggal_hari_ini();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                foreach ($daftar as $s) {
                    if ($jenis === 'naik') {
                        q('UPDATE siswa SET kelas_id = ?, diubah = ? WHERE id = ?', [(int) $tujuan['id'], date('Y-m-d H:i:s'), (int) $s['id']]);
                    } else {
                        q("UPDATE siswa SET status = 'lulus', aktif = 0, tanggal_status = ?, diubah = ? WHERE id = ?",
                            [$tanggal, date('Y-m-d H:i:s'), (int) $s['id']]);
                    }
                    q('INSERT INTO kenaikan (siswa_id, nama, nis, jenis, kelas_asal, kelas_tujuan, ta, tanggal, oleh, dibatalkan, dibuat)
                       VALUES (?,?,?,?,?,?,?,?,?,0,?)', [
                        (int) $s['id'], (string) $s['nama'], (string) $s['nis'], $jenis,
                        (string) $asal['nama'], $jenis === 'naik' ? (string) $tujuan['nama'] : 'Lulus',
                        $ta, $tanggal, current_user()['nama'] ?? '', date('Y-m-d H:i:s'),
                    ]);
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                $pesanGalat[] = 'Gagal memproses: ' . $ex->getMessage();
            }
            if (!$pesanGalat) {
                log_catat('kenaikan_' . $jenis, count($daftar) . ' siswa dari ' . $asal['nama']);
                flash(($jenis === 'naik'
                        ? 'Kenaikan kelas selesai: ' . count($daftar) . ' siswa dipindahkan dari ' . $asal['nama'] . ' ke ' . $tujuan['nama'] . '.'
                        : 'Kelulusan selesai: ' . count($daftar) . ' siswa dinyatakan LULUS dan masuk ke menu Alumni.'));
                redirect('kenaikan.php?kelas=' . (int) $asal['id']);
            }
        }
    }

    if ($aksi === 'batal') {
        $id = idf('id');
        $k = satu('SELECT * FROM kenaikan WHERE id = ?', [$id]);
        if (!$k) {
            flash('Catatan kenaikan tidak ditemukan.', 'err');
        } elseif ((int) $k['dibatalkan'] === 1) {
            flash('Catatan ini sudah dibatalkan sebelumnya.', 'err');
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if ($k['jenis'] === 'lulus') {
                    q("UPDATE siswa SET status = 'aktif', aktif = 1, tanggal_status = NULL, diubah = ? WHERE id = ?",
                        [date('Y-m-d H:i:s'), (int) $k['siswa_id']]);
                } else {
                    $asal = null;
                    foreach (kelas_list() as $kl) { if ($kl['nama'] === $k['kelas_asal']) { $asal = (int) $kl['id']; } }
                    if ($asal) {
                        q('UPDATE siswa SET kelas_id = ?, diubah = ? WHERE id = ?', [$asal, date('Y-m-d H:i:s'), (int) $k['siswa_id']]);
                    }
                }
                q('UPDATE kenaikan SET dibatalkan = 1, dibatalkan_pada = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
                $pdo->exec('COMMIT');
                log_catat('batal_kenaikan', $k['nama']);
                flash('Proses untuk ' . $k['nama'] . ' dibatalkan (data siswa dikembalikan).');
            } catch (Throwable $ex) {
                $pdo->exec('ROLLBACK');
                flash('Gagal membatalkan: ' . $ex->getMessage(), 'err');
            }
        }
        redirect('kenaikan.php?kelas=' . (int) ($kelas['id'] ?? 0));
    }
}

// ---------- data ----------
$daftarKelas = kelas_list(true);
$tujuanKelas = $kelas ? array_values(array_filter($daftarKelas, static fn($k) => (int) $k['id'] !== (int) $kelas['id'])) : $daftarKelas;
$siswa = $kelas ? semua("SELECT * FROM siswa WHERE kelas_id = ? AND status = 'aktif' ORDER BY nama", [(int) $kelas['id']]) : [];
$riwayat = semua("SELECT * FROM kenaikan WHERE dibatalkan = 0 ORDER BY tanggal DESC, id DESC LIMIT 40");
$sudahNaik = 0;
$sudahLulus = 0;
foreach (semua('SELECT jenis, COUNT(*) AS c FROM kenaikan WHERE dibatalkan = 0 GROUP BY jenis') as $r) {
    if ($r['jenis'] === 'naik') { $sudahNaik = (int) $r['c']; } else { $sudahLulus = (int) $r['c']; }
}
$alumniN = (int) kolom("SELECT COUNT(*) FROM siswa WHERE status = 'lulus'");

// ---------- ekspor CSV riwayat ----------
if (f('unduh') === 'csv') {
    $baris = [];
    foreach (semua("SELECT * FROM kenaikan WHERE dibatalkan = 0 ORDER BY tanggal DESC, id DESC") as $i => $k) {
        $baris[] = [$i + 1, tanggal_pendek($k['tanggal']), ucfirst((string) $k['jenis']), $k['nama'], $k['nis'], $k['kelas_asal'], $k['kelas_tujuan'], $k['ta'], $k['oleh']];
    }
    csv_kirim('riwayat-kenaikan-kelulusan', ['No', 'Tanggal', 'Jenis', 'Nama Siswa', 'NIS', 'Kelas Asal', 'Kelas Tujuan / Status', 'Tahun Ajaran', 'Diproses Oleh'], $baris);
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Kenaikan & Kelulusan', 'Administrasi kesiswaan · naik kelas dan kelulusan siswa');
?>
<div class="grid g4">
  <?= kartu('Siswa Aktif', (string) (int) kolom("SELECT COUNT(*) FROM siswa WHERE status = 'aktif'"), 'Seluruh kelas', 'siswa', 'hijau') ?>
  <?= kartu('Sudah Naik Kelas', (string) $sudahNaik, 'Tercatat pada riwayat', 'buku', 'biru') ?>
  <?= kartu('Sudah Diluluskan', (string) $sudahLulus, 'Masuk ke menu Alumni', 'buku', 'emas') ?>
  <?= kartu('Alumni', (string) $alumniN, 'Total siswa lulus', 'pengguna', 'biru') ?>
</div>

<?php foreach ($pesanGalat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

<section class="card mt16">
  <div class="card-kepala">
    <div><h2>Pilih Kelas</h2><p class="card-ket">Pilih kelas asal untuk memproses kenaikan kelas atau kelulusan.</p></div>
    <div class="card-aksi"><a class="btn btn-garis" href="alumni.php"><?= ikon('pengguna') ?> Menu Alumni</a></div>
  </div>
  <form class="saring" method="get">
    <div class="medan cari"><label for="kelas">Kelas Asal</label>
      <select id="kelas" name="kelas" data-auto>
        <option value="">— pilih kelas —</option>
        <?php foreach ($daftarKelas as $k): ?>
          <option value="<?= (int) $k['id'] ?>"<?= $kelas && (int) $kelas['id'] === (int) $k['id'] ? ' selected' : '' ?>>
            <?= e($k['nama'] . ' — ' . (int) kolom("SELECT COUNT(*) FROM siswa WHERE kelas_id = ? AND status = 'aktif'", [(int) $k['id']]) . ' siswa aktif') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</section>

<?php if (!$kelas): ?>
  <div class="card"><div class="kosong">Pilih kelas asal di atas untuk memulai proses kenaikan kelas atau kelulusan.</div></div>
<?php elseif (!$siswa): ?>
  <div class="card"><div class="kosong">Tidak ada siswa aktif di <?= e($kelas['nama']) ?>. Semua siswa kelas ini sudah diproses atau belum terdaftar.</div></div>
<?php else: ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Proses Kenaikan / Kelulusan — <?= e($kelas['nama']) ?></h2>
      <p class="card-ket"><?= count($siswa) ?> siswa aktif. Centang siswa yang diproses; siswa yang tidak dicentang tidak diubah (mis. tinggal kelas).</p></div>
  </div>

  <form method="post" id="formKenaikan" data-hitung-centang="#hitungKenaikan">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="proses">
    <input type="hidden" name="kelas_id" value="<?= (int) $kelas['id'] ?>">

    <div class="form-grid f3">
      <div class="medan"><label for="jenis">Tindakan</label>
        <select id="jenis" name="jenis" data-jenis-proses>
          <option value="naik">Naikkan ke kelas tujuan</option>
          <option value="lulus">Luluskan (masuk menu Alumni)</option>
        </select></div>
      <div class="medan" data-blok-naik><label for="kelas_tujuan">Kelas Tujuan</label>
        <select id="kelas_tujuan" name="kelas_tujuan">
          <option value="">— pilih kelas tujuan —</option>
          <?php foreach ($tujuanKelas as $k): ?><option value="<?= (int) $k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="medan"><label>Tahun Ajaran</label><input type="text" value="<?= e($ta) ?>" readonly></div>
    </div>

    <div class="tabel-ringkas mt12">
      <label class="centang"><input type="checkbox" data-pilih-semua="#formKenaikan input[name='siswa[]']"> Pilih semua siswa</label>
      <span class="chip" id="hitungKenaikan">0 dipilih</span>
    </div>

    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:36px">Pilih</th><th class="tengah" style="width:40px">No</th>
          <th>Nama Siswa</th><th class="tengah">NIS / NISN</th><th class="tengah">JK</th><th class="tengah">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($siswa as $i => $s): ?>
          <tr>
            <td class="tengah"><input type="checkbox" name="siswa[]" value="<?= (int) $s['id'] ?>" aria-label="Pilih <?= e($s['nama']) ?>"></td>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><b><?= e($s['nama']) ?></b></td>
            <td class="tengah"><?= e($s['nis'] ?: '-') ?><span class="sel-sub"><?= e($s['nisn'] ?: '-') ?></span></td>
            <td class="tengah"><?= e($s['jenis_kelamin']) ?></td>
            <td class="tengah"><span class="pil pil-aktif">Aktif</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="alert alert-warn mt16" data-info-lulus hidden>
      <div><b>Perhatian:</b> siswa yang diluluskan akan berstatus <b>Lulus</b> (tidak lagi muncul di absensi/nilai)
        dan otomatis masuk ke menu <b>Alumni</b>. Data induk serta riwayatnya tetap tersimpan.</div>
    </div>

    <div class="tombol-baris mt12">
      <button class="btn" type="submit" data-konfirmasi="Proses siswa yang dicentang sekarang?"><?= ikon('cek') ?> Proses Sekarang</button>
      <a class="btn btn-garis" href="alumni.php">Lihat Alumni</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-kepala">
    <div><h2>Riwayat Kenaikan & Kelulusan</h2><p class="card-ket">40 proses terakhir. Proses dapat dibatalkan bila salah pilih.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataRiwayat" data-nama="riwayat-kenaikan-kelulusan"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&kelas=<?= (int) ($kelas['id'] ?? 0) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>
  <?php if (!$riwayat): ?>
    <div class="kosong">Belum ada proses kenaikan/kelulusan.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th class="tengah">Tanggal</th><th class="tengah">Jenis</th>
          <th>Nama Siswa</th><th class="tengah">NIS</th><th>Kelas Asal</th><th>Kelas Tujuan</th><th class="tengah">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($riwayat as $i => $k): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah nowrap"><?= e(tanggal_id($k['tanggal'])) ?></td>
            <td class="tengah"><span class="chip <?= $k['jenis'] === 'lulus' ? 'emas' : 'hijau' ?>"><?= $k['jenis'] === 'lulus' ? 'Lulus' : 'Naik' ?></span></td>
            <td><b><?= e($k['nama']) ?></b></td>
            <td class="tengah"><?= e($k['nis'] ?: '-') ?></td>
            <td><?= e($k['kelas_asal'] ?: '-') ?></td>
            <td><?= e($k['kelas_tujuan'] ?: '-') ?></td>
            <td class="tengah">
              <form method="post" data-konfirmasi="Batalkan proses untuk <?= e($k['nama']) ?>? Data siswa dikembalikan ke keadaan sebelumnya.">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                <button class="btn btn-garis btn-kecil" type="submit">Batalkan</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
(function () {
  var jenis = document.querySelector('[data-jenis-proses]');
  var blokNaik = document.querySelector('[data-blok-naik]');
  var infoLulus = document.querySelector('[data-info-lulus]');
  function atur() {
    var lulus = jenis && jenis.value === 'lulus';
    if (blokNaik) { blokNaik.hidden = lulus; }
    if (infoLulus) { infoLulus.hidden = !lulus; }
  }
  if (jenis) { jenis.addEventListener('change', atur); atur(); }
})();
</script>
<script type="application/json" id="dataRiwayat"><?= json_encode([
    'judul' => 'Riwayat Kenaikan & Kelulusan',
    'sheet' => 'Riwayat',
    'header' => ['No', 'Tanggal', 'Jenis', 'Nama Siswa', 'NIS', 'Kelas Asal', 'Kelas Tujuan', 'Tahun Ajaran', 'Diproses Oleh'],
    'baris' => array_map(static fn($i, $k) => [$i + 1, $k['tanggal'], ucfirst((string) $k['jenis']), $k['nama'], $k['nis'], $k['kelas_asal'], $k['kelas_tujuan'], $k['ta'], $k['oleh']], array_keys($riwayat), $riwayat),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
