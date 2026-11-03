<?php
/**
 * Surat Keluar: pembuatan, penomoran otomatis, verifikasi, pemberkasan.
 *
 * v1.6.0: EMPAT JENIS SURAT — Surat Rekomendasi Pindah, Surat Keterangan Pindah Sekolah,
 * Persetujuan Pindah / Lolos Butuh (template resmi, medan diisi otomatis dari Data Siswa),
 * dan Surat Umum (isi bebas, perilaku lama).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$editId = idf('edit');
$edit = $editId > 0 ? satu('SELECT * FROM surat_keluar WHERE id = ?', [$editId]) : null;
$galat = [];
$petaStatus = status_surat_keluar();
$petaJenis = surat_jenis_list();
$daftarSiswa = semua("SELECT s.*, k.tingkat AS kelas_tingkat, k.nama AS kelas_nama FROM siswa s
    LEFT JOIN kelas k ON k.id = s.kelas_id ORDER BY s.nama");
$siswaPilih = idf('siswa');
$siswaPilihRow = $siswaPilih > 0 ? satu('SELECT * FROM siswa WHERE id = ?', [$siswaPilih]) : null;

/** Jenis surat yang sedang dibuka (dari POST, dari data yang diubah, atau dari pemilih jenis). */
function surat_jenis_halaman(?array $edit): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { return surat_jenis_sah(f('jenis')); }
    if ($edit) { return surat_jenis_sah((string) ($edit['jenis'] ?? SURAT_JENIS_UMUM)); }
    if (f('jenis') !== '') { return surat_jenis_sah(f('jenis')); }
    return SURAT_JENIS_UMUM;
}

