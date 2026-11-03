<?php
/**
 * Tambah / ubah data siswa — formulir BUKU INDUK SISWA (bagian A–F seperti formulir manual).
 *
 * Susunan medan diambil dari inc/siswa_induk.php (siswa_medan_grup()) sehingga formulir ini dan
 * lembar cetak Buku Induk per siswa selalu memakai label & urutan yang SAMA.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$id = idf('id');
$s = $id > 0 ? satu('SELECT * FROM siswa WHERE id = ?', [$id]) : null;
if ($id > 0 && !$s) {
    flash('Data siswa tidak ditemukan.', 'err');
    redirect('siswa.php');
}

// Nilai formulir: gabungan kolom tabel `siswa` + medan buku induk (tabel `siswa_induk`).
$nilai = array_merge($s ?? [], $id > 0 ? siswa_induk_data($id) : []);
foreach (['nis', 'nisn', 'nama', 'tempat_lahir', 'tanggal_lahir', 'nama_ayah', 'nama_ibu', 'nama_wali',
    'telepon', 'alamat', 'foto'] as $k) {
    $nilai[$k] = (string) ($nilai[$k] ?? '');
}
$nilai['jenis_kelamin'] = (string) ($nilai['jenis_kelamin'] ?? 'L');
$nilai['kelas_id'] = (string) ($nilai['kelas_id'] ?? '');
$nilai['status'] = (string) ($nilai['status'] ?? 'aktif');
$galat = [];

/** Kolom yang tinggal di tabel `siswa` (selebihnya disimpan ke `siswa_induk`). */
$kolSiswa = ['nis', 'nisn', 'nama', 'jenis_kelamin', 'kelas_id', 'tempat_lahir', 'tanggal_lahir',
    'nama_ayah', 'nama_ibu', 'nama_wali', 'telepon', 'alamat', 'status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    foreach (siswa_medan_baris() as $k => $b) {
        if ($k === 'foto' || $k === 'kelas_id' || $k === 'status' || $k === 'jenis_kelamin') { continue; }
        if (array_key_exists($k, $_POST)) { $nilai[$k] = trim((string) $_POST[$k]); }
    }
    if (array_key_exists('jenis_kelamin', $_POST)) { $nilai['jenis_kelamin'] = trim((string) $_POST['jenis_kelamin']); }
    if (array_key_exists('kelas_id', $_POST)) { $nilai['kelas_id'] = trim((string) $_POST['kelas_id']); }
    if (array_key_exists('status', $_POST)) { $nilai['status'] = trim((string) $_POST['status']); }

    if ($nilai['nama'] === '') { $galat[] = 'Nama siswa wajib diisi.'; }
    if (!in_array($nilai['jenis_kelamin'], ['L', 'P'], true)) { $nilai['jenis_kelamin'] = 'L'; }
    if (!array_key_exists($nilai['status'], status_siswa_list())) { $nilai['status'] = 'aktif'; }
    if ($nilai['tanggal_lahir'] !== '' && !tanggal_sah($nilai['tanggal_lahir'])) { $galat[] = 'Tanggal lahir tidak sah.'; }
    // Semua medan bertipe tanggal pada buku induk (ayah/ibu/wali, sekolah asal, dan bagian F).
    foreach (siswa_medan_baris() as $k => $b) {
        if (($b['tipe'] ?? '') !== 'tanggal' || $k === 'tanggal_lahir') { continue; }
        if (($nilai[$k] ?? '') !== '' && !tanggal_sah((string) $nilai[$k])) {
            $galat[] = 'Tanggal pada medan "' . $b['label'] . '" tidak sah.';
        }
    }
    if ($nilai['nis'] !== '') {
        $bentrok = satu('SELECT id FROM siswa WHERE nis = ? AND id <> ?', [$nilai['nis'], $id]);
        if ($bentrok) { $galat[] = 'NIS ' . $nilai['nis'] . ' sudah dipakai siswa lain.'; }
    }
    if (!$galat && !empty($_FILES['foto']['name'])) {
        $up = unggah_berkas('foto', FOTO_EKSTENSI, FOTO_MAKS_BYTE);
        if (!empty($up['ok'])) { $nilai['foto'] = $up['url']; }
        elseif (empty($up['kosong'])) { $galat[] = 'Foto: ' . ($up['pesan'] ?? 'gagal diunggah.'); }
    }
    if (!$galat && f('hapus_foto') === '1') { $nilai['foto'] = ''; }

    if (!$galat) {
        $aktif = $nilai['status'] === 'aktif' ? 1 : 0;
        $tglStatus = $s['tanggal_status'] ?? null;
        if ($nilai['status'] !== 'aktif' && (!$s || ($s['status'] ?? 'aktif') === 'aktif')) {
            $tglStatus = tanggal_hari_ini();
        }
        if ($nilai['status'] === 'aktif') { $tglStatus = null; }
        $kol = [];
        foreach ($kolSiswa as $k) {
            $kol[$k] = $k === 'kelas_id'
                ? ($nilai['kelas_id'] !== '' ? (int) $nilai['kelas_id'] : null)
                : (string) $nilai[$k];
        }
        $kol['tanggal_status'] = $tglStatus;
        $kol['foto'] = $nilai['foto'];
        $kol['aktif'] = $aktif;

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
                $arg = array_values($kol);
                $arg[] = date('Y-m-d H:i:s');
                $arg[] = $id;
                q("UPDATE siswa SET $set, diubah = ? WHERE id = ?", $arg);
                log_catat('ubah_siswa', $nilai['nama']);
                $pesan = 'Data siswa ' . $nilai['nama'] . ' berhasil diperbarui.';
            } else {
                $kol['urutan'] = (int) kolom('SELECT COALESCE(MAX(urutan),0) + 10 FROM siswa');
                $kol['dibuat'] = date('Y-m-d H:i:s');
                $nama = array_keys($kol);
                q('INSERT INTO siswa (' . implode(',', $nama) . ') VALUES (' . implode(',', array_fill(0, count($nama), '?')) . ')', array_values($kol));
                $id = (int) $pdo->lastInsertId();
                log_catat('tambah_siswa', $nilai['nama']);
                $pesan = 'Data siswa ' . $nilai['nama'] . ' berhasil ditambahkan.';
            }
            siswa_induk_simpan($id, $_POST);
            $pdo->exec('COMMIT');
        } catch (Throwable $ex) {
            $pdo->exec('ROLLBACK');
            $galat[] = 'Gagal menyimpan data siswa: ' . $ex->getMessage();
        }
        if (!$galat) {
            flash($pesan . ' Formulir buku induk tersimpan.');
            redirect('siswa.php');
        }
    }
}

