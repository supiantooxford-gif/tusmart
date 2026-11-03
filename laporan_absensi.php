<?php
/**
 * Laporan Absensi Guru & Tenaga Kependidikan.
 *
 * Tiga bentuk laporan (sesuai permintaan pemilik):
 *   1. Laporan Absen HARI INI / per tanggal — SEMUA guru sekaligus.
 *   2. Laporan PER BULAN — SEMUA guru (matriks tanggal seperti laporan cetak e-absensi).
 *   3. Laporan PER BULAN — PER GURU (rincian harian satu guru).
 * Semua laporan: cetak A4 (PDF) + ekspor Excel & CSV.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$tab = f('tab', 'harian');
if (!in_array($tab, ['harian', 'bulanan', 'guru'], true)) { $tab = 'harian'; }
$tanggal = tanggal_sah(f('tanggal')) ? f('tanggal') : tanggal_hari_ini();
$bulan = preg_match('/^\d{4}-\d{2}$/', f('bulan')) ? f('bulan') : date('Y-m');
$guruId = idf('guru');
$daftarGuru = guru_list(true);
$petaStatus = absensi_status();
$jamKerja = jam_kerja();
$jadwalTanggal = jam_kerja_hari($tanggal);   // jadwal hari laporan
$b = bulan_id();
$labelBulan = $b[(int) substr($bulan, 5, 2)] . ' ' . substr($bulan, 0, 4);

$guru = null;
foreach ($daftarGuru as $g) {
    if ((int) $g['id'] === $guruId) { $guru = $g; }
}
if ($tab === 'guru' && !$guru) {
    $guru = $daftarGuru[0] ?? null;
    $guruId = (int) ($guru['id'] ?? 0);
}

/* ================= DATA ================= */

// --- HARIAN ---
$harian = [];
$harianRingkas = ['direkam' => 0, 'tepat' => 0, 'telat' => 0, 'cepat' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'alpha' => 0, 'belum' => 0];
if ($tab === 'harian') {
    $rekam = [];
    foreach (semua('SELECT * FROM absensi_guru WHERE tanggal = ?', [$tanggal]) as $r) {
        $rekam[(int) $r['guru_id']] = $r;
    }
    foreach ($daftarGuru as $g) {
        $r = $rekam[(int) $g['id']] ?? null;
        $k = $r ? absensi_kategori_baris($r) : ['masuk' => '', 'pulang' => '', 'menit' => 0];
        $st = $r['status'] ?? '';
        $harian[] = [
            'guru' => $g, 'r' => $r, 'k' => $k, 'status' => $st,
        ];
        if (!$r) { $harianRingkas['belum']++; continue; }
        $harianRingkas['direkam']++;
        if ($k['masuk'] === 'telat' || $st === 'T') { $harianRingkas['telat']++; }
        elseif ($st === 'H' || $k['masuk'] === 'tepat') { $harianRingkas['tepat']++; }
        if ($k['pulang'] === 'cepat') { $harianRingkas['cepat']++; }
        // Kode status (I/S/C/A) dipetakan ke kunci ringkasan — dulu memakai strtolower($st)
        // sehingga kuncinya 'i'/'s'/'c'/'a' yang TIDAK ADA: ringkasan Izin/Sakit/Cuti/Alpha
        // selalu 0 dan PHP mengeluarkan "Undefined array key" yang merusak ekspor CSV
        // (header sudah terkirim → CSV gagal terunduh).
        // (nama variabel $petaRingkas — JANGAN $petaStatus: variabel itu sudah dipakai
        //  untuk label status pada tabel di bawah.)
        $petaRingkas = ['I' => 'izin', 'S' => 'sakit', 'C' => 'cuti', 'A' => 'alpha'];
        if (isset($petaRingkas[$st])) { $harianRingkas[$petaRingkas[$st]]++; }
    }
}

// --- BULANAN SEMUA GURU ---
$petaBulan = [];
$rekapBulan = [];
if ($tab === 'bulanan') {
    $petaBulan = absensi_bulan_data($bulan);
    $rekapBulan = absensi_rekap_bulan($bulan, $daftarGuru);
}
$jumlahHari = (int) date('t', strtotime($bulan . '-01'));