// ---------- simpan ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'simpan') {
    csrf_wajib();
    $id = idf('id');
    $lama = $id > 0 ? satu('SELECT * FROM surat_keluar WHERE id = ?', [$id]) : null;
    $jenis = surat_jenis_sah(f('jenis'));
    $siswaId = idf('siswa_id');
    $siswa = $siswaId > 0 ? satu('SELECT * FROM siswa WHERE id = ?', [$siswaId]) : null;
    if (!$siswa) { $siswaId = 0; }
    $data = surat_data_bersih($jenis, is_array($_POST['data'] ?? null) ? $_POST['data'] : []);
    // Medan yang dikosongkan dilengkapi dari Data Siswa (mis. TTL/ibu/NISN/NISM).
    if ($siswa) { $data = surat_lengkapi_siswa($data, $siswa); }
    if ($jenis === 'rekomendasi_pindah' && trim((string) $data['nism']) === '') {
        $data['nism'] = surat_nism_cadangan(['siswa_id' => $siswaId]);
    }

    $perihal = $jenis === SURAT_JENIS_UMUM ? f('perihal') : trim((string) ($data['perihal'] ?? ''));
    $tujuan = f('tujuan');
    if ($jenis !== SURAT_JENIS_UMUM && $tujuan === '') {
        $tujuan = surat_jenis_perlu_siswa($jenis)
            ? trim((string) ($data['sekolah_tujuan'] ?? ($data['tujuan_jabatan'] ?? '')))
            : '';
    }
    $kol = [
        'no_surat' => f('no_surat'),
        'jenis' => $jenis,
        'siswa_id' => $siswaId > 0 ? $siswaId : null,
        'data' => $jenis === SURAT_JENIS_UMUM ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'tanggal_surat' => f('tanggal_surat') !== '' ? f('tanggal_surat') : tanggal_hari_ini(),
        'tujuan' => $tujuan,
        'perihal' => $perihal,
        'sifat' => array_key_exists(f('sifat'), sifat_surat_list()) ? f('sifat') : 'biasa',
        'lampiran' => $jenis === SURAT_JENIS_UMUM ? f('lampiran') : trim((string) ($data['lampiran'] ?? '')),
        'isi' => $jenis === SURAT_JENIS_UMUM ? f('isi') : '',
    ];
    if ($perihal === '') { $galat[] = 'Perihal surat wajib diisi.'; }
    if (!tanggal_sah($kol['tanggal_surat'])) { $galat[] = 'Tanggal surat tidak sah.'; }
    if ($jenis !== SURAT_JENIS_UMUM) {
        if (trim((string) ($data['nama'] ?? '')) === '') {
            $galat[] = 'Nama siswa wajib diisi — pilih siswa dari Data Siswa atau ketik namanya.';
        }
        if (in_array($jenis, ['rekomendasi_pindah', 'pindah'], true) && trim((string) ($data['sekolah_tujuan'] ?? '')) === '') {
            $galat[] = 'Madrasah/sekolah tujuan wajib diisi.';
        }
    }
    if ($kol['no_surat'] === '') {
        $kol['no_surat'] = nomor_surat_berikut($kol['tanggal_surat']);
    }
    $bentrok = satu('SELECT id FROM surat_keluar WHERE no_surat = ? AND id <> ?', [$kol['no_surat'], $id]);
    if ($bentrok) { $galat[] = 'Nomor surat ' . $kol['no_surat'] . ' sudah dipakai surat lain.'; }

    $berkas = ['url' => $lama['berkas_url'] ?? '', 'nama' => $lama['berkas_nama'] ?? '', 'ukuran' => (int) ($lama['berkas_ukuran'] ?? 0)];
    if (!$galat && !empty($_FILES['berkas']['name'])) {
        $up = unggah_berkas('berkas');
        if (!empty($up['ok'])) {
            $berkas = ['url' => $up['url'], 'nama' => $up['nama'], 'ukuran' => (int) $up['ukuran']];
        } elseif (empty($up['kosong'])) {
            $galat[] = 'Berkas surat: ' . ($up['pesan'] ?? 'gagal diunggah.');
        }
    } elseif (f('hapus_berkas') === '1') {
        $berkas = ['url' => '', 'nama' => '', 'ukuran' => 0];
    }

    if (!$galat) {
        $kol['berkas_url'] = $berkas['url'];
        $kol['berkas_nama'] = $berkas['nama'];
        $kol['berkas_ukuran'] = $berkas['ukuran'];
        if ($id > 0) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
            $arg = array_values($kol);
            $arg[] = current_user()['nama'];
            $arg[] = date('Y-m-d H:i:s');
            $arg[] = $id;
            q("UPDATE surat_keluar SET $set, oleh = ?, diubah = ? WHERE id = ?", $arg);
            log_catat('ubah_surat_keluar', $kol['no_surat'] . ' · ' . $kol['perihal']);
            flash('Surat keluar ' . $kol['no_surat'] . ' diperbarui.');
        } else {
            $kol['no_agenda'] = agenda_berikut('surat_keluar', (int) substr($kol['tanggal_surat'], 0, 4), 'tanggal_surat');
            $kol['status'] = 'draf';
            $kol['oleh'] = current_user()['nama'];
            $kol['dibuat'] = date('Y-m-d H:i:s');
            $kol['diubah'] = date('Y-m-d H:i:s');
            $nm = array_keys($kol);
            q('INSERT INTO surat_keluar (' . implode(',', $nm) . ') VALUES (' . implode(',', array_fill(0, count($nm), '?')) . ')', array_values($kol));
            $id = (int) $pdo->lastInsertId();
            log_catat('tambah_surat_keluar', $kol['no_surat'] . ' · ' . $kol['perihal']);
            flash('Surat keluar ' . $kol['no_surat'] . ' dibuat sebagai Draf. Lakukan verifikasi bila sudah benar.');
        }
        arsip_otomatis('keluar', $id, 'Surat Keluar ' . $kol['no_surat'] . ' — ' . $kol['perihal'], (string) $kol['tanggal_surat'], (string) $kol['berkas_url'], (string) $kol['berkas_nama'], (int) $kol['berkas_ukuran']);
        redirect('surat_keluar.php');
    }
    $edit = array_merge([
        'id' => $id, 'no_surat' => $kol['no_surat'], 'jenis' => $jenis, 'siswa_id' => $siswaId,
        'data' => $kol['data'], 'tanggal_surat' => $kol['tanggal_surat'], 'tujuan' => $kol['tujuan'],
        'perihal' => $kol['perihal'], 'sifat' => $kol['sifat'], 'lampiran' => $kol['lampiran'], 'isi' => $kol['isi'],
        'berkas_url' => $berkas['url'], 'berkas_nama' => $berkas['nama'], 'berkas_ukuran' => $berkas['ukuran'], 'status' => $lama['status'] ?? 'draf',
    ]);
}

