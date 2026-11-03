<?php
/**
 * IMPOR KEHADIRAN GURU dari PDF laporan absensi bulanan aplikasi lain.
 *
 * Alur: unggah PDF → pratinjau yang DAPAT DISUNTING (jam masuk/pulang per guru per tanggal,
 * pemetaan nama guru bila tidak otomatis cocok) → simpan ke tabel `absensi_guru`.
 * Setelah tersimpan, rekaman langsung tampil pada perekaman harian (bisa diubah lagi) dan ikut
 * dihitung pada seluruh laporan absensi (harian, bulanan, per guru) serta lembar cetaknya.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/absensi_impor.php';
require_login();

$daftarGuru = guru_list(true);
$sesi = $_SESSION['impor_absensi'] ?? null;

// ---------------------------------------------------------------- batalkan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'batal') {
    csrf_wajib();
    unset($_SESSION['impor_absensi']);
    flash('Pratinjau impor dibatalkan.');
    redirect('absensi_impor.php');
}

// ---------------------------------------------------------------- baca PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'pratinjau') {
    csrf_wajib();
    $bulanCadangan = preg_match('/^\d{4}-\d{2}$/', f('bulan')) ? f('bulan') : '';
    if (empty($_FILES['berkas']['name']) || ($_FILES['berkas']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        flash('Pilih berkas PDF laporan absensi terlebih dahulu.', 'err');
        redirect('absensi_impor.php');
    }
    $f = $_FILES['berkas'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        $pesan = in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Ukuran berkas melebihi batas kirim server.'
            : 'Berkas gagal diunggah (kode ' . (int) $f['error'] . ').';
        flash($pesan, 'err');
        redirect('absensi_impor.php');
    }
    $nama = (string) $f['name'];
    if (ekstensi_berkas($nama) !== 'pdf') {
        flash('Berkas harus berformat PDF (hasil cetak laporan absensi).', 'err');
        redirect('absensi_impor.php');
    }
    if ((int) $f['size'] > 20 * 1024 * 1024) {
        flash('Ukuran PDF melebihi 20 MB.', 'err');
        redirect('absensi_impor.php');
    }
    $bytes = (string) @file_get_contents((string) $f['tmp_name']);
    $hasil = absensi_impor_baca_pdf($bytes, $bulanCadangan);
    if ($hasil['galat'] !== '') {
        flash($hasil['galat'], 'err');
        redirect('absensi_impor.php');
    }
    $cocok = ai_cocok_guru($hasil['guru'], $daftarGuru);
    $_SESSION['impor_absensi'] = [
        'berkas' => $nama,
        'bulan' => $hasil['bulan'],
        'guru' => $hasil['guru'],
        'peringatan' => $hasil['peringatan'],
        'cocok' => $cocok,
        'waktu' => date('Y-m-d H:i:s'),
    ];
    $tidakCocok = 0;
    foreach ($cocok as $id) { if ($id === null) { $tidakCocok++; } }
    flash('PDF berhasil dibaca: ' . count($hasil['guru']) . ' guru, bulan ' . $hasil['bulan']
        . ($tidakCocok > 0 ? ' — ' . $tidakCocok . ' nama guru perlu dipilih manual pada pratinjau.' : ' (semua nama guru cocok otomatis).'));
    redirect('absensi_impor.php');
}

// ---------------------------------------------------------------- simpan ke absensi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'simpan') {
    csrf_wajib();
    if (!$sesi) {
        flash('Pratinjau impor sudah tidak tersedia. Unggah ulang berkas PDF.', 'err');
        redirect('absensi_impor.php');
    }
    $peta = is_array($_POST['guru_id'] ?? null) ? $_POST['guru_id'] : [];
    $baris = is_array($_POST['baris'] ?? null) ? $_POST['baris'] : [];
    $timpa = f('timpa') === '1';
    $guruSah = [];
    foreach ($daftarGuru as $g) { $guruSah[(int) $g['id']] = $g; }

    $jumlah = 0;
    $dilewati = 0;
    $tanpaGuru = 0;
    $dicatat = [];
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($baris as $kunci => $isi) {
            if (!is_array($isi)) { continue; }
            if (empty($isi['pakai'])) { $dilewati++; continue; }
            // Kunci baris berbentuk "<indeksGrup>_<tanggal>"; pemetaan guru memakai indeks grup.
            $indeksGrup = (int) explode('_', (string) $kunci)[0];
            $gid = (int) ($peta[$indeksGrup] ?? 0);
            if (!isset($guruSah[$gid])) { $tanpaGuru++; continue; }
            $tgl = (string) ($isi['tanggal'] ?? '');
            if (!tanggal_sah($tgl)) { $dilewati++; continue; }
            $masuk = jam_rapi((string) ($isi['masuk'] ?? ''));
            $keluar = jam_rapi((string) ($isi['keluar'] ?? ''));
            $ket = substr(trim((string) ($isi['keterangan'] ?? '')), 0, 200);
            if ($masuk === '' && $keluar === '') { $dilewati++; continue; }

            $jadwalHari = jam_kerja_hari($tgl);
            $kMasuk = $masuk !== '' ? (string) ($isi['k_masuk'] ?? '') : '';
            if (!in_array($kMasuk, ['telat', 'tepat'], true)) {
                $kMasuk = $masuk !== '' ? absensi_kategori_masuk($masuk, $jadwalHari['masuk']) : '';
            }
            $kPulang = $keluar !== '' ? (string) ($isi['k_pulang'] ?? '') : '';
            if (!in_array($kPulang, ['tepat', 'cepat'], true)) {
                $kPulang = $keluar !== '' ? absensi_kategori_pulang($keluar, $jadwalHari['pulang']) : '';
            }
            $menit = ($kMasuk === 'telat') ? absensi_menit_terlambat($masuk, $jadwalHari['masuk']) : 0;
            $status = $kMasuk === 'telat' ? 'T' : 'H';

            $sudah = satu('SELECT id FROM absensi_guru WHERE guru_id = ? AND tanggal = ?', [$gid, $tgl]);
            if ($sudah && !$timpa) { $dilewati++; continue; }

            q('INSERT INTO absensi_guru (guru_id, tanggal, status, jam_masuk, jam_keluar, kategori_masuk, kategori_pulang, menit_terlambat, keterangan, dicatat_oleh, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)
               ON CONFLICT(guru_id, tanggal) DO UPDATE SET status = excluded.status, jam_masuk = excluded.jam_masuk,
                 jam_keluar = excluded.jam_keluar, kategori_masuk = excluded.kategori_masuk,
                 kategori_pulang = excluded.kategori_pulang, menit_terlambat = excluded.menit_terlambat,
                 keterangan = excluded.keterangan, dicatat_oleh = excluded.dicatat_oleh',
                [$gid, $tgl, $status, $masuk, $keluar, $kMasuk, $kPulang, $menit, $ket,
                 'Impor PDF (' . (current_user()['nama'] ?? '') . ')', date('Y-m-d H:i:s')]);
            $jumlah++;
            $dicatat[$tgl] = ($dicatat[$tgl] ?? 0) + 1;
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        flash('Impor gagal disimpan: ' . $ex->getMessage(), 'err');
        redirect('absensi_impor.php');
    }
    unset($_SESSION['impor_absensi']);
    log_catat('impor_absensi_pdf', $jumlah . ' baris');
    $pesan = 'Impor selesai: ' . $jumlah . ' rekaman kehadiran disimpan'
        . ($dilewati > 0 ? ', ' . $dilewati . ' dilewati' : '')
        . ($tanpaGuru > 0 ? ', ' . $tanpaGuru . ' tanpa guru yang dipilih' : '') . '.';
    flash($pesan);
    $tanggalAwal = $dicatat ? array_key_first($dicatat) : '';
    if ($tanggalAwal !== '') {
        ksort($dicatat);
        $tanggalAwal = array_key_first($dicatat);
    }
    redirect($tanggalAwal !== '' ? 'absensi_guru.php?tanggal=' . urlencode($tanggalAwal) : 'absensi_impor.php');
}

$GLOBALS['__butuh_xlsx'] = false;
page_top('Impor Kehadiran dari PDF', 'Administrasi kepegawaian · impor laporan absensi bulanan aplikasi lain');
?>
<div class="card">
  <div class="card-kepala">
    <div>
      <h2>Impor Kehadiran Guru dari PDF</h2>
      <p class="card-ket">Unggah <b>PDF laporan absensi bulanan</b> hasil cetak aplikasi absensi lain
        (mis. <span class="mono">ma.stm.sch.id/absensi/…</span>). Jam masuk &amp; pulang tiap guru terbaca
        otomatis, dapat disunting sebelum disimpan, lalu langsung menjadi bagian laporan absensi TUSmart.</p>
    </div>
    <div class="card-aksi">
      <a class="btn btn-garis" href="absensi_guru.php">Kembali ke Absensi</a>
      <a class="btn btn-garis" href="laporan_absensi.php">Laporan Absensi</a>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="saring" style="margin-bottom:0">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="pratinjau">
    <div class="medan cari">
      <label for="berkas">Berkas PDF laporan absensi</label>
      <input type="file" id="berkas" name="berkas" accept=".pdf,application/pdf" required>
      <span class="petunjuk">Maksimal 20 MB. Gunakan PDF asli hasil cetak (bukan hasil pindai/gambar).</span>
    </div>
    <div class="medan">
      <label for="bulan">Bulan laporan (cadangan)</label>
      <input type="month" id="bulan" name="bulan" value="<?= e(date('Y-m')) ?>">
      <span class="petunjuk">Dipakai bila bulan/tahun pada PDF tidak terbaca.</span>
    </div>
    <button class="btn" type="submit"><?= ikon('unggah') ?> Baca PDF</button>
  </form>
</div>

<?php if ($sesi): ?>
<section class="card">
  <div class="card-kepala">
    <div>
      <h2>Pratinjau Impor — <?= e($sesi['bulan']) ?></h2>
      <p class="card-ket">Berkas: <b><?= e($sesi['berkas']) ?></b> · <?= count($sesi['guru']) ?> guru terbaca ·
        waktu baca <?= e(waktu_id($sesi['waktu'])) ?>. Periksa/pilih guru pada tiap kelompok, sunting jam bila
        perlu, lalu simpan.</p>
    </div>
    <div class="card-aksi">
      <form method="post" data-konfirmasi="Batalkan pratinjau impor ini?">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="batal">
        <button class="btn btn-garis" type="submit">Batalkan</button>
      </form>
    </div>
  </div>

  <?php foreach ($sesi['peringatan'] as $p): ?>
    <div class="alert alert-warn"><div><?= e($p) ?></div></div>
  <?php endforeach; ?>
  <?php
  $jumlahBaris = 0;
  $jumlahTanpaGuru = 0;
  foreach ($sesi['guru'] as $gi => $g) {
      $jumlahBaris += count($g['tanggal']);
      if (($sesi['cocok'][$gi] ?? null) === null) { $jumlahTanpaGuru++; }
  }
  ?>
  <div class="tabel-ringkas">
    <span class="chip">Baris kehadiran: <b><?= $jumlahBaris ?></b></span>
    <span class="chip <?= $jumlahTanpaGuru ? 'emas' : 'hijau' ?>">Nama perlu dipilih manual: <b><?= $jumlahTanpaGuru ?></b></span>
    <span class="chip mut">Cocok otomatis: <b><?= count($sesi['guru']) - $jumlahTanpaGuru ?></b></span>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <?php $no = 0; foreach ($sesi['guru'] as $gi => $g): $cocokId = (int) ($sesi['cocok'][$gi] ?? 0); ?>
      <div class="card" style="box-shadow:none;background:#fbfdff;padding:12px">
        <div class="card-kepala" style="margin-bottom:8px">
          <div>
            <b><?= e($g['nama']) ?></b>
            <?php if ($g['jabatan'] !== ''): ?><span class="mut kecil"> · <?= e($g['jabatan']) ?></span><?php endif; ?>
            <span class="chip mut" style="margin-left:6px"><?= count($g['tanggal']) ?> hari</span>
          </div>
          <div class="card-aksi">
            <div class="medan" style="min-width:250px">
              <label for="guru_id_<?= $gi ?>">Guru di TUSmart</label>
              <select id="guru_id_<?= $gi ?>" name="guru_id[<?= $gi ?>]">
                <option value="">— tidak diimpor —</option>
                <?php foreach ($daftarGuru as $dg): ?>
                  <option value="<?= (int) $dg['id'] ?>"<?= (int) $dg['id'] === $cocokId ? ' selected' : '' ?>>
                    <?= e($dg['nama'] . ($dg['jabatan'] ? ' — ' . $dg['jabatan'] : '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="tabel-bungkus">
          <table class="tbl">
            <thead><tr>
              <th class="tengah" style="width:70px">Impor</th>
              <th style="width:130px">Tanggal</th>
              <th style="width:125px">Jam Masuk</th>
              <th class="tengah" style="width:120px">Sumber</th>
              <th style="width:125px">Jam Pulang</th>
              <th class="tengah" style="width:120px">Sumber</th>
              <th>Keterangan</th>
            </tr></thead>
            <tbody>
            <?php foreach ($g['tanggal'] as $tgl => $v): $no++; $hari = nama_hari(); ?>
              <tr>
                <td class="tengah">
                  <input type="checkbox" name="baris[<?= $gi ?>_<?= e($tgl) ?>][pakai]" value="1" checked
                    aria-label="Impor <?= e($g['nama']) ?> <?= e($tgl) ?>">
                  <input type="hidden" name="baris[<?= $gi ?>_<?= e($tgl) ?>][tanggal]" value="<?= e($tgl) ?>">
                  <input type="hidden" name="baris[<?= $gi ?>_<?= e($tgl) ?>][k_masuk]" value="<?= e($v['k_masuk']) ?>">
                  <input type="hidden" name="baris[<?= $gi ?>_<?= e($tgl) ?>][k_pulang]" value="<?= e($v['k_pulang']) ?>">
                </td>
                <td class="nowrap"><?= e(tanggal_id($tgl)) ?>
                  <span class="sel-sub"><?= e($hari[(int) date('N', strtotime($tgl))] ?? '') ?></span></td>
                <td><input type="time" name="baris[<?= $gi ?>_<?= e($tgl) ?>][masuk]" value="<?= e($v['masuk']) ?>"></td>
                <td class="tengah"><span class="pill <?= $v['k_masuk'] === 'telat' ? 'pil-a' : ($v['k_masuk'] === 'tepat' ? 'pil-aktif' : 'pil-nonaktif') ?>"><?= e(absensi_label_kategori($v['k_masuk']) ?: '-') ?></span></td>
                <td><input type="time" name="baris[<?= $gi ?>_<?= e($tgl) ?>][keluar]" value="<?= e($v['keluar']) ?>"></td>
                <td class="tengah"><span class="pill <?= $v['k_pulang'] === 'cepat' ? 'pil-t' : ($v['k_pulang'] === 'tepat' ? 'pil-aktif' : 'pil-nonaktif') ?>"><?= e(absensi_label_kategori($v['k_pulang']) ?: '-') ?></span></td>
                <td><input type="text" name="baris[<?= $gi ?>_<?= e($tgl) ?>][keterangan]" maxlength="200"
                  placeholder="keterangan (opsional)"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="alert alert-info">
      <div><b>Cara kerja penyimpanan:</b> hanya baris yang dicentang yang diimpor. Status kehadiran
        ditetapkan otomatis dari jam masuk (melewati batas hari itu = <b>Terlambat</b>) dan keterangan jam
        pulang mengikuti PDF (<b>Pulang Cepat</b> bila sebelum batas). Setelah tersimpan, rekaman dapat
        diubah lagi lewat perekaman harian dan otomatis masuk semua laporan.</div>
    </div>
    <div class="tombol-baris">
      <label class="centang"><input type="checkbox" name="timpa" value="1" checked>
        Timpa rekaman yang sudah ada pada tanggal yang sama</label>
      <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Hasil Impor</button>
      <a class="btn btn-garis" href="absensi_guru.php">Batal</a>
    </div>
  </form>
</section>
<?php else: ?>
<div class="card">
  <div class="card-kepala"><div><h3 class="mb0">Petunjuk</h3></div></div>
  <ol class="kecil" style="margin:0;padding-left:20px;line-height:1.8">
    <li>Buka aplikasi absensi lain → menu <b>Laporan Absensi Bulan</b> → pilih bulan → cetak/<b>Simpan sebagai PDF</b>.</li>
    <li>Unggah PDF tersebut pada formulir di atas, lalu tekan <b>Baca PDF</b>.</li>
    <li>Periksa pratinjau: pilih guru yang sesuai bila namanya tidak cocok otomatis, sunting jam bila perlu.</li>
    <li>Tekan <b>Simpan Hasil Impor</b> — rekaman muncul pada perekaman harian dan laporan absensi.</li>
  </ol>
</div>
<?php endif; ?>
<?php page_bottom(); ?>
