<?php
/**
 * Lembar cetak absensi guru & tenaga kependidikan.
 *
 *   ?tanggal=YYYY-MM-DD          → Laporan Absen HARI INI / per tanggal (SEMUA guru, A4 portrait)
 *   ?bulan=YYYY-MM [&guru=ID]    → guru=ID : rincian harian satu guru (A4 portrait)
 *                                  tanpa guru: MATRIKS bulanan semua guru, baris Masuk/Pulang
 *                                  (A4 landscape — bentuk seperti laporan absensi bulanan)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$petaStatus = absensi_status();
$daftarGuru = guru_list(true);
$jamKerja = jam_kerja();
$jadwalTgl = jam_kerja_hari($tanggal !== '' ? $tanggal : tanggal_hari_ini());
$guruId = idf('guru');
$tanggal = tanggal_sah(f('tanggal')) ? f('tanggal') : '';
$bulan = preg_match('/^\d{4}-\d{2}$/', f('bulan')) ? f('bulan') : substr($tanggal !== '' ? $tanggal : tanggal_hari_ini(), 0, 7);
$b = bulan_id();
$labelBulan = $b[(int) substr($bulan, 5, 2)] . ' ' . substr($bulan, 0, 4);
$guru = null;
foreach ($daftarGuru as $g) {
    if ((int) $g['id'] === $guruId) { $guru = $g; }
}
$ttd = [
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
];

/* ===================== MODE 1: LAPORAN HARIAN (SEMUA GURU) ===================== */
if ($tanggal !== '' && !$guru) {
    $rekam = [];
    foreach (semua('SELECT * FROM absensi_guru WHERE tanggal = ?', [$tanggal]) as $r) {
        $rekam[(int) $r['guru_id']] = $r;
    }
    $jml = ['tepat' => 0, 'telat' => 0, 'cepat' => 0, 'I' => 0, 'S' => 0, 'C' => 0, 'A' => 0, 'belum' => 0];
    $harian = [];
    foreach ($daftarGuru as $g) {
        $r = $rekam[(int) $g['id']] ?? null;
        $k = $r ? absensi_kategori_baris($r) : ['masuk' => '', 'pulang' => '', 'menit' => 0];
        $st = $r['status'] ?? '';
        if (!$r) { $jml['belum']++; }
        else {
            if ($k['masuk'] === 'telat' || $st === 'T') { $jml['telat']++; }
            elseif ($st === 'H' || $k['masuk'] === 'tepat') { $jml['tepat']++; }
            if ($k['pulang'] === 'cepat') { $jml['cepat']++; }
            if (in_array($st, ['I', 'S', 'C', 'A'], true)) { $jml[$st]++; }
        }
        $harian[] = ['g' => $g, 'r' => $r, 'k' => $k, 'st' => $st];
    }
    cetak_mulai('Laporan Absensi Guru & Tenaga Kependidikan', 'A4 portrait', [
            'Hari / Tanggal' => tanggal_id($tanggal, true),
            'Jumlah Guru / Tendik' => count($daftarGuru) . ' orang (belum direkam: ' . $jml['belum'] . ')',
            'Jam Kerja' => $jadwalTgl['nama'] . ': masuk paling lambat ' . $jadwalTgl['masuk']
                . ' · pulang paling cepat ' . $jadwalTgl['pulang'] . ($jadwalTgl['libur'] ? ' · HARI LIBUR' : ''),
            'Rekap' => 'Tepat waktu: ' . $jml['tepat'] . ' · Terlambat: ' . $jml['telat'] . ' · Pulang cepat: ' . $jml['cepat'],
            'Izin / Sakit / Cuti / Alpha' => $jml['I'] . ' / ' . $jml['S'] . ' / ' . $jml['C'] . ' / ' . $jml['A'],
        ]);
    ?>
    <table class="tbl-cetak padat absen-harian">
      <thead><tr>
        <th class="tengah" style="width:24px">No</th><th>Nama</th><th class="tengah" style="width:64px">Jabatan</th>
        <th class="tengah" style="width:54px">Masuk</th><th class="tengah" style="width:62px">Ket.</th>
        <th class="tengah" style="width:54px">Pulang</th><th class="tengah" style="width:62px">Ket.</th>
        <th class="tengah" style="width:44px">Status</th><th>Keterangan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($harian as $i => $h): $r = $h['r']; $k = $h['k']; $st = $h['st']; ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><?= e($h['g']['nama']) ?><?= $h['g']['nip'] ? ' <span style="font-size:.6rem">· ' . e($h['g']['nip']) . '</span>' : '' ?></td>
          <td><?= e($h['g']['jabatan'] ?: '-') ?></td>
          <td class="tengah"><?= e($r['jam_masuk'] ?? '') !== '' ? e(jam_rapi($r['jam_masuk'])) : '-' ?></td>
          <td class="tengah"><?= $k['masuk'] === 'telat' ? 'Telat' : ($k['masuk'] === 'tepat' ? 'Tepat Waktu' : '-') ?></td>
          <td class="tengah"><?= e($r['jam_keluar'] ?? '') !== '' ? e(jam_rapi($r['jam_keluar'])) : '-' ?></td>
          <td class="tengah"><?= $k['pulang'] === 'cepat' ? 'Pulang Cepat' : ($k['pulang'] === 'tepat' ? 'Tepat Waktu' : '-') ?></td>
          <td class="tengah"><?= $r ? e($petaStatus[$st]['label'] ?? $st) : '-' ?></td>
          <td><?= e($r['keterangan'] ?? '') ?: '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftarGuru): ?><tr><td colspan="9" class="tengah">Belum ada data guru/tenaga kependidikan.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <p class="ket-cetak mt8">Keterangan: <b>H</b> Hadir · <b>T</b> Terlambat · <b>I</b> Izin · <b>S</b> Sakit ·
      <b>C</b> Cuti · <b>A</b> Alpha. Jam masuk melewati batas <?= e($jamKerja['masuk']) ?> dihitung <b>Terlambat</b>;
      pulang sebelum <?= e($jadwalTgl['pulang']) ?> dihitung <b>Pulang Cepat</b>.
      <?= $jadwalTgl['libur'] ? '<b>' . e($jadwalTgl['keterangan']) . '</b>.' : '' ?></p>
    <?php
    cetak_selesai($ttd);
    exit;
}

