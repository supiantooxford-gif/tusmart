<?php
/**
 * Pengaturan Data Lembaga: identitas madrasah, kop/logo surat, penomoran, sumber data sarpras,
 * dan data kelas.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$galat = [];
$super = is_super();

$kunciTeks = ['nama', 'yayasan', 'akreditasi', 'npsn', 'nsm', 'jenis', 'alamat', 'desa', 'kecamatan', 'kabupaten', 'provinsi',
    'telepon', 'email', 'website', 'kepala', 'nip_kepala', 'tahun_ajaran', 'semester', 'kode_surat',
    'kafer_bentuk', 'kafer_singkat'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();

    if ($aksi === 'simpan') {
        $data = [];
        foreach ($kunciTeks as $k) {
            $data[$k] = array_key_exists($k, $_POST) ? trim((string) $_POST[$k]) : setting($k);
        }
        if ($data['nama'] === '') { $galat[] = 'Nama lembaga wajib diisi.'; }
        if ($data['jenis'] !== 'sekolah' && $data['jenis'] !== 'madrasah') { $data['jenis'] = 'madrasah'; }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { $galat[] = 'Alamat email tidak sah.'; }
        if (!preg_match('#^\d{4}/\d{4}$#', $data['tahun_ajaran'])) { $galat[] = 'Tahun ajaran harus berbentuk 2025/2026.'; }
        if (!in_array($data['semester'], ['Ganjil', 'Genap'], true)) { $data['semester'] = 'Ganjil'; }
        if (!preg_match('/^[A-Za-z0-9.\-]{1,12}$/', $data['kode_surat'])) { $galat[] = 'Kode surat 1–12 karakter (huruf/angka/titik/tanda hubung).'; }

        $logo = setting('logo');
        if (!$galat && !empty($_FILES['logo']['name'])) {
            $up = unggah_berkas('logo', FOTO_EKSTENSI, LOGO_MAKS_BYTE);
            if (!empty($up['ok'])) { $logo = $up['url']; }
            elseif (empty($up['kosong'])) { $galat[] = 'Logo: ' . ($up['pesan'] ?? 'gagal diunggah.'); }
        }
        if (!$galat && f('hapus_logo') === '1') { $logo = ''; }

        // Tanda tangan Kepala Sekolah/Madrasah (gambar) + sakelar "diaktifkan".
        $ttdKepala = setting('ttd_kepala');
        if (!$galat && !empty($_FILES['ttd_kepala']['name'])) {
            $up = unggah_berkas('ttd_kepala', FOTO_EKSTENSI, LOGO_MAKS_BYTE);
            if (!empty($up['ok'])) { $ttdKepala = $up['url']; }
            elseif (empty($up['kosong'])) { $galat[] = 'Tanda tangan: ' . ($up['pesan'] ?? 'gagal diunggah.'); }
        }
        if (!$galat && f('hapus_ttd_kepala') === '1') { $ttdKepala = ''; }
        $ttdAktif = ($ttdKepala !== '' && f('ttd_kepala_aktif') === '1') ? '1' : '0';

        if (!$galat) {
            foreach ($data as $k => $v) { set_setting($k, $v); }
            set_setting('logo', $logo);
            set_setting('ttd_kepala', $ttdKepala);
            set_setting('ttd_kepala_aktif', $ttdAktif);
            if ($super && array_key_exists('sarpras_sumber', $_POST)) {
                set_setting('sarpras_sumber', trim((string) $_POST['sarpras_sumber']));
            }
            if ($super && array_key_exists('absguru_sumber', $_POST)) {
                set_setting('absguru_sumber', trim((string) $_POST['absguru_sumber']));
            }
            log_catat('pengaturan_lembaga', 'Identitas lembaga diperbarui');
            flash('Pengaturan data lembaga disimpan.');
            redirect('pengaturan_lembaga.php');
        }
    }

    if ($aksi === 'kelas_simpan') {
        $kid = idf('kelas_id');
        $nama = f('kelas_nama');
        $tingkat = f('tingkat');
        $wali = f('wali');
        $aktif = f('kelas_aktif') === '0' ? 0 : 1;
        if ($nama === '') {
            $galat[] = 'Nama kelas wajib diisi.';
        } else {
            if ($kid > 0) {
                q('UPDATE kelas SET nama = ?, tingkat = ?, wali = ?, aktif = ? WHERE id = ?', [$nama, $tingkat, $wali, $aktif, $kid]);
                flash('Kelas ' . $nama . ' diperbarui.');
            } else {
                q('INSERT INTO kelas (nama, tingkat, wali, urutan, aktif, dibuat) VALUES (?,?,?,?,?,?)',
                    [$nama, $tingkat, $wali, (int) kolom('SELECT COALESCE(MAX(urutan),0) + 10 FROM kelas'), $aktif, date('Y-m-d H:i:s')]);
                flash('Kelas ' . $nama . ' ditambahkan.');
            }
            log_catat('kelas', $nama);
            redirect('pengaturan_lembaga.php#kelas');
        }
    }

    if ($aksi === 'kelas_hapus') {
        $kid = idf('kelas_id');
        $k = kelas_by_id($kid);
        if (!$k) {
            flash('Kelas tidak ditemukan.', 'err');
        } else {
            $jml = (int) kolom('SELECT COUNT(*) FROM siswa WHERE kelas_id = ?', [$kid]);
            q('DELETE FROM kelas WHERE id = ?', [$kid]);
            log_catat('hapus_kelas', $k['nama']);
            flash('Kelas ' . $k['nama'] . ' dihapus.' . ($jml > 0 ? ' ' . $jml . ' siswa kini tanpa kelas (data siswa tidak dihapus).' : ''));
        }
        redirect('pengaturan_lembaga.php#kelas');
    }
}

$editKelas = idf('edit_kelas');
$kEdit = $editKelas > 0 ? kelas_by_id($editKelas) : null;
$kelasSemua = semua('SELECT k.*, (SELECT COUNT(*) FROM siswa s WHERE s.kelas_id = k.id) AS jumlah FROM kelas k ORDER BY k.urutan, k.nama');
$sarprasAda = sarpras_ada();
$infoSarpras = sarpras_sekolah();
$absguruAda = absguru_ada();
$infoAbsguru = absguru_info();
$jmlAbsguru = $absguruAda ? count(absguru_guru(true)) : 0;
page_top('Pengaturan Data Lembaga', 'Identitas madrasah, kop surat, dan data kelas');
?>
<?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

<section class="card">
  <div class="card-kepala"><div><h2>Identitas Lembaga</h2>
    <p class="card-ket">Data ini dipakai pada kop seluruh lembar cetak, surat keluar, dan laporan.</p></div></div>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan">
    <div class="form-grid f3">
      <div class="medan" style="grid-column:1/3"><label for="nama">Nama <?= e(istilah_lembaga()) ?></label>
        <input type="text" id="nama" name="nama" value="<?= e(setting('nama')) ?>" required maxlength="150"></div>
      <div class="medan"><label for="jenis">Jenis Lembaga</label>
        <select id="jenis" name="jenis"><?= opsi(['madrasah' => 'Madrasah (MI/MTs/MA/MAK)', 'sekolah' => 'Sekolah Umum (SD/SMP/SMA/SMK)'], setting('jenis', 'madrasah')) ?></select></div>
      <div class="medan" style="grid-column:1/-1"><label for="yayasan">Nama Yayasan / Penyelenggara (kop surat resmi)</label>
        <input type="text" id="yayasan" name="yayasan" value="<?= e(setting('yayasan')) ?>" maxlength="150">
        <span class="petunjuk">Dicetak di atas nama madrasah pada kop Surat Rekomendasi Pindah, Surat Pindah, dan Lolos Butuh.</span></div>
      <div class="medan"><label for="akreditasi">Akreditasi</label>
        <input type="text" id="akreditasi" name="akreditasi" value="<?= e(setting('akreditasi')) ?>" maxlength="12" placeholder="mis. B">
        <span class="petunjuk">Dicetak pada kop surat resmi.</span></div>
      <div class="medan"><label for="npsn">NPSN</label><input type="text" id="npsn" name="npsn" value="<?= e(setting('npsn')) ?>" maxlength="20"></div>
      <div class="medan"><label for="nsm">NSM (khusus madrasah)</label><input type="text" id="nsm" name="nsm" value="<?= e(setting('nsm')) ?>" maxlength="30"></div>
      <div class="medan"><label for="kepala">Nama Kepala <?= e(istilah_lembaga()) ?></label><input type="text" id="kepala" name="kepala" value="<?= e(setting('kepala')) ?>" maxlength="120"></div>
      <div class="medan"><label for="nip_kepala">NIP Kepala</label><input type="text" id="nip_kepala" name="nip_kepala" value="<?= e(setting('nip_kepala')) ?>" maxlength="40"></div>
      <div class="medan"><label for="tahun_ajaran">Tahun Ajaran</label><input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e(setting('tahun_ajaran', TA_DEFAULT)) ?>" maxlength="9" placeholder="2025/2026"></div>
      <div class="medan"><label for="semester">Semester</label><select id="semester" name="semester"><?= opsi(['Ganjil' => 'Ganjil', 'Genap' => 'Genap'], setting('semester', SEMESTER_DEFAULT)) ?></select></div>
      <div class="medan"><label for="kode_surat">Kode Surat (penomoran otomatis)</label><input type="text" id="kode_surat" name="kode_surat" value="<?= e(setting('kode_surat', KODE_SURAT_DEFAULT)) ?>" maxlength="12">
        <span class="petunjuk">Contoh hasil: 001/<?= e(setting('kode_surat', KODE_SURAT_DEFAULT)) ?>/<?= e(bulan_romawi((int) date('n'))) ?>/<?= date('Y') ?></span></div>
      <div class="medan" style="grid-column:1/-1"><label for="alamat">Alamat (Jalan, Dusun)</label><input type="text" id="alamat" name="alamat" value="<?= e(setting('alamat')) ?>" maxlength="200"></div>
      <div class="medan"><label for="desa">Desa / Kelurahan</label><input type="text" id="desa" name="desa" value="<?= e(setting('desa')) ?>" maxlength="80"></div>
      <div class="medan"><label for="kecamatan">Kecamatan</label><input type="text" id="kecamatan" name="kecamatan" value="<?= e(setting('kecamatan')) ?>" maxlength="80"></div>
      <div class="medan"><label for="kabupaten">Kabupaten</label><input type="text" id="kabupaten" name="kabupaten" value="<?= e(setting('kabupaten')) ?>" maxlength="80"></div>
      <div class="medan"><label for="provinsi">Provinsi</label><input type="text" id="provinsi" name="provinsi" value="<?= e(setting('provinsi')) ?>" maxlength="80"></div>
      <div class="medan"><label for="telepon">Telepon / WhatsApp</label><input type="text" id="telepon" name="telepon" value="<?= e(setting('telepon')) ?>" maxlength="40"></div>
      <div class="medan"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e(setting('email')) ?>" maxlength="120"></div>
      <div class="medan"><label for="website">Situs / Website</label><input type="text" id="website" name="website" value="<?= e(setting('website')) ?>" maxlength="120"></div>
      <div class="medan"><label for="kafer_bentuk">Kafer Buku Induk — Bentuk Lembaga</label>
        <input type="text" id="kafer_bentuk" name="kafer_bentuk" value="<?= e(setting('kafer_bentuk')) ?>" maxlength="80"
          placeholder="<?= e(kafer_identitas()['bentuk']) ?>">
        <span class="petunjuk">Baris ke-2 judul kafer (sampul) buku induk. Kosongkan = otomatis dari nama lembaga.</span></div>
      <div class="medan"><label for="kafer_singkat">Kafer Buku Induk — Singkatan</label>
        <input type="text" id="kafer_singkat" name="kafer_singkat" value="<?= e(setting('kafer_singkat')) ?>" maxlength="20"
          placeholder="<?= e(kafer_identitas()['singkat'] !== '' ? kafer_identitas()['singkat'] : 'mis. MAS') ?>">
        <span class="petunjuk">Tercetak dalam kurung pada kafer. Kosongkan = otomatis.</span></div>
      <div class="medan" style="grid-column:1/-1">
        <span class="petunjuk">Pratinjau judul kafer: <b><?= e(kafer_identitas()['judul']) ?></b> ·
          <?= e(kafer_identitas()['bentuk']) ?><?= kafer_identitas()['singkat'] !== '' ? ' (' . e(kafer_identitas()['singkat']) . ')' : '' ?> ·
          <?= e(kafer_identitas()['nama']) ?> · <?= e(kafer_identitas()['tahun']) ?>
          &nbsp;— <a href="cetak_kafer.php" target="_blank" rel="noopener">lihat kafer</a></span></div>
      <div class="medan"><label for="logo">Logo (dipakai pada kop)</label>
        <input type="file" id="logo" name="logo" accept="image/*" data-pratinjau="#pratinjauLogo">
        <div class="pratinjau" id="pratinjauLogo"><?php if (setting('logo') !== ''): ?><img src="<?= e(setting('logo')) ?>" alt="Logo lembaga"><?php else: ?>Belum ada logo.<?php endif; ?></div>
        <?php if (setting('logo') !== ''): ?><label class="centang"><input type="checkbox" name="hapus_logo" value="1"> Hapus logo</label><?php endif; ?>
      </div>
      <div class="medan" style="grid-column:2/4" id="medanTtdKepala">
        <label for="ttd_kepala">Tanda Tangan <?= e('Kepala ' . istilah_lembaga()) ?></label>
        <input type="file" id="ttd_kepala" name="ttd_kepala" accept="image/*" data-pratinjau="#pratinjauTtd">
        <div class="pratinjau ttd-pratinjau" id="pratinjauTtd"><?php if (ttd_kepala_url() !== ''): ?><img src="<?= e(ttd_kepala_url()) ?>" alt="Tanda tangan kepala"><?php else: ?>Belum ada tanda tangan. Unggah gambar tanda tangan (PNG transparan dianjurkan).<?php endif; ?></div>
        <input type="hidden" name="ttd_kepala_aktif" value="0">
        <label class="centang"><input type="checkbox" name="ttd_kepala_aktif" value="1"<?= ttd_kepala_pakai() ? ' checked' : '' ?>>
          Aktifkan tanda tangan pada dokumen</label>
        <span class="petunjuk">Bila dicentang, gambar ini tercetak pada <b>semua</b> dokumen bertanda tangan
          Kepala <?= e(istilah_lembaga()) ?> (surat keluar, buku induk, data guru/siswa, laporan, notulen, dan lembar cetak lainnya).
          Bila tidak dicentang, ruang tanda tangan dibiarkan kosong.</span>
        <?php if (ttd_kepala_url() !== ''): ?><label class="centang"><input type="checkbox" name="hapus_ttd_kepala" value="1"> Hapus tanda tangan</label><?php endif; ?>
      </div>
      <?php if ($super): ?>
      <div class="medan" style="grid-column:1/3"><label for="sarpras_sumber">Berkas basis data e-GURU (sumber Inventaris Sarpras)</label>
        <input type="text" id="sarpras_sumber" name="sarpras_sumber" value="<?= e(setting('sarpras_sumber')) ?>" maxlength="300">
        <span class="petunjuk">Jalur berkas SQLite e-GURU di server. Hanya Super Admin yang dapat mengubahnya.</span></div>
      <div class="medan" style="grid-column:1/3"><label for="absguru_sumber">Berkas basis data Absensi Guru (sumber Laporan Absensi-Guru)</label>
        <input type="text" id="absguru_sumber" name="absguru_sumber" value="<?= e(setting('absguru_sumber')) ?>" maxlength="300">
        <span class="petunjuk">Jalur berkas SQLite aplikasi Absensi Guru di server. Dibaca hanya-baca: absensi yang
          diisi guru langsung tampil pada menu <b>Laporan Absensi-Guru</b>. Hanya Super Admin yang dapat mengubahnya.</span></div>
      <?php endif; ?>
    </div>
    <div class="tombol-baris mt16"><button class="btn" type="submit"><?= ikon('cek') ?> Simpan Pengaturan</button></div>
  </form>
</section>

<section class="card">
  <div class="card-kepala"><div><h2>Sumber Data Inventaris Sarpras</h2>
    <p class="card-ket">Dibaca hanya-baca (mode query_only) dari basis data aplikasi e-GURU.</p></div></div>
  <div class="tabel-bungkus">
  <table class="tbl">
    <tbody>
      <tr><td style="width:220px">Status koneksi</td><td><?= $sarprasAda ? '<span class="chip hijau">Terhubung</span>' : '<span class="chip merah">Belum terhubung</span>' ?></td></tr>
      <tr><td>Berkas</td><td class="mono"><?= e(setting('sarpras_sumber')) ?></td></tr>
      <tr><td>Data lembaga di e-GURU</td><td><?= $infoSarpras['id'] > 0 ? e($infoSarpras['nama']) . ' (id ' . (int) $infoSarpras['id'] . ')' : '<span class="mut">tidak ditemukan — cocokkan Nama Lembaga di atas dengan nama sekolah pada e-GURU</span>' ?></td></tr>
    </tbody>
  </table>
  </div>
  <p class="kecil mut mb0">TUSmart tidak pernah menulis ke basis data e-GURU. Bila e-GURU dipindah, ubah jalur berkas di atas (Super Admin).</p>
</section>

<section class="card">
  <div class="card-kepala"><div><h2>Sumber Data Absensi Guru</h2>
    <p class="card-ket">Dibaca hanya-baca (mode query_only) dari basis data aplikasi Absensi Guru — dipakai menu <b>Laporan Absensi-Guru</b>.</p></div></div>
  <div class="tabel-bungkus">
  <table class="tbl">
    <tbody>
      <tr><td style="width:220px">Status koneksi</td><td><?= $absguruAda ? '<span class="chip hijau">Terhubung</span>' : '<span class="chip merah">Belum terhubung</span>' ?></td></tr>
      <tr><td>Berkas</td><td class="mono"><?= e(absguru_sumber_aktif() !== '' ? absguru_sumber_aktif() : setting('absguru_sumber')) ?></td></tr>
      <tr><td>Data lembaga di Absensi Guru</td><td><?= $absguruAda ? e($infoAbsguru['nama']) . ' · ' . $jmlAbsguru . ' guru aktif' : '<span class="mut">tidak terbaca</span>' ?></td></tr>
    </tbody>
  </table>
  </div>
  <p class="kecil mut mb0">Absensi yang diisi guru pada aplikasi Absensi Guru langsung tampil pada menu Laporan Absensi-Guru (tanpa proses kirim/salin data). TUSmart tidak pernah menulis ke basis data Absensi Guru.</p>
</section>

<section class="card" id="kelas">
  <div class="card-kepala"><div><h2>Data Kelas</h2><p class="card-ket">Dipakai pada Data Siswa, Buku Induk, dan Buku Klapper.</p></div></div>
  <form method="post" class="saring">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="kelas_simpan"><input type="hidden" name="kelas_id" value="<?= (int) ($kEdit['id'] ?? 0) ?>">
    <div class="medan"><label for="kelas_nama">Nama Kelas</label><input type="text" id="kelas_nama" name="kelas_nama" value="<?= e($kEdit['nama'] ?? '') ?>" required maxlength="60" placeholder="mis. Kelas X"></div>
    <div class="medan"><label for="tingkat">Tingkat</label><input type="text" id="tingkat" name="tingkat" value="<?= e($kEdit['tingkat'] ?? '') ?>" maxlength="20" placeholder="mis. X"></div>
    <div class="medan cari"><label for="wali">Wali Kelas</label><input type="text" id="wali" name="wali" value="<?= e($kEdit['wali'] ?? '') ?>" maxlength="120"></div>
    <div class="medan"><label for="kelas_aktif">Status</label><select id="kelas_aktif" name="kelas_aktif"><?= opsi(['1' => 'Aktif', '0' => 'Nonaktif'], (string) ($kEdit['aktif'] ?? '1')) ?></select></div>
    <button class="btn" type="submit"><?= ikon($kEdit ? 'cek' : 'tambah') ?> <?= $kEdit ? 'Simpan Perubahan' : 'Tambah Kelas' ?></button>
    <?php if ($kEdit): ?><a class="btn btn-garis" href="pengaturan_lembaga.php#kelas">Batal</a><?php endif; ?>
  </form>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr><th class="tengah" style="width:38px">No</th><th>Nama Kelas</th><th class="tengah">Tingkat</th><th>Wali Kelas</th><th class="tengah">Siswa</th><th class="tengah">Status</th><th style="width:120px">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($kelasSemua as $i => $k): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($k['nama']) ?></b></td>
          <td class="tengah"><?= e($k['tingkat'] ?: '-') ?></td>
          <td><?= e($k['wali'] ?: '-') ?></td>
          <td class="tengah"><?= (int) $k['jumlah'] ?></td>
          <td class="tengah"><span class="pil <?= (int) $k['aktif'] === 1 ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= (int) $k['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td>
            <div class="aksi">
              <a class="btn btn-garis btn-kecil" href="pengaturan_lembaga.php?edit_kelas=<?= (int) $k['id'] ?>#kelas"><?= ikon('ubah') ?></a>
              <form method="post" data-konfirmasi="Hapus kelas <?= e($k['nama']) ?>? Siswa di kelas ini akan menjadi tanpa kelas.">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="kelas_hapus"><input type="hidden" name="kelas_id" value="<?= (int) $k['id'] ?>">
                <button class="btn btn-bahaya btn-garis btn-kecil" type="submit"><?= ikon('hapus') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kelasSemua): ?><tr><td colspan="7" class="kosong">Belum ada kelas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_bottom(); ?>