/** Satu kotak isian pada formulir (mengikuti 'tipe' pada metadata medan). */
function siswa_input(array $b, array $nilai): string
{
    $k = $b['k'];
    $id = 'f_' . $k;
    $nilaiK = (string) ($nilai[$k] ?? '');
    $wajib = !empty($b['wajib']) ? ' required' : '';
    $maks = isset($b['maks']) ? ' maxlength="' . (int) $b['maks'] . '"' : '';
    $tipe = $b['tipe'] ?? 'teks';
    $contoh = isset($b['contoh']) ? ' placeholder="' . e($b['contoh']) . '"' : '';

    if ($tipe === 'kelas') {
        $opsi = '<option value="">— belum ditentukan —</option>';
        foreach (kelas_list() as $kl) {
            $opsi .= '<option value="' . (int) $kl['id'] . '"' . ($nilaiK === (string) $kl['id'] ? ' selected' : '') . '>' . e($kl['nama']) . '</option>';
        }
        return '<select id="' . $id . '" name="' . e($k) . '">' . $opsi . '</select>';
    }
    if ($tipe === 'status') {
        return '<select id="' . $id . '" name="status">' . opsi(status_siswa_list(), $nilaiK) . '</select>';
    }
    if ($tipe === 'pilih') {
        return '<select id="' . $id . '" name="' . e($k) . '"' . $wajib . '>'
            . opsi($b['pilihan'] ?? [], $nilaiK) . '</select>';
    }
    if ($tipe === 'tanggal') {
        $v = tanggal_sah($nilaiK) ? $nilaiK : '';
        return '<input type="date" id="' . $id . '" name="' . e($k) . '" value="' . e($v) . '"' . $wajib . '>';
    }
    if ($tipe === 'tekspanjang') {
        return '<textarea id="' . $id . '" name="' . e($k) . '" rows="2"' . $maks . $wajib . '>' . e($nilaiK) . '</textarea>';
    }
    if ($tipe === 'foto') {
        $prev = $nilaiK !== '' ? '<img src="' . e($nilaiK) . '" alt="Foto siswa">' : 'Belum ada foto.';
        $hapus = $nilaiK !== '' ? '<label class="centang"><input type="checkbox" name="hapus_foto" value="1"> Hapus foto</label>' : '';
        return '<input type="file" id="foto" name="foto" accept="image/*" data-pratinjau="#pratinjauFoto">'
            . '<div class="pratinjau" id="pratinjauFoto">' . $prev . '</div>' . $hapus;
    }
    if ($tipe === 'angka') {
        return '<input type="number" id="' . $id . '" name="' . e($k) . '" value="' . e($nilaiK) . '" min="0" step="1"' . $wajib . '>';
    }
    return '<input type="text" id="' . $id . '" name="' . e($k) . '" value="' . e($nilaiK) . '"' . $maks . $contoh . $wajib . '>';
}

