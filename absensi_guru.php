<?php
/**
 * Absensi Guru & Tenaga Kependidikan.
 *
 * - Tab "Rekam Harian": input MANUAL jam masuk & jam pulang tiap guru (bisa diisi serentak).
 *   Status dihitung otomatis: jam masuk melewati batas = TERLAMBAT, jam pulang sebelum batas =
 *   PULANG CEPAT. Batas jam berlaku untuk SEMUA guru (kartu "Jam Kerja").
 * - Tab "Rekap Bulanan": matriks status + jumlah terlambat/pulang cepat per guru.
 * Laporan harian & bulanan (per guru / semua guru) ada di menu "Laporan Absensi".
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$tab = f('tab', 'harian') === 'bulanan' ? 'bulanan' : 'harian';
$tanggal = tanggal_sah(f('tanggal')) ? f('tanggal') : tanggal_hari_ini();
$bulan = preg_match('/^\d{4}-\d{2}$/', f('bulan')) ? f('bulan') : date('Y-m');
$daftarGuru = guru_list(true);
$petaStatus = absensi_status();
$jamKerja = jam_kerja();                 // bawaan (dipakai bila hari tidak disetel)
$jadwal = jam_kerja_hari($tanggal);      // jam kerja & keadaan libur untuk tanggal terpilih
$hariKerjaMingguan = jam_kerja_peta();
$hariLiburManual = semua('SELECT * FROM hari_libur ORDER BY tanggal DESC');

// ---------- pengaturan JAM KERJA PER HARI (berlaku untuk semua guru) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'jam_kerja') {
    csrf_wajib();
    $masuk = is_array($_POST['masuk'] ?? null) ? $_POST['masuk'] : [];
    $pulang = is_array($_POST['pulang'] ?? null) ? $_POST['pulang'] : [];
    $libur = is_array($_POST['libur'] ?? null) ? $_POST['libur'] : [];
    $berhasil = 0;
    $gagal = [];
    $nama = nama_hari();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        for ($h = 1; $h <= 7; $h++) {
            if (!array_key_exists($h, $masuk) && !array_key_exists((string) $h, $masuk)) { continue; }
            $m = jam_rapi((string) ($masuk[$h] ?? $masuk[(string) $h] ?? ''));
            $p = jam_rapi((string) ($pulang[$h] ?? $pulang[(string) $h] ?? ''));
            $l = !empty($libur[$h]) || !empty($libur[(string) $h]);
            if (jam_kerja_simpan_hari($h, $m, $p, $l)) { $berhasil++; }
            else { $gagal[] = $nama[$h]; }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        flash('Gagal menyimpan jam kerja: ' . $ex->getMessage(), 'err');
        redirect('absensi_guru.php?tab=' . $tab . '&tanggal=' . urlencode($tanggal));
    }
    log_catat('jam_kerja', $berhasil . ' hari diperbarui');
    flash($berhasil . ' hari jam kerja disimpan (berlaku untuk semua guru).'
        . ($gagal ? ' Gagal: ' . implode(', ', $gagal) . ' — periksa format jam.' : ''));
    redirect('absensi_guru.php?tab=' . $tab . '&tanggal=' . urlencode($tanggal) . '&bulan=' . urlencode($bulan) . '#jamkerja');
}

// ---------- HARI LIBUR MANUAL ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'libur_tambah') {
    csrf_wajib();
    $tglLibur = f('libur_tanggal');
    $ket = f('libur_keterangan');
    if (!tanggal_sah($tglLibur)) {
        flash('Tanggal libur tidak sah.', 'err');
    } elseif (hari_libur_tambah($tglLibur, $ket)) {
        log_catat('hari_libur', $tglLibur . ' ' . $ket);
        flash('Hari libur ' . tanggal_id($tglLibur, true) . ' ditambahkan' . ($ket !== '' ? ' (' . $ket . ')' : '') . '.');
    } else {
        flash('Hari libur gagal disimpan.', 'err');
    }
    redirect('absensi_guru.php?tab=' . $tab . '&tanggal=' . urlencode($tanggal) . '&bulan=' . urlencode($bulan) . '#harilibur');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'libur_hapus') {
    csrf_wajib();
    $idL = idf('id');
    $r = satu('SELECT * FROM hari_libur WHERE id = ?', [$idL]);
    if (!$r) {
        flash('Hari libur tidak ditemukan.', 'err');
    } else {
        hari_libur_hapus($idL);
        log_catat('hapus_hari_libur', (string) $r['tanggal']);
        flash('Hari libur ' . tanggal_id((string) $r['tanggal']) . ' dihapus.');
    }
    redirect('absensi_guru.php?tab=' . $tab . '&tanggal=' . urlencode($tanggal) . '&bulan=' . urlencode($bulan) . '#harilibur');
}

// ---------- simpan rekaman harian ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'simpan') {
    csrf_wajib();
    $tgl = f('tanggal');
    if (!tanggal_sah($tgl)) {
        flash('Tanggal absensi tidak sah.', 'err');
        redirect('absensi_guru.php');
    }
    $status = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
    $masuk = is_array($_POST['jam_masuk'] ?? null) ? $_POST['jam_masuk'] : [];
    $keluar = is_array($_POST['jam_keluar'] ?? null) ? $_POST['jam_keluar'] : [];
    $ket = is_array($_POST['keterangan'] ?? null) ? $_POST['keterangan'] : [];
    $sah = array_keys($petaStatus);
    $jadwalSimpan = jam_kerja_hari($tgl);
    $batasMasuk = $jadwalSimpan['masuk'];
    $batasPulang = $jadwalSimpan['pulang'];
    $jumlah = 0;
    $dihapus = 0;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($daftarGuru as $g) {
            $gid = (int) $g['id'];
            // Hanya guru yang barisnya benar-benar dikirim yang diproses (POST sebagian tidak
            // boleh menghapus rekaman guru lain).
            $dikirim = array_key_exists($gid, $status) || array_key_exists($gid, $masuk)
                || array_key_exists($gid, $keluar) || array_key_exists($gid, $ket);
            if (!$dikirim) { continue; }
            $st = strtoupper(trim((string) ($status[$gid] ?? '')));
            if (!in_array($st, $sah, true)) { $st = ''; }
            $jm = jam_rapi((string) ($masuk[$gid] ?? ''));
            $jk = jam_rapi((string) ($keluar[$gid] ?? ''));
            $teks = substr(trim((string) ($ket[$gid] ?? '')), 0, 200);

            // Status otomatis dari JAM MASUK (H/T), kecuali pengguna memilih I/S/C/A secara manual.
            if ($st === '' || $st === 'H' || $st === 'T') {
                if ($jm !== '') {
                    $st = absensi_kategori_masuk($jm, $batasMasuk) === 'telat' ? 'T' : 'H';
                } elseif ($st === '' ) {
                    $st = '';
                }
            }
            // Tidak ada isian sama sekali -> baris absensi dihapus (memungkinkan pembatalan).
            if ($st === '' && $jm === '' && $jk === '' && $teks === '') {
                $ada = satu('SELECT id FROM absensi_guru WHERE guru_id = ? AND tanggal = ?', [$gid, $tgl]);
                if ($ada) {
                    q('DELETE FROM absensi_guru WHERE id = ?', [(int) $ada['id']]);
                    $dihapus++;
                }
                continue;
            }
            if ($st === '') { $st = 'H'; }

            $kMasuk = absensi_kategori_masuk($jm, $batasMasuk);
            $kPulang = absensi_kategori_pulang($jk, $batasPulang);
            $menit = $kMasuk === 'telat' ? absensi_menit_terlambat($jm, $batasMasuk) : 0;
            // Hari libur: bila jam kosong tetapi status diisi, jam kerja hari itu tidak dipakai
            // untuk menilai keterlambatan (rekaman hanya mencatat status/kehadiran sukarela).

            q('INSERT INTO absensi_guru (guru_id, tanggal, status, jam_masuk, jam_keluar, kategori_masuk, kategori_pulang, menit_terlambat, keterangan, dicatat_oleh, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)
               ON CONFLICT(guru_id, tanggal) DO UPDATE SET status = excluded.status, jam_masuk = excluded.jam_masuk,
                 jam_keluar = excluded.jam_keluar, kategori_masuk = excluded.kategori_masuk,
                 kategori_pulang = excluded.kategori_pulang, menit_terlambat = excluded.menit_terlambat,
                 keterangan = excluded.keterangan, dicatat_oleh = excluded.dicatat_oleh',
                [$gid, $tgl, $st, $jm, $jk, $kMasuk, $kPulang, $menit, $teks, current_user()['nama'], date('Y-m-d H:i:s')]);
            $jumlah++;
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        flash('Gagal menyimpan absensi: ' . $ex->getMessage(), 'err');
        redirect('absensi_guru.php?tanggal=' . urlencode($tgl));
    }
    log_catat('absensi_guru', $tgl . ' (' . $jumlah . ' baris)');
    flash('Absensi ' . tanggal_id($tgl, true) . ' disimpan (' . $jumlah . ' guru/tenaga kependidikan'
        . ($dihapus > 0 ? ', ' . $dihapus . ' baris kosong dibersihkan' : '') . ').'
        . ' Status & keterangan jam dihitung otomatis (jadwal ' . $jadwalSimpan['nama'] . ': masuk '
        . $jadwalSimpan['masuk'] . ', pulang ' . $jadwalSimpan['pulang'] . ').');
    redirect('absensi_guru.php?tab=' . $tab . '&tanggal=' . urlencode($tgl) . '&bulan=' . urlencode(substr($tgl, 0, 7)));
}

// ---------- data rekaman tanggal terpilih ----------
$rekam = [];
foreach (semua('SELECT * FROM absensi_guru WHERE tanggal = ?', [$tanggal]) as $r) {
    $rekam[(int) $r['guru_id']] = $r;
}
$ringkasHari = absensi_hari_ini_ringkas($tanggal);

// ---------- rekap bulanan ----------
$awal = $bulan . '-01';
$akhir = date('Y-m-t', strtotime($awal));
$hariKerja = 0;
for ($d = 1; $d <= (int) date('t', strtotime($awal)); $d++) {
    $ts = strtotime($bulan . '-' . sprintf('%02d', $d));
    if ((int) date('N', $ts) <= 6) { $hariKerja++; } // Senin–Sabtu
}
$rekap = absensi_rekap_bulan($bulan, $daftarGuru);
$totalHariRekam = (int) kolom('SELECT COUNT(DISTINCT tanggal) FROM absensi_guru WHERE tanggal BETWEEN ? AND ?', [$awal, $akhir]);

// ---------- ekspor CSV ----------
if (f('unduh') === 'csv') {
    if ($tab === 'bulanan') {
        $baris = [];
        foreach ($daftarGuru as $g) {
            $r = $rekap[(int) $g['id']];
            $persen = $r['n'] > 0 ? round(($r['H'] + $r['T']) / $r['n'] * 100, 1) : 0;
            $baris[] = [$g['nama'], $g['nip'], $r['H'], $r['T'], $r['I'], $r['S'], $r['C'], $r['A'], $r['telat'], $r['cepat'], $r['n'], $persen];
        }
        csv_kirim('rekap-absensi-' . $bulan, ['Nama', 'NIP', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Cuti', 'Alpha', 'Datang Telat', 'Pulang Cepat', 'Hari Direkam', 'Persentase Kehadiran'], $baris);
    }
    $baris = [];
    $no = 1;
    foreach ($daftarGuru as $g) {
        $r = $rekam[(int) $g['id']] ?? null;
        if (!$r) { continue; }
        $k = absensi_kategori_baris($r);
        $baris[] = [$no++, $tanggal, $g['nama'], $g['nip'], $r['jam_masuk'], absensi_label_kategori($k['masuk']),
            $r['jam_keluar'], absensi_label_kategori($k['pulang']), $petaStatus[$r['status']]['label'] ?? '', $r['keterangan']];
    }
    csv_kirim('absensi-' . $tanggal, ['No', 'Tanggal', 'Nama', 'NIP', 'Jam Masuk', 'Ket. Masuk', 'Jam Pulang', 'Ket. Pulang', 'Status', 'Keterangan'], $baris);
}

// ---------- data ekspor Excel ----------
$dataHarian = [];
$no = 1;
foreach ($daftarGuru as $g) {
    $r = $rekam[(int) $g['id']] ?? null;
    if (!$r) { continue; }
    $k = absensi_kategori_baris($r);
    $dataHarian[] = [$no++, $tanggal, $g['nama'], $g['nip'], $g['jabatan'], $r['jam_masuk'],
        absensi_label_kategori($k['masuk']), $r['jam_keluar'], absensi_label_kategori($k['pulang']),
        $petaStatus[$r['status']]['label'] ?? $r['status'], $r['keterangan']];
}
$dataBulanan = [];
foreach ($daftarGuru as $g) {
    $r = $rekap[(int) $g['id']];
    $persen = $r['n'] > 0 ? round(($r['H'] + $r['T']) / $r['n'] * 100, 1) : 0;
    $dataBulanan[] = [$g['nama'], $g['nip'], $r['H'], $r['T'], $r['I'], $r['S'], $r['C'], $r['A'], $r['telat'], $r['cepat'], $r['n'], $persen];
}
$GLOBALS['__butuh_xlsx'] = true;
page_top('Absensi Guru & Tendik', 'Administrasi kepegawaian · jam masuk & pulang diinput manual');
?>
<div class="tab">
  <a href="?tab=harian&tanggal=<?= e($tanggal) ?>" class="<?= $tab === 'harian' ? 'on' : '' ?>">Rekam Harian</a>
  <a href="?tab=bulanan&bulan=<?= e($bulan) ?>" class="<?= $tab === 'bulanan' ? 'on' : '' ?>">Rekap Bulanan</a>
  <a href="laporan_absensi.php">Laporan Absensi</a>
</div>

<section class="card">
  <div class="card-kepala">
    <div>
      <h2 id="jamkerja">Jam Kerja per Hari (berlaku untuk semua guru)</h2>
      <p class="card-ket">Setiap hari boleh berbeda jam masuk &amp; pulang. Jam masuk yang <b>melewati</b> batas
        dianggap <b>TERLAMBAT</b>; jam pulang <b>sebelum</b> batas dianggap <b>PULANG CEPAT</b>.
        Centang <b>Libur</b> untuk hari libur mingguan (madrasah umumnya <b>Jumat</b>).</p>
    </div>
  </div>
  <form method="post" style="margin-bottom:0">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="jam_kerja">
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th style="min-width:110px">Hari</th>
          <th style="width:150px">Jam Masuk</th>
          <th style="width:150px">Jam Pulang</th>
          <th class="tengah" style="width:120px">Libur Mingguan</th>
          <th>Keterangan</th>
        </tr></thead>
        <tbody>
        <?php
        // Urutan tampil mengikuti kebiasaan madrasah: Sabtu, Minggu, Senin … Jumat.
        $urutanHari = [6, 7, 1, 2, 3, 4, 5];
        $namaHari = nama_hari();
        foreach ($urutanHari as $h):
            $j = $hariKerjaMingguan[$h];
        ?>
          <tr>
            <td><b><?= e($namaHari[$h]) ?></b></td>
            <td><input type="time" name="masuk[<?= $h ?>]" value="<?= e($j['masuk']) ?>" aria-label="Jam masuk <?= e($namaHari[$h]) ?>" required></td>
            <td><input type="time" name="pulang[<?= $h ?>]" value="<?= e($j['pulang']) ?>" aria-label="Jam pulang <?= e($namaHari[$h]) ?>" required></td>
            <td class="tengah"><label class="centang" style="justify-content:center">
              <input type="checkbox" name="libur[<?= $h ?>]" value="1"<?= (int) $j['libur'] === 1 ? ' checked' : '' ?>
                aria-label="Libur mingguan <?= e($namaHari[$h]) ?>"> Libur</label></td>
            <td class="kecil mut">
              <?php if ((int) $j['libur'] === 1): ?>Hari libur mingguan — absensi tidak wajib.
              <?php elseif ($h === 5): ?>Madrasah umumnya libur hari Jumat; centang bila berlaku.
              <?php else: ?>Masuk ≤ <?= e($j['masuk']) ?> · pulang ≥ <?= e($j['pulang']) ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="tombol-baris mt12">
      <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Jam Kerja</button>
      <span class="kecil mut">Jadwal berlaku untuk <b>semua</b> guru/tenaga kependidikan dan dipakai pada laporan.</span>
    </div>
  </form>
</section>

<section class="card" id="harilibur">
  <div class="card-kepala">
    <div><h2>Hari Libur Manual</h2>
      <p class="card-ket">Tambahkan tanggal libur tertentu (libur nasional, libur semester, kegiatan madrasah).
        Pada tanggal libur, absensi <b>tidak wajib</b> dan laporan menandainya sebagai libur.</p></div>
  </div>
  <form method="post" class="saring" style="margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="libur_tambah">
    <div class="medan"><label for="libur_tanggal">Tanggal Libur</label>
      <input type="date" id="libur_tanggal" name="libur_tanggal" value="<?= e(tanggal_hari_ini()) ?>" required></div>
    <div class="medan cari"><label for="libur_keterangan">Keterangan</label>
      <input type="text" id="libur_keterangan" name="libur_keterangan" maxlength="150" placeholder="mis. Libur Idul Fitri / Libur semester"></div>
    <button class="btn" type="submit"><?= ikon('tambah') ?> Tambah Hari Libur</button>
  </form>
  <?php if (!$hariLiburManual): ?>
    <div class="kosong">Belum ada hari libur manual. Libur mingguan diatur pada tabel <b>Jam Kerja per Hari</b> di atas.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr><th class="tengah" style="width:40px">No</th><th>Tanggal</th><th class="tengah">Hari</th>
          <th>Keterangan</th><th>Ditambahkan Oleh</th><th class="tengah" style="width:80px">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($hariLiburManual as $i => $hl): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="nowrap"><?= e(tanggal_id((string) $hl['tanggal'])) ?></td>
            <td class="tengah"><?= e($namaHari[(int) date('N', strtotime((string) $hl['tanggal']))] ?? '-') ?></td>
            <td><?= e($hl['keterangan'] ?: '-') ?></td>
            <td><?= e($hl['oleh'] ?: '-') ?></td>
            <td class="tengah">
              <form method="post" data-konfirmasi="Hapus hari libur <?= e(tanggal_id((string) $hl['tanggal'])) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="libur_hapus"><input type="hidden" name="id" value="<?= (int) $hl['id'] ?>">
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

<?php if ($tab === 'harian'): ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Perekaman Kehadiran Harian</h2>
      <p class="card-ket">Isi <b>Jam Masuk</b> dan <b>Jam Pulang</b> secara manual (bisa sekaligus untuk semua guru),
        atau pilih status Izin/Sakit/Cuti/Alpha. Menyimpan ulang tanggal yang sama akan memperbarui datanya.</p></div>
    <div class="card-aksi">
      <a class="btn" href="absensi_impor.php"><?= ikon('unggah') ?> Impor Kehadiran (PDF)</a>
      <a class="btn btn-garis" href="laporan_absensi.php"><?= ikon('cetak') ?> Laporan Absensi</a>
      <?= tombol_cetak('cetak_absensi_guru.php?tanggal=' . urlencode($tanggal), 'Cetak Hari Ini') ?>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEksporHarian" data-nama="absensi-<?= e($tanggal) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=harian&tanggal=<?= e($tanggal) ?>&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>

  <?php if ($jadwal['libur']): ?>
    <div class="alert alert-warn">
      <div><b><?= e(tanggal_id($tanggal, true)) ?> adalah hari libur</b> — <?= e($jadwal['keterangan']) ?>.
        Absensi tidak wajib direkam; rekaman hanya diperlukan bila ada kegiatan.</div>
    </div>
  <?php endif; ?>
  <div class="alert alert-info">
    <div><b>Jadwal <?= e($jadwal['nama']) ?>:</b> jam masuk paling lambat <b><?= e($jadwal['masuk']) ?></b>,
      jam pulang paling cepat <b><?= e($jadwal['pulang']) ?></b>
      <?= $jadwal['libur'] ? '· <b>Hari libur</b>' : '' ?>.
      <a href="#jamkerja">Ubah jadwal per hari</a></div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="harian">
    <div class="medan">
      <label for="tanggal">Tanggal Absensi</label>
      <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" data-auto>
    </div>
    <div class="medan" style="flex:1">
      <span class="kecil mut">Rekaman tersimpan: <b><?= count($rekam) ?></b> dari <?= count($daftarGuru) ?> guru/tenaga kependidikan
        · <?= e(tanggal_id($tanggal, true)) ?> · Hadir <b><?= $ringkasHari['hadir'] ?></b>,
        terlambat <b><?= $ringkasHari['telat'] ?></b>, pulang cepat <b><?= $ringkasHari['cepat'] ?></b>.</span>
    </div>
    <div class="medan">
      <a class="btn btn-garis" href="absensi_impor.php" title="Impor kehadiran dari PDF laporan absensi aplikasi lain">
        <?= ikon('unggah') ?> Impor dari PDF</a>
    </div>
  </form>

  <?php if (!$daftarGuru): ?>
    <div class="kosong">Belum ada data guru. <a href="guru_edit.php">Tambah data</a> terlebih dahulu.</div>
  <?php else: ?>
  <form method="post" id="formAbsen">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">

    <div class="saring" style="align-items:flex-end">
      <div class="medan"><label for="isiMasuk">Jam masuk untuk semua</label>
        <input type="time" id="isiMasuk" value="<?= e($jadwal['masuk']) ?>"></div>
      <div class="medan"><label for="isiPulang">Jam pulang untuk semua</label>
        <input type="time" id="isiPulang" value="<?= e($jadwal['pulang']) ?>"></div>
      <button class="btn btn-garis" type="button" id="btnIsiSemua"><?= ikon('unduh') ?> Terapkan ke semua guru</button>
      <button class="btn btn-garis" type="button" id="btnSemuaHadir"><?= ikon('cek') ?> Tandai Semua Hadir</button>
      <div class="medan" style="flex:1">
        <span class="kecil mut">Isian cepat: mengisi kedua kolom jam pada semua baris, lalu bisa diubah per guru sebelum disimpan.</span>
      </div>
    </div>

    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:42px">No</th><th>Nama / NIP</th><th>Jabatan</th>
          <th style="width:150px">Status</th>
          <th style="width:118px">Jam Masuk</th><th class="tengah" style="width:96px">Ket. Masuk</th>
          <th style="width:118px">Jam Pulang</th><th class="tengah" style="width:96px">Ket. Pulang</th>
          <th>Keterangan</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftarGuru as $i => $g): $r = $rekam[(int) $g['id']] ?? null; $gid = (int) $g['id']; $k = $r ? absensi_kategori_baris($r) : ['masuk' => '', 'pulang' => '', 'menit' => 0]; ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><b><?= e($g['nama']) ?></b><span class="sel-sub"><?= e($g['nip'] ?: 'NIP -') ?></span></td>
            <td><?= e($g['jabatan'] ?: '-') ?></td>
            <td>
              <select name="status[<?= $gid ?>]" aria-label="Status <?= e($g['nama']) ?>">
                <option value="">— otomatis dari jam —</option>
                <?php foreach ($petaStatus as $kode => $info): ?>
                  <option value="<?= e($kode) ?>"<?= ($r['status'] ?? '') === $kode ? ' selected' : '' ?>><?= e($kode . ' · ' . $info['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="time" name="jam_masuk[<?= $gid ?>]" value="<?= e(jam_rapi($r['jam_masuk'] ?? '')) ?>" aria-label="Jam masuk <?= e($g['nama']) ?>"></td>
            <td class="tengah" data-ket-masuk="<?= $gid ?>"><?= $k['masuk'] === 'telat'
                ? '<span class="pil pil-a">Telat' . ($k['menit'] > 0 ? ' ' . $k['menit'] . "'" : '') . '</span>'
                : ($k['masuk'] === 'tepat' ? '<span class="pil pil-aktif">Tepat</span>' : '<span class="mut kecil">-</span>') ?></td>
            <td><input type="time" name="jam_keluar[<?= $gid ?>]" value="<?= e(jam_rapi($r['jam_keluar'] ?? '')) ?>" aria-label="Jam pulang <?= e($g['nama']) ?>"></td>
            <td class="tengah" data-ket-pulang="<?= $gid ?>"><?= $k['pulang'] === 'cepat'
                ? '<span class="pil pil-t">Pulang Cepat</span>'
                : ($k['pulang'] === 'tepat' ? '<span class="pil pil-aktif">Tepat</span>' : '<span class="mut kecil">-</span>') ?></td>
            <td><input type="text" name="keterangan[<?= $gid ?>]" value="<?= e($r['keterangan'] ?? '') ?>" maxlength="200" placeholder="mis. sakit dengan surat dokter" aria-label="Keterangan <?= e($g['nama']) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="kecil mut mt8">Kode status: <b>H</b> Hadir · <b>T</b> Terlambat · <b>I</b> Izin · <b>S</b> Sakit ·
      <b>C</b> Cuti · <b>A</b> Alpha. Status H/T ditetapkan otomatis dari jam masuk; kosongkan semua isian
      untuk menghapus rekaman hari itu.</p>
    <div class="tombol-baris mt12"><button class="btn" type="submit"><?= ikon('cek') ?> Simpan Absensi</button></div>
  </form>
  <?php endif; ?>
</section>
<script>
(function () {
  var batasMasuk = '<?= e($jadwal['masuk']) ?>';
  var batasPulang = '<?= e($jadwal['pulang']) ?>';
  function menit(v) { if (!v) { return null; } var p = v.split(':'); return (+p[0]) * 60 + (+p[1]); }
  function labelMasuk(inp, sel) {
    var m = menit(inp.value), b = menit(batasMasuk);
    if (m === null) { sel.innerHTML = '<span class="mut kecil">-</span>'; return; }
    if (m > b) { sel.innerHTML = '<span class="pil pil-a">Telat ' + (m - b) + "'</span>"; }
    else { sel.innerHTML = '<span class="pil pil-aktif">Tepat</span>'; }
  }
  function labelPulang(inp, sel) {
    var m = menit(inp.value), b = menit(batasPulang);
    if (m === null) { sel.innerHTML = '<span class="mut kecil">-</span>'; return; }
    if (m < b) { sel.innerHTML = '<span class="pil pil-t">Pulang Cepat</span>'; }
    else { sel.innerHTML = '<span class="pil pil-aktif">Tepat</span>'; }
  }
  document.querySelectorAll('#formAbsen input[name^="jam_masuk["]').forEach(function (inp) {
    var gid = inp.name.replace(/\D+/g, '');
    var sel = document.querySelector('[data-ket-masuk="' + gid + '"]');
    if (!sel) { return; }
    inp.addEventListener('input', function () { labelMasuk(inp, sel); });
    inp.addEventListener('change', function () { labelMasuk(inp, sel); });
  });
  document.querySelectorAll('#formAbsen input[name^="jam_keluar["]').forEach(function (inp) {
    var gid = inp.name.replace(/\D+/g, '');
    var sel = document.querySelector('[data-ket-pulang="' + gid + '"]');
    if (!sel) { return; }
    inp.addEventListener('input', function () { labelPulang(inp, sel); });
    inp.addEventListener('change', function () { labelPulang(inp, sel); });
  });
  var isi = document.getElementById('btnIsiSemua');
  if (isi) {
    isi.addEventListener('click', function () {
      var m = document.getElementById('isiMasuk').value;
      var p = document.getElementById('isiPulang').value;
      document.querySelectorAll('#formAbsen input[name^="jam_masuk["]').forEach(function (inp) {
        if (m) { inp.value = m; inp.dispatchEvent(new Event('change')); }
      });
      document.querySelectorAll('#formAbsen input[name^="jam_keluar["]').forEach(function (inp) {
        if (p) { inp.value = p; inp.dispatchEvent(new Event('change')); }
      });
    });
  }
  var b = document.getElementById('btnSemuaHadir');
  if (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#formAbsen select[name^="status["]').forEach(function (s) {
        if (!s.value || s.value === 'H' || s.value === 'T') { s.value = 'H'; }
      });
    });
  }
})();
</script>

<?php else: ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Rekap Absensi Bulanan</h2>
      <p class="card-ket"><?= e($bulan) ?> · <?= $totalHariRekam ?> hari terekam · <?= $hariKerja ?> hari efektif (Senin–Sabtu).
        Jam kerja bulan ini: <?= e($bulan) ?> — jadwal tiap hari mengikuti pengaturan <a href="#jamkerja">Jam Kerja per Hari</a>.</p></div>
    <div class="card-aksi">
      <a class="btn btn-garis" href="laporan_absensi.php?tab=bulanan&bulan=<?= e($bulan) ?>"><?= ikon('cetak') ?> Laporan Bulanan</a>
      <?= tombol_cetak('cetak_absensi_guru.php?bulan=' . urlencode($bulan), 'Cetak Matriks') ?>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEksporBulanan" data-nama="rekap-absensi-<?= e($bulan) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=bulanan&bulan=<?= e($bulan) ?>&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="bulanan">
    <div class="medan"><label for="bulan">Bulan</label><input type="month" id="bulan" name="bulan" value="<?= e($bulan) ?>" data-auto></div>
  </form>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr>
        <th class="tengah" style="width:42px">No</th><th>Nama / NIP</th>
        <?php foreach ($petaStatus as $kode => $info): ?><th class="tengah" title="<?= e($info['label']) ?>"><?= e($kode) ?></th><?php endforeach; ?>
        <th class="tengah" title="Datang terlambat">Telat</th>
        <th class="tengah" title="Pulang sebelum jam kerja">P. Cepat</th>
        <th class="tengah">Hari Direkam</th><th class="tengah">% Kehadiran</th>
        <th class="tengah" style="width:104px">Laporan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftarGuru as $i => $g): $r = $rekap[(int) $g['id']]; $persen = $r['n'] > 0 ? round(($r['H'] + $r['T']) / $r['n'] * 100, 1) : 0; ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($g['nama']) ?></b><span class="sel-sub"><?= e($g['nip'] ?: 'NIP -') ?></span></td>
          <?php foreach (array_keys($petaStatus) as $kode): ?>
            <td class="tengah"><?= $r[$kode] > 0 ? '<span class="pil pil-' . strtolower($kode) . '">' . $r[$kode] . '</span>' : '<span class="mut">-</span>' ?></td>
          <?php endforeach; ?>
          <td class="tengah"><?= $r['telat'] > 0 ? '<span class="pil pil-a">' . $r['telat'] . '</span>' : '<span class="mut">-</span>' ?></td>
          <td class="tengah"><?= $r['cepat'] > 0 ? '<span class="pil pil-t">' . $r['cepat'] . '</span>' : '<span class="mut">-</span>' ?></td>
          <td class="tengah"><?= $r['n'] ?></td>
          <td class="tengah"><b><?= e((string) $persen) ?>%</b></td>
          <td class="tengah"><a class="btn btn-garis btn-kecil" href="laporan_absensi.php?tab=guru&bulan=<?= e($bulan) ?>&guru=<?= (int) $g['id'] ?>"><?= ikon('cetak') ?> Per Guru</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script type="application/json" id="dataEksporHarian"><?= json_encode(['judul' => 'Absensi ' . $tanggal, 'sheet' => 'Absensi Harian', 'header' => ['No', 'Tanggal', 'Nama', 'NIP', 'Jabatan', 'Jam Masuk', 'Ket. Masuk', 'Jam Pulang', 'Ket. Pulang', 'Status', 'Keterangan'], 'baris' => $dataHarian], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="dataEksporBulanan"><?= json_encode(['judul' => 'Rekap Absensi ' . $bulan, 'sheet' => 'Rekap Bulanan', 'header' => ['Nama', 'NIP', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Cuti', 'Alpha', 'Datang Telat', 'Pulang Cepat', 'Hari Direkam', 'Persentase Kehadiran'], 'baris' => $dataBulanan], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