// ---------- verifikasi / batalkan verifikasi / kirim ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(aksi_post(), ['verifikasi', 'kirim', 'draf'], true)) {
    csrf_wajib();
    $id = idf('id');
    $s = satu('SELECT * FROM surat_keluar WHERE id = ?', [$id]);
    if (!$s) {
        flash('Surat keluar tidak ditemukan.', 'err');
        redirect('surat_keluar.php');
    }
    $aksi = aksi_post();
    if ($aksi === 'verifikasi') {
        q("UPDATE surat_keluar SET status = 'verifikasi', verifikator = ?, verifikasi_pada = ?, catatan_verifikasi = ?, diubah = ? WHERE id = ?",
            [current_user()['nama'], date('Y-m-d H:i:s'), f('catatan'), date('Y-m-d H:i:s'), $id]);
        log_catat('verifikasi_surat', $s['no_surat']);
        flash('Surat ' . $s['no_surat'] . ' diverifikasi atas nama ' . current_user()['nama'] . '.');
    } elseif ($aksi === 'kirim') {
        if ($s['status'] === 'draf') {
            flash('Surat harus diverifikasi terlebih dahulu sebelum ditandai terkirim.', 'err');
        } else {
            q("UPDATE surat_keluar SET status = 'terkirim', diubah = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
            log_catat('kirim_surat', $s['no_surat']);
            flash('Surat ' . $s['no_surat'] . ' ditandai sudah terkirim.');
        }
    } else {
        q("UPDATE surat_keluar SET status = 'draf', verifikator = NULL, verifikasi_pada = NULL, catatan_verifikasi = NULL, diubah = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
        log_catat('batal_verifikasi_surat', $s['no_surat']);
        flash('Verifikasi surat ' . $s['no_surat'] . ' dibatalkan (kembali Draf).');
    }
    redirect('surat_keluar.php');
}

// ---------- hapus ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'hapus') {
    csrf_wajib();
    $id = idf('id');
    $s = satu('SELECT * FROM surat_keluar WHERE id = ?', [$id]);
    if (!$s) {
        flash('Surat keluar tidak ditemukan.', 'err');
    } else {
        q('DELETE FROM surat_keluar WHERE id = ?', [$id]);
        q("DELETE FROM arsip WHERE sumber = 'keluar' AND ref_id = ?", [$id]);
        log_catat('hapus_surat_keluar', $s['no_surat']);
        flash('Surat keluar ' . $s['no_surat'] . ' dihapus.');
    }
    redirect('surat_keluar.php');
}

