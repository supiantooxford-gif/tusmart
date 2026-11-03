<?php
/**
 * Lembar cetak laporan e-PERPUS (A4 landscape) — jenis: pengunjung|buku|peminjaman|pengembalian.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$jenis = f('jenis', 'pengunjung');
if (!perpus_jenis_sah($jenis)) { $jenis = 'pengunjung'; }
$f = perpus_filter();
$L = perpus_laporan($jenis, $f);
$info = perpus_info();

$periode = ($f['dari'] !== '' || $f['sampai'] !== '')
    ? ($f['dari'] !== '' ? tanggal_id($f['dari']) : '…') . ' s.d. ' . ($f['sampai'] !== '' ? tanggal_id($f['sampai']) : '…')
    : 'Seluruh data';
$meta = [
    'Jenis Laporan' => perpus_jenis()[$jenis],
    'Periode' => $periode,
];
foreach ($L['ringkas'] as $label => $nilai) {
    $meta[$label] = (string) $nilai;
}
if ($f['cari'] !== '') { $meta['Kata Kunci'] = $f['cari']; }

cetak_mulai($L['judul'], 'A4 landscape', $meta,
    'Sumber data: aplikasi e-PERPUS' . (!empty($info['nama_perpus']) ? ' — ' . $info['nama_perpus'] : '') . ' (dibaca otomatis).');
?>
<table class="tbl-cetak">
  <thead><tr>
    <?php foreach ($L['kolom'] as $i => $judul): ?>
      <th class="<?= ($L['align'][$i] ?? 'l') === 'c' ? 'tengah' : (($L['align'][$i] ?? 'l') === 'r' ? 'num' : '') ?>"><?= e($judul) ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($L['rows'] as $baris): ?>
    <tr>
      <?php foreach ($baris as $i => $sel): ?>
        <td class="<?= ($L['align'][$i] ?? 'l') === 'c' ? 'tengah' : (($L['align'][$i] ?? 'l') === 'r' ? 'num' : '') ?>"><?= e((string) $sel) ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$L['rows']): ?><tr><td colspan="<?= count($L['kolom']) ?>" class="tengah"><?= e($L['kosong']) ?></td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cetak_selesai([
    ['label' => ['Mengetahui,', 'Kepala ' . istilah_lembaga()], 'nama' => kepala_lembaga(), 'keterangan' => [nip_kepala() !== '' ? 'NIP. ' . nip_kepala() : '']],
    ['label' => ['Kepala Tata Usaha'], 'nama' => current_user()['nama'], 'keterangan' => [''], 'tempat' => true],
]);
