<?php
/**
 * Lembar cetak Laporan Absensi-Guru (A4 melintang) — data dibaca dari aplikasi Absensi Guru.
 *
 * Parameter: jenis (hari_ini|hari|bulan|guru|matriks|overtime|kunjungan), tanggal, bulan, guru, auto=1.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

if (!absguru_ada()) {
    flash('Basis data aplikasi Absensi Guru belum terhubung — laporan tidak dapat dicetak.', 'err');
    redirect('laporan_absensi_guru.php');
}

$jenis = f('jenis', 'hari_ini');
if (!absguru_jenis_sah($jenis)) { $jenis = 'hari_ini'; }
$fl = absguru_filter();
if ((int) $fl['guru'] <= 0 && in_array($jenis, ['guru'], true)) {
    $g = absguru_guru(true);
    $fl['guru'] = (int) ($g[0]['id'] ?? 0);
}

$L = absguru_laporan($jenis, $fl);
$info = absguru_info();

// Tabel untuk dicetak: kolom "Bukti Foto" tidak ikut (foto hanya tampil di layar).
$tabel = [];
foreach ($L['tabel'] as $t) {
    $idx = array_search('Bukti Foto', $t['kolom'], true);
    if ($idx !== false) {
        unset($t['kolom'][$idx]);
        $t['kolom'] = array_values($t['kolom']);
        if (isset($t['align'][$idx])) {
            unset($t['align'][$idx]);
            $t['align'] = array_values($t['align']);
        }
        $baru = [];
        foreach ($t['rows'] as $row) {
            $row = array_values($row);
            unset($row[$idx]);
            $baru[] = array_values($row);
        }
        $t['rows'] = $baru;
        if (!isset($t['pil'])) { $t['pil'] = []; }
    }
    $tabel[] = $t;
}

$kepala = kepala_lembaga();
$nip = nip_kepala();
$catatan = 'Sumber data: aplikasi Absensi Guru'
    . ($info['nama'] !== '' ? ' — ' . $info['nama'] : '')
    . ' (dibaca otomatis, hanya-baca).';

cetak_mulai($L['judul'], 'A4 landscape', $L['meta'], $catatan);
?>
<?php if (($L['kartu'] ?? []) !== []): ?>
  <div class="cetak-ringkas">
    <?php foreach ($L['kartu'] as $k): ?>
      <span><b><?= e((string) $k[0]) ?></b>: <?= e((string) $k[1]) ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($jenis === 'matriks'): ?>
  <?php if (($L['matriks']['guru'] ?? []) === []): ?>
    <p class="ket-cetak"><?= e($L['kosong']) ?></p>
  <?php else: ?>
    <?= absguru_matriks_html($L['matriks']) ?>
  <?php endif; ?>
<?php elseif ($tabel === []): ?>
  <p class="ket-cetak"><?= e($L['kosong']) ?></p>
<?php else: ?>
  <?php foreach ($tabel as $t): ?>
    <?= absguru_tabel_html($t) ?>
  <?php endforeach; ?>
<?php endif; ?>
<?php
$ttd = [[
    'label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()],
    'nama' => $kepala,
    'keterangan' => $nip !== '' ? ['NIP. ' . $nip] : [],
]];
cetak_selesai($ttd);