// ---------- daftar ----------
$cari = f('cari');
$status = f('status');
$jenisSaring = f('jenis_surat');
$dari = tanggal_sah(f('dari')) ? f('dari') : '';
$sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(no_surat LIKE ? OR no_agenda LIKE ? OR tujuan LIKE ? OR perihal LIKE ? OR isi LIKE ? OR data LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k);
}
if ($status !== '') { $w[] = 'status = ?'; $arg[] = $status; }
if ($jenisSaring !== '' && array_key_exists($jenisSaring, $petaJenis)) { $w[] = 'jenis = ?'; $arg[] = $jenisSaring; }
if ($dari !== '') { $w[] = 'tanggal_surat >= ?'; $arg[] = $dari; }
if ($sampai !== '') { $w[] = 'tanggal_surat <= ?'; $arg[] = $sampai; }
$daftar = semua('SELECT * FROM surat_keluar' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY tanggal_surat DESC, id DESC', $arg);
$qCetak = http_build_query(array_filter(['cari' => $cari, 'status' => $status, 'jenis_surat' => $jenisSaring, 'dari' => $dari, 'sampai' => $sampai]));

$headerEkspor = ['No', 'No Agenda', 'No Surat', 'Jenis Surat', 'Tanggal', 'Tujuan', 'Perihal', 'Sifat', 'Lampiran', 'Status', 'Verifikator', 'Tanggal Verifikasi', 'Berkas'];
$barisEkspor = [];
foreach ($daftar as $i => $s) {
    $barisEkspor[] = [$i + 1, $s['no_agenda'], $s['no_surat'], surat_jenis_label($s['jenis'] ?? ''), ekspor_tgl($s['tanggal_surat']),
        $s['tujuan'], $s['perihal'], sifat_surat_list()[$s['sifat']] ?? $s['sifat'], $s['lampiran'],
        $petaStatus[$s['status']] ?? $s['status'], $s['verifikator'], ekspor_tgl(substr((string) $s['verifikasi_pada'], 0, 10)), $s['berkas_nama']];
}
if (f('unduh') === 'csv') {
    csv_kirim('surat-keluar', $headerEkspor, $barisEkspor);
}

$jenis = surat_jenis_halaman($edit);
$medan = surat_medan($jenis);
$dataNilai = surat_data_baku($jenis);
if ($edit) {
    if (surat_jenis_sah((string) ($edit['jenis'] ?? '')) === $jenis) {
        $dataNilai = surat_data($edit);
    } else {
        // Ganti jenis saat mengubah: pertahankan data lama yang kuncinya sama.
        $dataNilai = array_merge($dataNilai, array_intersect_key(surat_data($edit), $dataNilai));
    }
}
if ($siswaPilihRow) {
    $dataNilai = surat_lengkapi_siswa($dataNilai, $siswaPilihRow);
}
$siswaTerpilih = (int) ($edit['siswa_id'] ?? 0);
if ($siswaTerpilih <= 0 && $siswaPilihRow) { $siswaTerpilih = (int) $siswaPilihRow['id']; }

$nilai = [
    'id' => $edit['id'] ?? 0, 'no_surat' => $edit['no_surat'] ?? nomor_surat_berikut(tanggal_hari_ini()),
    'tanggal_surat' => $edit['tanggal_surat'] ?? tanggal_hari_ini(), 'tujuan' => $edit['tujuan'] ?? '',
    'perihal' => $edit['perihal'] ?? ($jenis === SURAT_JENIS_UMUM ? '' : (string) ($dataNilai['perihal'] ?? '')),
    'sifat' => $edit['sifat'] ?? 'biasa', 'lampiran' => $edit['lampiran'] ?? (string) ($dataNilai['lampiran'] ?? ''),
    'isi' => $edit['isi'] ?? '', 'berkas_url' => $edit['berkas_url'] ?? '', 'berkas_nama' => $edit['berkas_nama'] ?? '',
    'berkas_ukuran' => $edit['berkas_ukuran'] ?? 0, 'status' => $edit['status'] ?? 'draf',
];
$tampilForm = $edit !== null || f('aksi') === 'baru' || $galat;

// Peta Data Siswa untuk mengisi medan secara otomatis di peramban (tanpa memuat ulang halaman).
$petaSiswa = [];
foreach ($daftarSiswa as $s) {
    $petaSiswa[(string) (int) $s['id']] = surat_data_siswa($s);
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Surat Keluar', 'Administrasi persuratan · pembuatan, verifikasi, dan pemberkasan');
?>
<div class="card" id="konten">
  <div class="card-kepala">
    <div><h2>Pembuatan Surat Keluar</h2>
      <p class="card-ket">Pilih <b>jenis surat</b> lebih dahulu — medan isiannya menyesuaikan template resmi
        (Rekomendasi Pindah, Surat Pindah, Lolos Butuh). Nomor surat dibuat otomatis dengan pola
        <span class="mono">019/<?= e(setting('kode_surat', KODE_SURAT_DEFAULT)) ?>/<?= e(bulan_romawi((int) date('n'))) ?>/<?= date('Y') ?></span>
        (dapat diubah manual).</p></div>
    <div class="card-aksi">
      <?php if ($tampilForm): ?><a class="btn btn-garis" href="surat_keluar.php">Tutup Formulir</a><?php else: ?><a class="btn" href="surat_keluar.php?aksi=baru"><?= ikon('tambah') ?> Buat Surat Baru</a><?php endif; ?>
    </div>
  </div>
  <?php if ($tampilForm): ?>
    <?php if ($galat): ?><div class="alert alert-err"><?php foreach ($galat as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>

    <form method="get" class="saring" id="formJenisSurat" style="margin-bottom:14px">
      <input type="hidden" name="aksi" value="baru">
      <?php if ((int) $nilai['id'] > 0): ?><input type="hidden" name="edit" value="<?= (int) $nilai['id'] ?>"><?php endif; ?>
      <div class="medan cari"><label for="jenis_surat_pilih">Jenis Surat</label>
        <select id="jenis_surat_pilih" name="jenis" data-auto>
          <?php foreach ($petaJenis as $kj => $lj): ?>
            <option value="<?= e($kj) ?>"<?= $kj === $jenis ? ' selected' : '' ?>><?= e($lj) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="petunjuk">Mengganti jenis akan memuat medan isian yang sesuai.</span></div>
      <button class="btn btn-garis" type="submit"><?= ikon('ubah') ?> Tampilkan Medan</button>
    </form>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int) $nilai['id'] ?>">
      <input type="hidden" name="jenis" value="<?= e($jenis) ?>">

      <div class="form-grid f3">
        <div class="medan"><label for="no_surat">Nomor Surat</label>
          <input type="text" id="no_surat" name="no_surat" value="<?= e($nilai['no_surat']) ?>" maxlength="80">
          <span class="petunjuk">Biarkan sesuai usulan otomatis, atau tulis nomor resmi dari buku agenda.</span></div>
        <div class="medan"><label for="tanggal_surat">Tanggal Surat</label><input type="date" id="tanggal_surat" name="tanggal_surat" value="<?= e($nilai['tanggal_surat']) ?>" required></div>
        <div class="medan"><label for="sifat">Sifat</label><select id="sifat" name="sifat"><?= opsi(sifat_surat_list(), $nilai['sifat']) ?></select></div>
        <?php if ($jenis === SURAT_JENIS_UMUM): ?>
          <div class="medan" style="grid-column:1/3"><label for="tujuan">Tujuan Surat</label>
            <input type="text" id="tujuan" name="tujuan" value="<?= e($nilai['tujuan']) ?>" maxlength="200" placeholder="mis. Kepala Kantor Kementerian Agama Kabupaten Gorontalo"></div>
          <div class="medan"><label for="lampiran">Lampiran</label><input type="text" id="lampiran" name="lampiran" value="<?= e($nilai['lampiran']) ?>" maxlength="80" placeholder="mis. 1 berkas"></div>
          <div class="medan" style="grid-column:1/-1"><label for="perihal">Perihal</label>
            <input type="text" id="perihal" name="perihal" value="<?= e($nilai['perihal']) ?>" maxlength="200" required></div>
          <div class="medan" style="grid-column:1/-1"><label for="isi">Isi Ringkas / Keterangan Surat</label>
            <textarea id="isi" name="isi" rows="3" maxlength="1500" placeholder="Ringkasan isi surat atau keterangan tambahan."><?= e($nilai['isi']) ?></textarea></div>
        <?php else: ?>
          <div class="medan"><label for="tujuan_ringkas">Tujuan (untuk daftar &amp; saringan)</label>
            <input type="text" id="tujuan_ringkas" name="tujuan" value="<?= e($nilai['tujuan']) ?>" maxlength="200"
              placeholder="otomatis dari sekolah tujuan bila dikosongkan">
            <span class="petunjuk">Dipakai pada pencarian daftar surat; isi surat memakai medan di bawah.</span></div>
          <?php if (surat_jenis_perlu_siswa($jenis)): ?>
            <div class="medan" style="grid-column:2/4"><label for="siswa_id">Ambil dari Data Siswa</label>
              <select id="siswa_id" name="siswa_id" data-isi-siswa>
                <option value="">— ketik manual / tidak memakai data siswa —</option>
                <?php foreach ($daftarSiswa as $ds): ?>
                  <option value="<?= (int) $ds['id'] ?>"<?= (int) $ds['id'] === $siswaTerpilih ? ' selected' : '' ?>>
                    <?= e($ds['nama'] . ($ds['kelas_nama'] ? ' — ' . $ds['kelas_nama'] : '') . ($ds['nisn'] ? ' · ' . $ds['nisn'] : '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="petunjuk">Memilih siswa mengisi medan nama, TTL, jenis kelamin, ibu, alamat, kelas, NISN,
                NISM secara otomatis &mdash; semuanya tetap dapat disunting.</span></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($jenis !== SURAT_JENIS_UMUM): ?>
        <h3 class="bagian-judul">Isi Surat — <?= e(surat_jenis_label($jenis)) ?></h3>
        <div class="form-grid f2">
          <?php foreach ($medan as $kunci => $m): $nilaiMedan = (string) ($dataNilai[$kunci] ?? ''); ?>
            <div class="medan"<?= ($m['tipe'] ?? '') === 'panjang' ? ' style="grid-column:1/-1"' : '' ?>>
              <label for="data_<?= e($kunci) ?>"><?= e($m['label']) ?><?= !empty($m['wajib']) ? ' *' : '' ?></label>
              <?php if (($m['tipe'] ?? '') === 'panjang'): ?>
                <textarea id="data_<?= e($kunci) ?>" name="data[<?= e($kunci) ?>]" rows="<?= (int) ($m['baris'] ?? 4) ?>"><?= e($nilaiMedan) ?></textarea>
              <?php elseif (($m['tipe'] ?? '') === 'pilih'): ?>
                <select id="data_<?= e($kunci) ?>" name="data[<?= e($kunci) ?>]" data-medan="<?= e($kunci) ?>"><?= opsi((array) $m['pilihan'], $nilaiMedan) ?></select>
              <?php else: ?>
                <input type="text" id="data_<?= e($kunci) ?>" name="data[<?= e($kunci) ?>]" data-medan="<?= e($kunci) ?>"
                  value="<?= e($nilaiMedan) ?>" maxlength="250">
              <?php endif; ?>
              <?php if (!empty($m['petunjuk'])): ?><span class="petunjuk"><?= e($m['petunjuk']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($jenis === 'lolos_butuh'): ?>
          <h3 class="bagian-judul">Proses Penyelesaian Pindah</h3>
          <div class="tabel-bungkus">
            <table class="tbl">
              <thead><tr><th class="tengah" style="width:40px">No</th><th>Uraian</th>
                <th style="width:170px">Tanggal</th><th>Keterangan</th></tr></thead>
              <tbody>
              <?php foreach (surat_langkah_baku() as $i => $namaLangkah): ?>
                <tr>
                  <td class="tengah"><?= $i + 1 ?></td>
                  <td><?= e($namaLangkah) ?></td>
                  <td><input type="text" id="langkah_<?= $i ?>_tanggal" name="data[langkah_<?= $i ?>_tanggal]"
                    value="<?= e((string) ($dataNilai['langkah_' . $i . '_tanggal'] ?? '')) ?>" maxlength="60"
                    placeholder="mis. 05 Januari 2025"></td>
                  <td><input type="text" id="langkah_<?= $i ?>_ket" name="data[langkah_<?= $i ?>_ket]"
                    value="<?= e((string) ($dataNilai['langkah_' . $i . '_ket'] ?? '')) ?>" maxlength="120"></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="form-grid f3 mt16">
        <div class="medan"><label for="berkas">Berkas Surat (opsional)</label>
          <input type="file" id="berkas" name="berkas" data-pratinjau="#pratinjauBerkas">
          <?php if (($nilai['berkas_url'] ?? '') !== ''): ?>
            <div class="berkas-ada"><?= ikon('arsip') ?> <a href="<?= e($nilai['berkas_url']) ?>" target="_blank" rel="noopener"><?= e($nilai['berkas_nama']) ?></a>
              <span class="mut">(<?= e(ukuran_teks((int) $nilai['berkas_ukuran'])) ?>)</span></div>
            <label class="centang"><input type="checkbox" name="hapus_berkas" value="1"> Hapus berkas ini</label>
          <?php endif; ?>
          <div class="pratinjau" id="pratinjauBerkas">Belum ada berkas dipilih.</div>
        </div>
      </div>
      <div class="tombol-baris mt16">
        <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Surat</button>
        <a class="btn btn-garis" href="surat_keluar.php">Batal</a>
      </div>
    </form>
    <script type="application/json" id="dataSiswaSurat"><?= json_encode($petaSiswa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-kepala">
    <div><h2>Daftar Surat Keluar</h2>
      <p class="card-ket"><?= count($daftar) ?> surat · <?= (int) kolom("SELECT COUNT(*) FROM surat_keluar WHERE status = 'draf'") ?> draf menunggu verifikasi.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="surat-keluar"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_surat_keluar.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>
  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nomor, tujuan, perihal, nama siswa…"></div>
    <div class="medan"><label for="jenis_surat">Jenis Surat</label><select id="jenis_surat" name="jenis_surat" data-auto><?= opsi($petaJenis, $jenisSaring, 'Semua jenis') ?></select></div>
    <div class="medan"><label for="status">Status</label><select id="status" name="status" data-auto><?= opsi($petaStatus, $status, 'Semua status') ?></select></div>
    <div class="medan"><label for="dari">Dari Tanggal</label><input type="date" id="dari" name="dari" value="<?= e($dari) ?>" data-auto></div>
    <div class="medan"><label for="sampai">Sampai</label><input type="date" id="sampai" name="sampai" value="<?= e($sampai) ?>" data-auto></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $status !== '' || $jenisSaring !== '' || $dari !== '' || $sampai !== ''): ?><a class="btn btn-garis" href="surat_keluar.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada surat keluar yang cocok.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:36px">No</th><th class="tengah">Nomor / Tanggal</th><th>Jenis Surat</th>
          <th>Tujuan</th><th>Perihal</th>
          <th class="tengah">Sifat</th><th class="tengah">Status</th><th class="tengah">Verifikasi</th><th class="tengah">Berkas</th><th style="width:180px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $s): $ringkas = surat_ringkas($s); ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah mono"><?= e($s['no_surat']) ?><span class="sel-sub"><?= e(tanggal_id($s['tanggal_surat'])) ?></span></td>
            <td><span class="chip <?= surat_jenis_sah($s['jenis'] ?? '') === SURAT_JENIS_UMUM ? 'mut' : 'emas' ?>"><?= e(surat_jenis_label($s['jenis'] ?? '')) ?></span>
              <?= $ringkas !== '' ? '<span class="sel-sub">' . e($ringkas) . '</span>' : '' ?></td>
            <td><?= e($s['tujuan'] ?: '-') ?></td>
            <td><?= e($s['perihal']) ?><?= $s['lampiran'] ? '<span class="sel-sub">Lampiran: ' . e($s['lampiran']) . '</span>' : '' ?></td>
            <td class="tengah"><span class="chip <?= in_array($s['sifat'], ['penting', 'segera'], true) ? 'emas' : ($s['sifat'] === 'rahasia' ? 'merah' : 'mut') ?>"><?= e(sifat_surat_list()[$s['sifat']] ?? $s['sifat']) ?></span></td>
            <td class="tengah"><span class="pil pil-<?= e($s['status']) ?>"><?= e($petaStatus[$s['status']] ?? $s['status']) ?></span></td>
            <td class="tengah"><?= e($s['verifikator'] ?: '-') ?><?= $s['verifikasi_pada'] ? '<span class="sel-sub">' . e(waktu_id($s['verifikasi_pada'])) . '</span>' : '' ?></td>
            <td class="tengah"><?php if ($s['berkas_url']): ?><a class="btn btn-garis btn-kecil" href="<?= e($s['berkas_url']) ?>" target="_blank" rel="noopener"><?= ikon('unduh') ?></a><?php else: ?><span class="mut kecil">-</span><?php endif; ?></td>
            <td>
              <div class="aksi">
                <a class="btn btn-garis btn-kecil" href="surat_keluar_cetak.php?id=<?= (int) $s['id'] ?>" target="_blank" rel="noopener"><?= ikon('cetak') ?> Surat</a>
                <a class="btn btn-garis btn-kecil" href="surat_keluar.php?edit=<?= (int) $s['id'] ?>#konten"><?= ikon('ubah') ?></a>
                <?php if ($s['status'] === 'draf'): ?>
                  <form method="post" data-konfirmasi="Verifikasi surat <?= e($s['no_surat']) ?> atas nama Anda?">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-ok btn-garis btn-kecil" type="submit"><?= ikon('cek') ?> Verifikasi</button>
                  </form>
                <?php elseif ($s['status'] === 'verifikasi'): ?>
                  <form method="post" data-konfirmasi="Tandai surat <?= e($s['no_surat']) ?> sudah terkirim?">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="kirim"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-ok btn-garis btn-kecil" type="submit">Terkirim</button>
                  </form>
                <?php else: ?>
                  <form method="post" data-konfirmasi="Batalkan status terkirim dan kembalikan ke Draf?">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="draf"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-garis btn-kecil" type="submit">Batal Verifikasi</button>
                  </form>
                <?php endif; ?>
                <form method="post" data-konfirmasi="Hapus surat keluar <?= e($s['no_surat']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
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

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Surat Keluar', 'sheet' => 'Surat Keluar', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