$grup = siswa_medan_grup();
page_top($id > 0 ? 'Ubah Data Siswa' : 'Tambah Data Siswa', 'Buku induk siswa · bagian A–F');
?>
<?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" id="formSiswa">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-kepala">
      <div><h2><?= $id > 0 ? 'Ubah Data Siswa' : 'Tambah Data Siswa' ?></h2>
        <p class="card-ket">Isian mengikuti formulir <b>Buku Induk Siswa</b> (bagian A–F). Data ini langsung
          dipakai pada lembar cetak buku induk siswa.</p></div>
      <div class="card-aksi">
        <?php if ($id > 0): ?><a class="btn btn-garis" href="cetak_buku_induk_siswa.php?id=<?= (int) $id ?>" target="_blank" rel="noopener"><?= ikon('cetak') ?> Buku Induk</a><?php endif; ?>
        <a class="btn btn-garis" href="siswa.php">Kembali</a>
      </div>
    </div>
    <p class="kecil mut mb0">Medan bertanda <b>*</b> wajib diisi. Bagian yang belum diketahui boleh dikosongkan
      (akan tercetak sebagai tanda hubung pada lembar buku induk).</p>
  </div>

  <?php foreach ($grup as $kode => $g): ?>
  <section class="card form-seksi" id="seksi<?= e($kode) ?>">
    <h3 class="seksi-judul">
      <?php if ($kode !== 'ADM'): ?><span class="seksi-abjad"><?= e($kode) ?></span><?php endif; ?>
      <span><?= e($g['judul']) ?></span>
      <?php if ($kode === 'ADM'): ?><span class="seksi-ket">nomor induk, kelas, status, dan pas foto</span><?php endif; ?>
    </h3>
    <div class="form-grid <?= e($g['kolom']) ?>">
      <?php foreach ($g['baris'] as $b): ?>
        <?php if (isset($b['sub'])): ?>
          <div class="medan-sub"><?= e($b['sub']) ?></div>
          <?php continue; ?>
        <?php endif; ?>
        <?php $lebar = (int) ($b['lebar'] ?? 1); ?>
        <div class="medan"<?= $lebar > 1 ? ' style="grid-column:1/-1"' : '' ?>>
          <label for="<?= $b['k'] === 'foto' ? 'foto' : 'f_' . e($b['k']) ?>">
            <?= isset($b['no']) ? e($b['no']) . ' ' : '' ?><?= e($b['label']) ?><?= !empty($b['wajib']) ? ' *' : '' ?>
          </label>
          <?= siswa_input($b, $nilai) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <div class="card">
    <div class="tombol-baris">
      <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Data Siswa</button>
      <a class="btn btn-garis" href="siswa.php">Batal</a>
    </div>
  </div>
</form>
<?php page_bottom(); ?>