// --- PER GURU ---
$rincianGuru = [];
$rekapGuru = null;
if ($tab === 'guru' && $guru) {
    $awal = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $rincianGuru = semua('SELECT * FROM absensi_guru WHERE guru_id = ? AND tanggal BETWEEN ? AND ? ORDER BY tanggal',
        [$guruId, $awal, $akhir]);
    $rekapGuru = absensi_rekap_bulan($bulan, [$guru])[$guruId];
}

/* ================= EKSPOR ================= */
$headerHarian = ['No', 'Tanggal', 'Nama', 'NIP', 'Jabatan', 'Jam Masuk', 'Ket. Masuk', 'Jam Pulang', 'Ket. Pulang', 'Status', 'Keterangan'];
$barisHarian = [];
foreach ($harian as $i => $h) {
    $barisHarian[] = [
        $i + 1, $tanggal, $h['guru']['nama'], $h['guru']['nip'], $h['guru']['jabatan'],
        $h['r']['jam_masuk'] ?? '', absensi_label_kategori($h['k']['masuk']),
        $h['r']['jam_keluar'] ?? '', absensi_label_kategori($h['k']['pulang']),
        $h['r'] ? ($petaStatus[$h['status']]['label'] ?? $h['status']) : 'Belum direkam',
        $h['r']['keterangan'] ?? '',
    ];
}
$headerRekap = ['No', 'Nama', 'NIP', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Cuti', 'Alpha', 'Datang Telat', 'Pulang Cepat', 'Hari Direkam', '% Kehadiran'];
$barisRekap = [];
foreach ($daftarGuru as $i => $g) {
    $r = $rekapBulan[(int) $g['id']] ?? null;
    if (!$r) { continue; }
    $persen = $r['n'] > 0 ? round(($r['H'] + $r['T']) / $r['n'] * 100, 1) : 0;
    $barisRekap[] = [$i + 1, $g['nama'], $g['nip'], $r['H'], $r['T'], $r['I'], $r['S'], $r['C'], $r['A'], $r['telat'], $r['cepat'], $r['n'], $persen];
}
$headerRincian = ['No', 'Tanggal', 'Hari', 'Jam Masuk', 'Ket. Masuk', 'Jam Pulang', 'Ket. Pulang', 'Status', 'Keterangan'];
$barisRincian = [];
foreach ($rincianGuru as $i => $r) {
    $k = absensi_kategori_baris($r);
    $hr = nama_hari();
    $barisRincian[] = [
        $i + 1, $r['tanggal'], $hr[(int) date('N', strtotime($r['tanggal']))], $r['jam_masuk'],
        absensi_label_kategori($k['masuk']), $r['jam_keluar'], absensi_label_kategori($k['pulang']),
        $petaStatus[$r['status']]['label'] ?? $r['status'], $r['keterangan'],
    ];
}

if (f('unduh') === 'csv') {
    if ($tab === 'harian') { csv_kirim('laporan-absensi-' . $tanggal, $headerHarian, $barisHarian); }
    if ($tab === 'bulanan') { csv_kirim('laporan-absensi-bulanan-' . $bulan, $headerRekap, $barisRekap); }
    if ($tab === 'guru') { csv_kirim('laporan-absensi-' . ($guru['nama'] ?? 'guru') . '-' . $bulan, $headerRincian, $barisRincian); }
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Laporan Absensi', 'Laporan harian & bulanan guru/tenaga kependidikan');
?>
<div class="tab">
  <a href="?tab=harian&tanggal=<?= e($tanggal) ?>" class="<?= $tab === 'harian' ? 'on' : '' ?>">Laporan Hari Ini</a>
  <a href="?tab=bulanan&bulan=<?= e($bulan) ?>" class="<?= $tab === 'bulanan' ? 'on' : '' ?>">Per Bulan — Semua Guru</a>
  <a href="?tab=guru&bulan=<?= e($bulan) ?>&guru=<?= (int) $guruId ?>" class="<?= $tab === 'guru' ? 'on' : '' ?>">Per Bulan — Per Guru</a>
</div>

<div class="alert alert-info">
  <div><b>Jam kerja per hari berlaku untuk semua guru:</b>
    <?php if ($tab === 'harian'): ?>
      <?= e($jadwalTanggal['nama']) ?>, <?= e(tanggal_id($tanggal)) ?> → masuk paling lambat
      <b><?= e($jadwalTanggal['masuk']) ?></b> · pulang paling cepat <b><?= e($jadwalTanggal['pulang']) ?></b>
      <?= $jadwalTanggal['libur'] ? '· <b>' . e($jadwalTanggal['keterangan']) . '</b>' : '' ?>.
    <?php else: ?>
      jadwal tiap hari mengikuti pengaturan (mis. Jumat libur di madrasah).
    <?php endif; ?>
    Melewati batas masuk = <b>Telat</b>; pulang sebelum batas = <b>Pulang Cepat</b>.
    <a href="absensi_guru.php#jamkerja">Ubah jam kerja per hari</a>.
  </div>
</div>
<?php if ($tab === 'harian' && $jadwalTanggal['libur']): ?>
<div class="alert alert-warn">
  <div><b>Tanggal ini hari libur</b> — <?= e($jadwalTanggal['keterangan']) ?>. Absensi tidak wajib;
    guru yang tetap hadir boleh direkam.</div>
</div>
<?php endif; ?>

<?php if ($tab === 'harian'): ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Laporan Absen <?= e(tanggal_id($tanggal, true)) ?></h2>
      <p class="card-ket">Semua guru &amp; tenaga kependidikan sekaligus (<?= count($daftarGuru) ?> orang).</p></div>
    <div class="card-aksi">
      <?= tombol_cetak('cetak_absensi_guru.php?tanggal=' . urlencode($tanggal), 'Cetak / PDF') ?>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="laporan-absensi-<?= e($tanggal) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=harian&tanggal=<?= e($tanggal) ?>&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="harian">
    <div class="medan"><label for="tanggal">Tanggal Laporan</label><input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" data-auto></div>
    <div class="medan"><button class="btn btn-garis" type="button" id="btnHariIni">Hari Ini</button></div>
  </form>

  <div class="grid g4" style="margin-bottom:14px">
    <?= kartu('Hadir / Tepat Waktu', (string) $harianRingkas['tepat'], 'Datang sesuai jam kerja', 'cek', 'hijau') ?>
    <?= kartu('Terlambat', (string) $harianRingkas['telat'], 'Melewati batas masuk', 'absen', 'merah') ?>
    <?= kartu('Pulang Cepat', (string) $harianRingkas['cepat'], 'Pulang sebelum batas', 'absen', 'emas') ?>
    <?= kartu('Belum Direkam', (string) $harianRingkas['belum'], 'Dari ' . count($daftarGuru) . ' guru/tendik', 'saring', 'biru') ?>
  </div>
  <div class="tabel-ringkas">
    <span class="chip">Rekaman: <b><?= $harianRingkas['direkam'] ?></b></span>
    <span class="chip hijau">Hadir: <b><?= $harianRingkas['tepat'] ?></b></span>
    <span class="chip merah">Terlambat: <b><?= $harianRingkas['telat'] ?></b></span>
    <span class="chip emas">Pulang cepat: <b><?= $harianRingkas['cepat'] ?></b></span>
    <span class="chip mut">Izin: <b><?= $harianRingkas['izin'] ?></b></span>
    <span class="chip mut">Sakit: <b><?= $harianRingkas['sakit'] ?></b></span>
    <span class="chip mut">Cuti: <b><?= $harianRingkas['cuti'] ?></b></span>
    <span class="chip mut">Alpha: <b><?= $harianRingkas['alpha'] ?></b></span>
  </div>

  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr>
        <th class="tengah" style="width:38px">No</th><th>Nama / NIP</th><th>Jabatan</th>
        <th class="tengah">Jam Masuk</th><th class="tengah">Ket. Masuk</th>
        <th class="tengah">Jam Pulang</th><th class="tengah">Ket. Pulang</th>
        <th class="tengah">Status</th><th>Keterangan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($harian as $i => $h): $r = $h['r']; $k = $h['k']; $st = $h['status']; ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($h['guru']['nama']) ?></b><span class="sel-sub"><?= e($h['guru']['nip'] ?: 'NIP -') ?></span></td>
          <td><?= e($h['guru']['jabatan'] ?: '-') ?></td>
          <td class="tengah mono"><?= e($r['jam_masuk'] ?? '') !== '' ? e(jam_rapi($r['jam_masuk'])) : '-' ?></td>
          <td class="tengah"><?= $k['masuk'] === 'telat'
              ? '<span class="pil pil-a">Telat' . ($k['menit'] > 0 ? ' ' . $k['menit'] . "'" : '') . '</span>'
              : ($k['masuk'] === 'tepat' ? '<span class="pil pil-aktif">Tepat Waktu</span>' : '<span class="mut">-</span>') ?></td>
          <td class="tengah mono"><?= e($r['jam_keluar'] ?? '') !== '' ? e(jam_rapi($r['jam_keluar'])) : '-' ?></td>
          <td class="tengah"><?= $k['pulang'] === 'cepat'
              ? '<span class="pil pil-t">Pulang Cepat</span>'
              : ($k['pulang'] === 'tepat' ? '<span class="pil pil-aktif">Tepat Waktu</span>' : '<span class="mut">-</span>') ?></td>
          <td class="tengah"><?php if ($r): ?><span class="pil pil-<?= strtolower((string) $st) ?>"><?= e($petaStatus[$st]['label'] ?? $st) ?></span><?php else: ?><span class="mut kecil">belum</span><?php endif; ?></td>
          <td><?= e($r['keterangan'] ?? '') ?: '<span class="mut">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftarGuru): ?><tr><td colspan="9" class="kosong">Belum ada data guru.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(function () {
  var b = document.getElementById('btnHariIni');
  if (b) { b.addEventListener('click', function () { var t = new Date(); var s = t.toISOString().slice(0, 10); document.getElementById('tanggal').value = s; document.querySelector('form.saring').submit(); }); }
})();
</script>
<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Laporan Absensi ' . $tanggal, 'sheet' => 'Absensi Harian', 'header' => $headerHarian, 'baris' => $barisHarian], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php elseif ($tab === 'bulanan'): ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Laporan Absen Bulan <?= e($labelBulan) ?> — Semua Guru</h2>
      <p class="card-ket">Matriks tanggal 1–<?= $jumlahHari ?> untuk setiap guru (baris MASUK &amp; PULANG), seperti laporan absensi bulanan.</p></div>
    <div class="card-aksi">
      <?= tombol_cetak('cetak_absensi_guru.php?bulan=' . urlencode($bulan), 'Cetak / PDF') ?>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="laporan-absensi-bulanan-<?= e($bulan) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=bulanan&bulan=<?= e($bulan) ?>&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="bulanan">
    <div class="medan"><label for="bulan">Bulan Laporan</label><input type="month" id="bulan" name="bulan" value="<?= e($bulan) ?>" data-auto></div>
    <div class="medan" style="flex:1"><span class="kecil mut">Keterangan tiap sel: <b>jam</b> + status
      (<span class="pil pil-aktif">Tepat</span> / <span class="pil pil-a">Telat</span> /
      <span class="pil pil-t">Cepat</span>). Tanda &times; = tidak ada rekaman. Sel abu-abu = hari libur
      (mingguan/manual).</span></div>
  </form>

  <div class="tabel-bungkus">
    <table class="tbl matriks-absen">
      <thead>
        <tr>
          <th class="tengah" rowspan="2" style="width:34px">No</th>
          <th rowspan="2" style="min-width:132px">Nama</th>
          <th rowspan="2" style="min-width:88px">Jabatan</th>
          <th class="tengah" rowspan="2" style="width:64px">Status</th>
          <th class="tengah" colspan="<?= $jumlahHari ?>"><?= e($labelBulan) ?></th>
        </tr>
        <tr>
          <?php for ($d = 1; $d <= $jumlahHari; $d++): $tglSel = $bulan . '-' . sprintf('%02d', $d); $liburHari = absensi_libur_tanggal($tglSel); ?>
            <th class="tengah<?= $liburHari ? ' libur' : '' ?>" style="width:26px" title="<?= $liburHari ? 'Libur' : '' ?>"><?= $d ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($daftarGuru as $i => $g): $gid = (int) $g['id']; ?>
        <tr>
          <td class="tengah" rowspan="2"><?= $i + 1 ?></td>
          <td rowspan="2"><b><?= e($g['nama']) ?></b></td>
          <td rowspan="2"><?= e($g['jabatan'] ?: '-') ?></td>
          <td class="tengah kecil">Masuk</td>
          <?php for ($d = 1; $d <= $jumlahHari; $d++): $r = $petaBulan[$gid][$d] ?? null; $k = $r ? absensi_kategori_baris($r) : null; $ts = strtotime($bulan . '-' . sprintf('%02d', $d)); ?>
            <td class="tengah sel-absen<?= absensi_libur_tanggal($bulan . '-' . sprintf('%02d', $d)) ? ' libur' : '' ?><?= $k && $k['masuk'] === 'telat' ? ' telat' : '' ?>">
              <?php if ($r && ($r['jam_masuk'] ?? '') !== ''): ?>
                <b><?= e(jam_rapi($r['jam_masuk'])) ?></b><span class="sel-sub"><?= $k['masuk'] === 'telat' ? 'Telat' : 'Tepat' ?></span>
              <?php else: ?><span class="mut">&times;</span><?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
        <tr>
          <td class="tengah kecil">Pulang</td>
          <?php for ($d = 1; $d <= $jumlahHari; $d++): $r = $petaBulan[$gid][$d] ?? null; $k = $r ? absensi_kategori_baris($r) : null; $ts = strtotime($bulan . '-' . sprintf('%02d', $d)); ?>
            <td class="tengah sel-absen<?= absensi_libur_tanggal($bulan . '-' . sprintf('%02d', $d)) ? ' libur' : '' ?><?= $k && $k['pulang'] === 'cepat' ? ' cepat' : '' ?>">
              <?php if ($r && ($r['jam_keluar'] ?? '') !== ''): ?>
                <b><?= e(jam_rapi($r['jam_keluar'])) ?></b><span class="sel-sub"><?= $k['pulang'] === 'cepat' ? 'Cepat' : 'Tepat' ?></span>
              <?php else: ?><span class="mut">&times;</span><?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftarGuru): ?><tr><td colspan="<?= $jumlahHari + 4 ?>" class="kosong">Belum ada data guru.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <h3 class="mt16">Rekap Jumlah per Guru</h3>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr>
        <th class="tengah" style="width:38px">No</th><th>Nama</th><th class="tengah">Hadir</th><th class="tengah">Terlambat</th>
        <th class="tengah">Izin</th><th class="tengah">Sakit</th><th class="tengah">Cuti</th><th class="tengah">Alpha</th>
        <th class="tengah">Datang Telat</th><th class="tengah">Pulang Cepat</th><th class="tengah">Hari Direkam</th><th class="tengah">%</th>
        <th class="tengah" style="width:96px">Laporan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftarGuru as $i => $g): $r = $rekapBulan[(int) $g['id']]; $persen = $r['n'] > 0 ? round(($r['H'] + $r['T']) / $r['n'] * 100, 1) : 0; ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($g['nama']) ?></b><span class="sel-sub"><?= e($g['nip'] ?: 'NIP -') ?></span></td>
          <td class="tengah"><?= $r['H'] ?></td>
          <td class="tengah"><?= $r['T'] ?></td>
          <td class="tengah"><?= $r['I'] ?></td>
          <td class="tengah"><?= $r['S'] ?></td>
          <td class="tengah"><?= $r['C'] ?></td>
          <td class="tengah"><?= $r['A'] ?></td>
          <td class="tengah"><?= $r['telat'] ?></td>
          <td class="tengah"><?= $r['cepat'] ?></td>
          <td class="tengah"><?= $r['n'] ?></td>
          <td class="tengah"><b><?= e((string) $persen) ?>%</b></td>
          <td class="tengah"><a class="btn btn-garis btn-kecil" href="?tab=guru&bulan=<?= e($bulan) ?>&guru=<?= (int) $g['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Rekap Absensi ' . $labelBulan, 'sheet' => 'Rekap Bulanan', 'header' => $headerRekap, 'baris' => $barisRekap], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php else: ?>