/* ===================== MODE 2: RINCIAN SATU GURU ===================== */
if ($guru) {
    $awal = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $baris = semua('SELECT * FROM absensi_guru WHERE guru_id = ? AND tanggal BETWEEN ? AND ? ORDER BY tanggal', [$guruId, $awal, $akhir]);
    $rk = absensi_rekap_bulan($bulan, [$guru])[$guruId];
    $persen = $rk['n'] > 0 ? round(($rk['H'] + $rk['T']) / $rk['n'] * 100, 1) : 0;
    cetak_mulai('Rincian Absensi Guru / Tenaga Kependidikan', 'A4 portrait', [
        'Nama' => $guru['nama'],
        'NIP' => $guru['nip'] ?: '-',
        'Jabatan' => $guru['jabatan'] ?: '-',
        'Bulan' => $labelBulan,
        'Jam Kerja' => 'Jadwal tiap hari (mis. '
            . (jam_kerja_peta()[5]['libur'] ? 'Jumat libur' : 'Jumat masuk') . ')',
        'Rekap' => 'H ' . $rk['H'] . ' · T ' . $rk['T'] . ' · I ' . $rk['I'] . ' · S ' . $rk['S'] . ' · C ' . $rk['C']
            . ' · A ' . $rk['A'] . ' · Datang telat ' . $rk['telat'] . '× (' . $rk['menit'] . ' menit) · Pulang cepat ' . $rk['cepat'] . '×',
        'Kehadiran' => $persen . '% dari ' . $rk['n'] . ' hari direkam',
    ]);
    ?>
    <table class="tbl-cetak">
      <thead><tr>
        <th class="tengah" style="width:24px">No</th><th class="tengah" style="width:78px">Tanggal</th>
        <th class="tengah" style="width:58px">Hari</th><th class="tengah" style="width:56px">Masuk</th>
        <th class="tengah" style="width:66px">Ket.</th><th class="tengah" style="width:56px">Pulang</th>
        <th class="tengah" style="width:66px">Ket.</th><th class="tengah" style="width:46px">Status</th><th>Keterangan</th>
      </tr></thead>
      <tbody>
      <?php $hr = nama_hari(); foreach ($baris as $i => $r): $k = absensi_kategori_baris($r); ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td class="tengah"><?= e(tanggal_id($r['tanggal'])) ?></td>
          <td class="tengah"><?= e($hr[(int) date('N', strtotime($r['tanggal']))]) ?></td>
          <td class="tengah"><?= e($r['jam_masuk'] ?: '-') ?></td>
          <td class="tengah"><?= $k['masuk'] === 'telat' ? 'Telat' : ($k['masuk'] === 'tepat' ? 'Tepat Waktu' : '-') ?></td>
          <td class="tengah"><?= e($r['jam_keluar'] ?: '-') ?></td>
          <td class="tengah"><?= $k['pulang'] === 'cepat' ? 'Pulang Cepat' : ($k['pulang'] === 'tepat' ? 'Tepat Waktu' : '-') ?></td>
          <td class="tengah"><?= e($petaStatus[$r['status']]['label'] ?? $r['status']) ?></td>
          <td><?= e($r['keterangan'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$baris): ?><tr><td colspan="9" class="tengah">Tidak ada rekaman pada bulan ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php
    cetak_selesai($ttd);
    exit;
}

/* ===================== MODE 3: MATRIKS BULANAN SEMUA GURU ===================== */
$peta = absensi_bulan_data($bulan);
$jumlahHari = (int) date('t', strtotime($bulan . '-01'));
$rekap = absensi_rekap_bulan($bulan, $daftarGuru);
$hariSingkat = [1 => 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
$totalTelat = 0;
$totalCepat = 0;
foreach ($rekap as $r) { $totalTelat += $r['telat']; $totalCepat += $r['cepat']; }

cetak_mulai('Laporan Absensi Bulan: ' . $labelBulan, 'A4 landscape', [
    'Jumlah Guru / Tendik' => count($daftarGuru) . ' orang',
    'Jam Kerja' => 'Masuk ≤ ' . $jamKerja['masuk'] . ' · Pulang ≥ ' . $jamKerja['pulang'],
    'Rekap' => 'Datang terlambat ' . $totalTelat . '× · Pulang cepat ' . $totalCepat . '×',
], 'Baris MASUK & PULANG tiap guru. Tanda × = tidak ada rekaman. Hari Minggu diblok abu-abu.');
?>
<style>
@media print {
  @page { size: A4 portrait; margin: 10mm; }
  table.tbl-cetak.absen-harian th, table.tbl-cetak.absen-harian td { padding: 2px 4px; font-size: .66rem; }
  table.tbl-cetak.absen-harian thead th { font-size: .6rem; }
  .kop-gambar { width: 52px; height: 52px; }
  .kop-nama { font-size: 1rem; }
  .kop-baris { font-size: .6rem; line-height: 1.25; }
  .sheet-title { font-size: .86rem; margin: 5px 0 0; }
  .sheet-meta { font-size: .66rem; margin: 3px 0 3px; }
  .sheet-meta td { padding: 0 3px; }
  .sheet-foot { margin-top: 8px; }
  .sheet-sign .ttd-space { height: 34px; }
}
table.matriks-cetak { width: 100%; border-collapse: collapse; font-size: 5.4pt; table-layout: fixed; margin-top: 4px; }
table.matriks-cetak th, table.matriks-cetak td { border: .6px solid #55637a; padding: .6px .4px; text-align: center; vertical-align: middle; overflow: hidden; }
table.matriks-cetak thead th { background: #dce8fa; font-size: 5.2pt; line-height: 1.05; }
table.matriks-cetak thead th.hari { font-weight: 600; }
table.matriks-cetak td.nama, table.matriks-cetak th.nama { text-align: left; padding-left: 2px; font-size: 5.6pt; }
table.matriks-cetak td.jabatan { text-align: left; font-size: 5pt; }
table.matriks-cetak td.status-baris { font-size: 4.8pt; font-weight: 700; background: #f1f6fd; }
table.matriks-cetak td.libur { background: #e9edf3; }
table.matriks-cetak td.telat { background: #fde8e6; }
table.matriks-cetak td.cepat { background: #fff4dd; }
table.matriks-cetak .jam { display: block; font-weight: 700; font-size: 5.4pt; line-height: 1.05; }
table.matriks-cetak .ket { display: block; font-size: 4.1pt; line-height: 1.05; color: #333; }
table.matriks-cetak .kosong { color: #98a3b3; font-size: 5pt; }
@media print {
  @page { size: A4 landscape; margin: 8mm; }
  table.matriks-cetak th, table.matriks-cetak td { padding: .2px .1px; }
  table.matriks-cetak { font-size: 4.9pt; margin-top: 2px; }
  table.matriks-cetak .jam { font-size: 5pt; }
  table.matriks-cetak .ket { font-size: 3.8pt; }
  .lembar-kaki-cetak { margin-top: 3px; font-size: 5.2pt; }
  /* dipadatkan supaya 12+ guru tetap muat satu lembar A4 melintang */
  .kop-gambar { width: 50px; height: 50px; }
  .kop-nama { font-size: 1rem; }
  .kop-baris { font-size: .6rem; line-height: 1.25; }
  .kop-garis { margin: 3px 0 1px; }
  .sheet-title { font-size: .84rem; margin: 5px 0 0; }
  .sheet-meta { font-size: .64rem; margin: 3px 0 3px; }
  .sheet-meta td { padding: 0 3px; }
  .ket-cetak { font-size: .6rem; margin: 0 0 3px; }
  .sheet-foot { margin-top: 6px; }
  .sheet-sign .ttd-space { height: 28px; }
  .ttd-label, .ttd-tempat { font-size: .68rem; line-height: 1.2; }
  .ttd-nama { font-size: .72rem; }
  .ttd-ket { font-size: .66rem; }
  .sheet-cetak-kaki { margin-top: 3px; font-size: .56rem; }
}
</style>

<?php
$kolomNo = 16;
$kolomNama = 82;
$kolomJabatan = 52;
$kolomStatus = 26;
?>
<table class="matriks-cetak">
  <colgroup>
    <col style="width:<?= $kolomNo ?>px"><col style="width:<?= $kolomNama ?>px">
    <col style="width:<?= $kolomJabatan ?>px"><col style="width:<?= $kolomStatus ?>px">
    <?php for ($d = 1; $d <= $jumlahHari; $d++): ?><col><?php endfor; ?>
  </colgroup>
  <thead>
    <tr>
      <th rowspan="2">No</th>
      <th class="nama" rowspan="2">Nama</th>
      <th rowspan="2">Jabatan</th>
      <th rowspan="2">Status</th>
      <th colspan="<?= $jumlahHari ?>"><?= e($labelBulan) ?></th>
    </tr>
    <tr>
      <?php for ($d = 1; $d <= $jumlahHari; $d++): $ts = strtotime($bulan . '-' . sprintf('%02d', $d)); $liburH = absensi_libur_tanggal($bulan . '-' . sprintf('%02d', $d)); ?>
        <th class="hari<?= $liburH ? ' libur' : '' ?>"><?= $d ?><br><span style="font-size:3.8pt"><?= e($hariSingkat[(int) date('N', $ts)]) ?></span></th>
      <?php endfor; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($daftarGuru as $i => $g): $gid = (int) $g['id']; ?>
    <tr>
      <td rowspan="2"><?= $i + 1 ?></td>
      <td class="nama" rowspan="2"><?= e($g['nama']) ?></td>
      <td class="jabatan" rowspan="2"><?= e($g['jabatan'] ?: '-') ?></td>
      <td class="status-baris">Masuk</td>
      <?php for ($d = 1; $d <= $jumlahHari; $d++): $r = $peta[$gid][$d] ?? null; $k = $r ? absensi_kategori_baris($r) : null; $ts = strtotime($bulan . '-' . sprintf('%02d', $d)); ?>
        <td class="<?= absensi_libur_tanggal($bulan . '-' . sprintf('%02d', $d)) ? 'libur ' : '' ?><?= $k && $k['masuk'] === 'telat' ? 'telat' : '' ?>">
          <?php if ($r && ($r['jam_masuk'] ?? '') !== ''): ?>
            <span class="jam"><?= e(jam_rapi($r['jam_masuk'])) ?></span><span class="ket"><?= $k['masuk'] === 'telat' ? 'Telat' : 'Tepat' ?></span>
          <?php else: ?><span class="kosong">&times;</span><?php endif; ?>
        </td>
      <?php endfor; ?>
    </tr>
    <tr>
      <td class="status-baris">Pulang</td>
      <?php for ($d = 1; $d <= $jumlahHari; $d++): $r = $peta[$gid][$d] ?? null; $k = $r ? absensi_kategori_baris($r) : null; $ts = strtotime($bulan . '-' . sprintf('%02d', $d)); ?>
        <td class="<?= absensi_libur_tanggal($bulan . '-' . sprintf('%02d', $d)) ? 'libur ' : '' ?><?= $k && $k['pulang'] === 'cepat' ? 'cepat' : '' ?>">
          <?php if ($r && ($r['jam_keluar'] ?? '') !== ''): ?>
            <span class="jam"><?= e(jam_rapi($r['jam_keluar'])) ?></span><span class="ket"><?= $k['pulang'] === 'cepat' ? 'Cepat' : 'Tepat' ?></span>
          <?php else: ?><span class="kosong">&times;</span><?php endif; ?>
        </td>
      <?php endfor; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$daftarGuru): ?>
    <tr><td colspan="<?= $jumlahHari + 4 ?>" class="tengah">Belum ada data guru/tenaga kependidikan.</td></tr>
  <?php endif; ?>
  <tr>
    <td colspan="4" style="text-align:left;font-weight:700;background:#eef4fd">REKAP</td>
    <?php for ($d = 1; $d <= $jumlahHari; $d++): $msk = 0; $telat = 0;
      foreach ($daftarGuru as $g) {
        $r = $peta[(int) $g['id']][$d] ?? null;
        if ($r && ($r['jam_masuk'] ?? '') !== '') { $msk++; if (absensi_kategori_baris($r)['masuk'] === 'telat') { $telat++; } }
      } ?>
      <td style="background:#eef4fd"><?= $msk > 0 ? $msk : '&times;' ?><?= $telat > 0 ? '<span class="ket">' . $telat . ' telat</span>' : '' ?></td>
    <?php endfor; ?>
  </tr>
  </tbody>
</table>

<div class="lembar-kaki-cetak">
  <b>Keterangan:</b> H = Hadir · T = Terlambat · A = Alpha · I = Izin · C = Cuti · S = Sakit ·
  &times; = tidak ada rekaman. <b>Telat</b> = jam masuk melewati <?= e($jamKerja['masuk']) ?>;
  <b>Cepat</b> = jam pulang sebelum jam yang ditetapkan hari itu.
  Sel <b>abu-abu</b> = hari libur (mingguan sesuai hari, atau hari libur manual).
</div>

<?php
cetak_selesai($ttd);
