<?php
/**
 * Tambah / ubah data guru & tenaga kependidikan.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$id = idf('id');
$g = $id > 0 ? satu('SELECT * FROM guru WHERE id = ?', [$id]) : null;
if ($id > 0 && !$g) {
    flash('Data guru tidak ditemukan.', 'err');
    redirect('guru.php');
}

$nilai = [
    'nama' => $g['nama'] ?? '', 'nip' => $g['nip'] ?? '', 'nuptk' => $g['nuptk'] ?? '',
    'jenis_kelamin' => $g['jenis_kelamin'] ?? 'L', 'jabatan' => $g['jabatan'] ?? 'Guru',
    'mapel' => $g['mapel'] ?? '', 'email' => $g['email'] ?? '', 'telepon' => $g['telepon'] ?? '',
    'alamat' => $g['alamat'] ?? '', 'tmt' => $g['tmt'] ?? '', 'keterangan' => $g['keterangan'] ?? '',
    'aktif' => (string) ($g['aktif'] ?? '1'), 'status' => $g['status'] ?? 'aktif', 'foto' => $g['foto'] ?? '',
];
$galat = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    foreach (array_keys($nilai) as $k) {
        if ($k === 'foto') { continue; }
        if (array_key_exists($k, $_POST)) {
            $nilai[$k] = trim((string) $_POST[$k]);
        }
    }
    if ($nilai['nama'] === '') { $galat[] = 'Nama wajib diisi.'; }
    if (!in_array($nilai['jenis_kelamin'], ['L', 'P'], true)) { $nilai['jenis_kelamin'] = 'L'; }
    if ($nilai['tmt'] !== '' && !tanggal_sah($nilai['tmt'])) { $galat[] = 'Tanggal TMT tidak sah (format YYYY-MM-DD).'; }
    if ($nilai['email'] !== '' && !filter_var($nilai['email'], FILTER_VALIDATE_EMAIL)) { $galat[] = 'Alamat email tidak sah.'; }
    $nilai['aktif'] = $nilai['aktif'] === '0' ? '0' : '1';

    // unggah foto (opsional)
    if (!$galat && !empty($_FILES['foto']['name'])) {
        $up = unggah_berkas('foto', FOTO_EKSTENSI, FOTO_MAKS_BYTE);
        if (!empty($up['ok'])) {
            $nilai['foto'] = $up['url'];
        } elseif (empty($up['kosong'])) {
            $galat[] = 'Foto: ' . ($up['pesan'] ?? 'gagal diunggah.');
        }
    }
    if (!$galat && f('hapus_foto') === '1') {
        $nilai['foto'] = '';
    }

    if (!$galat) {
        $kol = [
            'nama' => $nilai['nama'], 'nip' => $nilai['nip'], 'nuptk' => $nilai['nuptk'],
            'jenis_kelamin' => $nilai['jenis_kelamin'], 'jabatan' => $nilai['jabatan'], 'mapel' => $nilai['mapel'],
            'email' => $nilai['email'], 'telepon' => $nilai['telepon'], 'alamat' => $nilai['alamat'],
            'tmt' => $nilai['tmt'], 'keterangan' => $nilai['keterangan'], 'foto' => $nilai['foto'],
            'status' => (int) $nilai['aktif'] === 1 ? 'aktif' : 'nonaktif', 'aktif' => (int) $nilai['aktif'],
        ];
        if ($id > 0) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
            $arg = array_values($kol);
            $arg[] = date('Y-m-d H:i:s');
            $arg[] = $id;
            q("UPDATE guru SET $set, diubah = ? WHERE id = ?", $arg);
            log_catat('ubah_guru', $nilai['nama']);
            flash('Data ' . $nilai['nama'] . ' berhasil diperbarui.');
        } else {
            $kol['urutan'] = (int) kolom('SELECT COALESCE(MAX(urutan),0) + 10 FROM guru');
            $kol['dibuat'] = date('Y-m-d H:i:s');
            $nama = array_keys($kol);
            q('INSERT INTO guru (' . implode(',', $nama) . ') VALUES (' . implode(',', array_fill(0, count($nama), '?')) . ')', array_values($kol));
            log_catat('tambah_guru', $nilai['nama']);
            flash('Data ' . $nilai['nama'] . ' berhasil ditambahkan.');
        }
        redirect('guru.php');
    }
}

page_top($id > 0 ? 'Ubah Data Guru' : 'Tambah Data Guru', 'Administrasi kepegawaian');
?>
<div class="card" style="max-width:980px">
  <div class="card-kepala">
    <div><h2><?= $id > 0 ? 'Ubah Data' : 'Tambah Data' ?> Guru &amp; Tenaga Kependidikan</h2>
      <p class="card-ket">Isi data sesuai dokumen resmi. Kolom NIP/NUPTK boleh dikosongkan atau diisi tanda “-”.</p></div>
    <div class="card-aksi"><a class="btn btn-garis" href="guru.php">Kembali</a></div>
  </div>

  <?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-grid f2">
      <div class="medan"><label for="nama">Nama Lengkap &amp; Gelar</label>
        <input type="text" id="nama" name="nama" value="<?= e($nilai['nama']) ?>" required maxlength="150" placeholder="mis. Supianto, S.Pd.I"></div>
      <div class="medan"><label for="jenis_kelamin">Jenis Kelamin</label>
        <select id="jenis_kelamin" name="jenis_kelamin"><?= opsi(['L' => 'Laki-laki', 'P' => 'Perempuan'], $nilai['jenis_kelamin']) ?></select></div>
      <div class="medan"><label for="nip">NIP</label><input type="text" id="nip" name="nip" value="<?= e($nilai['nip']) ?>" maxlength="40"></div>
      <div class="medan"><label for="nuptk">NUPTK</label><input type="text" id="nuptk" name="nuptk" value="<?= e($nilai['nuptk']) ?>" maxlength="40"></div>
      <div class="medan"><label for="jabatan">Jabatan</label>
        <input type="text" id="jabatan" name="jabatan" value="<?= e($nilai['jabatan']) ?>" list="saranJabatan" maxlength="80">
        <datalist id="saranJabatan">
          <option value="Kepala Madrasah"><option value="Guru"><option value="Kepala Tata Usaha">
          <option value="Tenaga Administrasi"><option value="Bendahara"><option value="Pustakawan">
          <option value="Laboran"><option value="Penjaga Sekolah"><option value="Satpam">
        </datalist>
      </div>
      <div class="medan"><label for="mapel">Mata Pelajaran / Bidang</label>
        <input type="text" id="mapel" name="mapel" value="<?= e($nilai['mapel']) ?>" maxlength="150" placeholder="mis. Matematika, IPS"></div>
      <div class="medan"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($nilai['email']) ?>" maxlength="120"></div>
      <div class="medan"><label for="telepon">Telepon / WhatsApp</label><input type="text" id="telepon" name="telepon" value="<?= e($nilai['telepon']) ?>" maxlength="40"></div>
      <div class="medan"><label for="tmt">TMT (Terhitung Mulai Tanggal)</label><input type="date" id="tmt" name="tmt" value="<?= e($nilai['tmt']) ?>"></div>
      <div class="medan"><label for="keterangan">Keterangan Kepegawaian</label>
        <select id="keterangan" name="keterangan"><?= opsi(['Satmikal' => 'Satmikal', 'Non Satmikal' => 'Non Satmikal'], $nilai['keterangan'], '— belum diisi —') ?></select></div>
      <div class="medan"><label for="aktif">Status Kepegawaian</label>
        <select id="aktif" name="aktif"><?= opsi(['1' => 'Aktif', '0' => 'Nonaktif'], $nilai['aktif']) ?></select></div>
      <div class="medan"><label for="foto">Foto (opsional)</label>
        <input type="file" id="foto" name="foto" accept="image/*" data-pratinjau="#pratinjauFoto">
        <div class="pratinjau" id="pratinjauFoto">
          <?php if ($nilai['foto'] !== ''): ?><img src="<?= e($nilai['foto']) ?>" alt="Foto saat ini"><?php else: ?>Belum ada foto.<?php endif; ?>
        </div>
        <?php if ($nilai['foto'] !== ''): ?>
          <label class="centang"><input type="checkbox" name="hapus_foto" value="1"> Hapus foto saat menyimpan</label>
        <?php endif; ?>
      </div>
      <div class="medan" style="grid-column:1/-1"><label for="alamat">Alamat</label>
        <textarea id="alamat" name="alamat" rows="2" maxlength="300"><?= e($nilai['alamat']) ?></textarea></div>
    </div>
    <div class="tombol-baris mt16">
      <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Data</button>
      <a class="btn btn-garis" href="guru.php">Batal</a>
    </div>
  </form>
</div>
<?php page_bottom(); ?>