<section class="card">
  <div class="card-kepala">
    <div><h2>Laporan Absen Per Guru — <?= e($labelBulan) ?></h2>
      <p class="card-ket"><?= $guru ? e($guru['nama']) . ' · ' . e($guru['jabatan'] ?: '-') : 'Belum ada guru' ?></p></div>
    <div class="card-aksi">
      <?= $guru ? tombol_cetak('cetak_absensi_guru.php?bulan=' . urlencode($bulan) . '&guru=' . (int) $guruId, 'Cetak / PDF') : '' ?>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="laporan-absen-guru-<?= e($bulan) ?>"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?tab=guru&bulan=<?= e($bulan) ?>&guru=<?= (int) $guruId ?>&unduh=csv"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>
  <form class="saring" method="get">
    <input type="hidden" name="tab" value="guru">
    <div class="medan"><label for="guru">Guru / Tenaga Kependidikan</label>
      <select id="guru" name="guru" data-auto>
        <?php foreach ($daftarGuru as $g): ?>
          <option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === $guruId ? ' selected' : '' ?>><?= e($g['nama'] . ' — ' . ($g['jabatan'] ?: '-')) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="medan"><label for="bulan">Bulan</label><input type="month" id="bulan" name="bulan" value="<?= e($bulan) ?>" data-auto></div>
  </form>

  <?php if ($rekapGuru): $persenG = $rekapGuru['n'] > 0 ? round(($rekapGuru['H'] + $rekapGuru['T']) / $rekapGuru['n'] * 100, 1) : 0; ?>
    <div class="grid g4" style="margin-bottom:14px">
      <?= kartu('Hadir', (string) ($rekapGuru['H'] + $rekapGuru['T']), $persenG . '% kehadiran', 'cek', 'hijau') ?>
      <?= kartu('Terlambat', (string) $rekapGuru['telat'], $rekapGuru['menit'] . ' menit total', 'absen', 'merah') ?>
      <?= kartu('Pulang Cepat', (string) $rekapGuru['cepat'], 'Pulang sebelum batas', 'absen', 'emas') ?>
      <?= kartu('Hari Direkam', (string) $rekapGuru['n'], 'Izin ' . $rekapGuru['I'] . ' · Sakit ' . $rekapGuru['S'] . ' · Cuti ' . $rekapGuru['C'] . ' · Alpha ' . $rekapGuru['A'], 'absen', 'biru') ?>
    </div>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th class="tengah">Tanggal</th><th class="tengah">Hari</th>
          <th class="tengah">Jam Masuk</th><th class="tengah">Ket. Masuk</th>
          <th class="tengah">Jam Pulang</th><th class="tengah">Ket. Pulang</th>
          <th class="tengah">Status</th><th>Keterangan</th>
        </tr></thead>
        <tbody>
        <?php $hr = nama_hari(); foreach ($rincianGuru as $i => $r): $k = absensi_kategori_baris($r); ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah nowrap"><?= e(tanggal_id($r['tanggal'])) ?></td>
            <td class="tengah"><?= e($hr[(int) date('N', strtotime($r['tanggal']))]) ?></td>
            <td class="tengah mono"><?= e($r['jam_masuk'] ?: '-') ?></td>
            <td class="tengah"><?= $k['masuk'] === 'telat'
                ? '<span class="pil pil-a">Telat' . ($k['menit'] > 0 ? ' ' . $k['menit'] . "'" : '') . '</span>'
                : ($k['masuk'] === 'tepat' ? '<span class="pil pil-aktif">Tepat</span>' : '<span class="mut">-</span>') ?></td>
            <td class="tengah mono"><?= e($r['jam_keluar'] ?: '-') ?></td>
            <td class="tengah"><?= $k['pulang'] === 'cepat' ? '<span class="pil pil-t">Pulang Cepat</span>'
                : ($k['pulang'] === 'tepat' ? '<span class="pil pil-aktif">Tepat</span>' : '<span class="mut">-</span>') ?></td>
            <td class="tengah"><span class="pil pil-<?= strtolower((string) $r['status']) ?>"><?= e($petaStatus[$r['status']]['label'] ?? $r['status']) ?></span></td>
            <td><?= e($r['keterangan'] ?: '') ?: '<span class="mut">-</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rincianGuru): ?><tr><td colspan="9" class="kosong">Tidak ada rekaman pada bulan ini.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Laporan Absensi ' . ($guru['nama'] ?? '') . ' ' . $labelBulan, 'sheet' => 'Rincian Guru', 'header' => $headerRincian, 'baris' => $barisRincian], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php page_bottom(); ?>
