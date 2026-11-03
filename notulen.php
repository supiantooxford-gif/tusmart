<?php
/**
 * Notulen Rapat — berita acara rapat beserta daftar hadir, foto bukti kegiatan, dan lembar cetak.
 *
 * Fiturnya DISAMAKAN dengan modul Notulen Rapat aplikasi e-GURU:
 *  - daftar berbentuk tabel + kartu ringkasan (notulen tersimpan, bulan ini, guru aktif, baris tampil);
 *  - saringan cari + rentang tanggal;
 *  - daftar hadir dipilih per guru (centang), bukan diketik manual;
 *  - foto bukti kegiatan (maksimal NOTULEN_FOTO_MAKS = 2 per notulen);
 *  - empat lembar cetak: Berita Acara, Daftar Hadir, Isi Notulen, dan Foto Dokumentasi;
 *  - v1.7.0: TANDA TANGAN DIGITAL (layar sentuh) pada lembar Daftar Hadir — peserta, Kepala,
 *    Pimpinan Rapat, dan Notulis (lihat inc/notulen_ttd.php + upload_notulen_ttd.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'hapus') {
    csrf_wajib();
    $id = idf('id');
    $n = notulen_by_id($id);
    if (!$n) {
        flash('Notulen tidak ditemukan.', 'err');
    } else {
        $peserta = notulen_jumlah_hadir($id);
        q('DELETE FROM notulen WHERE id = ?', [$id]);
        log_catat('hapus_notulen', (string) $n['judul']);
        flash('Notulen rapat “' . $n['judul'] . '” beserta ' . $peserta . ' baris daftar hadir dihapus.', 'warning');
    }
    redirect('notulen.php');
}

// ---------------- saringan ----------------
$cari = f('cari');
$fDari = tanggal_sah(f('dari')) ? f('dari') : '';
$fSampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
if ($fDari !== '' && $fSampai !== '' && $fDari > $fSampai) {
    [$fDari, $fSampai] = [$fSampai, $fDari];
}
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(judul LIKE ? OR tempat LIKE ? OR agenda LIKE ? OR pembahasan LIKE ? OR keputusan LIKE ? OR pimpinan LIKE ? OR notulis LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k, $k);
}
if ($fDari !== '') { $w[] = 'tanggal >= ?'; $arg[] = $fDari; }
if ($fSampai !== '') { $w[] = 'tanggal <= ?'; $arg[] = $fSampai; }
$rows = semua('SELECT n.*,
        (SELECT COUNT(*) FROM notulen_hadir h WHERE h.notulen_id = n.id) AS jml_hadir,
        (SELECT COUNT(*) FROM notulen_foto f WHERE f.notulen_id = n.id) AS jml_foto,
        (SELECT COUNT(*) FROM notulen_ttd t WHERE t.notulen_id = n.id) AS jml_ttd
    FROM notulen n' . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
    . ' ORDER BY n.tanggal DESC, n.id DESC LIMIT 300', $arg);

// Ringkasan dari SELURUH notulen (bukan hanya baris berfilter) — seperti e-GURU.
$totalNotulen = (int) kolom('SELECT COUNT(*) FROM notulen');
$bulanIni = (int) kolom('SELECT COUNT(*) FROM notulen WHERE substr(tanggal,1,7) = ?', [date('Y-m')]);
$guruAktif = (int) kolom('SELECT COUNT(*) FROM guru WHERE aktif = 1');
$qCetak = http_build_query(array_filter(['cari' => $cari, 'dari' => $fDari, 'sampai' => $fSampai]));

$GLOBALS['__butuh_xlsx'] = true;
page_top('Notulen Rapat', 'Administrasi rapat · berita acara, daftar hadir, isi notulen, foto dokumentasi');
?>
<div class="grid g4">
  <?= kartu('Notulen Tersimpan', (string) $totalNotulen, 'Seluruh notulen rapat', 'notulen', 'biru') ?>
  <?= kartu('Bulan Ini', (string) $bulanIni, bulan_id()[(int) date('n')] . ' ' . date('Y'), 'absen', 'hijau') ?>
  <?= kartu('Guru Aktif (Peserta)', (string) $guruAktif, 'Dapat dipilih pada daftar hadir', 'guru', 'emas') ?>
  <?= kartu('Baris Ditampilkan', (string) count($rows), 'Sesuai saringan', 'saring', 'biru') ?>
</div>

<div class="card mt16">
  <div class="card-kepala">
    <div>
      <h3 class="mb0">Tanda Tangan Digital Daftar Hadir</h3>
      <p class="card-ket">Buka <b>Daftar Hadir</b> sebuah notulen pada perangkat berlayar sentuh (tablet/HP
        atau layar sentuh), lalu tekan tombol <b>Tanda Tangan</b> pada kolom tanda tangan tiap nama —
        termasuk blok <b>Kepala <?= e(istilah_lembaga()) ?></b> dan <b>Notulis</b>. Tanda tangan digambar
        langsung dengan jari/stylus dan tersimpan pada notulen itu.</p>
    </div>
    <div class="card-aksi">
      <?php $notulenTtdTerakhir = $rows[0]['id'] ?? 0; ?>
      <?php if ($notulenTtdTerakhir): ?>
        <a class="btn btn-garis" href="cetak_notulen.php?id=<?= (int) $notulenTtdTerakhir ?>&amp;bagian=hadir"
          target="_blank" rel="noopener"><?= ikon('ubah') ?> Buka Daftar Hadir Terbaru</a>
      <?php endif; ?>
    </div>
  </div>
  <p class="kecil mut" style="margin:0">Tombol tanda tangan hanya muncul pada perangkat yang memiliki
    layar sentuh; pada komputer biasa lembar tetap dapat dicetak dan kolom tanda tangan diisi tangan.</p>
</div>

<section class="card mt16">
  <div class="card-kepala">
    <div>
      <h2>Daftar Notulen Rapat</h2>
      <p class="card-ket">Berita acara rapat beserta daftar hadir guru dan foto bukti kegiatan, siap dilihat dan dicetak.</p>
    </div>
    <div class="card-aksi">
      <a class="btn" href="notulen_form.php"><?= ikon('tambah') ?> Buat Notulen</a>
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="notulen-rapat"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
    </div>
  </div>

  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label>
      <input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="topik / tempat / agenda / hasil rapat…"></div>
    <div class="medan"><label for="dari">Dari Tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e($fDari) ?>" data-auto></div>
    <div class="medan"><label for="sampai">Sampai Tanggal</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($fSampai) ?>" data-auto></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $fDari !== '' || $fSampai !== ''): ?><a class="btn btn-garis" href="notulen.php">Reset</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="kosong">
      <b>Belum ada notulen rapat.</b><br>
      Buat notulen lewat tombol “Buat Notulen”, lalu pilih guru yang menghadiri rapat.
      <div class="mt12"><a class="btn" href="notulen_form.php"><?= ikon('tambah') ?> Buat Notulen</a></div>
    </div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl" id="tblNotulen">
        <thead><tr>
          <th style="width:112px">Tanggal</th>
          <th>Topik Rapat</th>
          <th class="tengah" style="width:82px">Hadir</th>
          <th class="tengah" style="width:66px">Foto</th>
          <th class="tengah" style="width:96px">Tanda Tangan</th>
          <th style="width:150px">Pimpinan / Notulis</th>
          <th style="width:230px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $jid = (int) $r['id']; $hal = nama_hari(); ?>
          <tr>
            <td class="nowrap">
              <?= e(tanggal_id((string) $r['tanggal'])) ?>
              <span class="sel-sub"><?= e($hal[(int) date('N', strtotime((string) $r['tanggal']))] ?? '') ?><?= trim((string) $r['waktu']) !== '' ? ' · ' . e((string) $r['waktu']) : '' ?></span>
            </td>
            <td>
              <b><?= e((string) ($r['judul'] ?: '(topik belum diisi)')) ?></b>
              <span class="sel-sub"><?= e((string) ($r['tempat'] ?: 'tempat belum diisi')) ?></span>
            </td>
            <td class="tengah"><span class="chip hijau"><?= (int) $r['jml_hadir'] ?> guru</span></td>
            <td class="tengah"><span class="chip <?= (int) $r['jml_foto'] > 0 ? 'hijau' : 'mut' ?>"
              title="Foto bukti kegiatan (maksimal <?= (int) NOTULEN_FOTO_MAKS ?>)"><?= (int) $r['jml_foto'] ?>/<?= (int) NOTULEN_FOTO_MAKS ?></span></td>
            <td class="tengah"><a class="chip <?= (int) $r['jml_ttd'] > 0 ? 'hijau' : 'mut' ?>"
              href="cetak_notulen.php?id=<?= $jid ?>&amp;bagian=hadir" target="_blank" rel="noopener"
              title="Tanda tangan digital yang sudah tersimpan pada lembar Daftar Hadir — buka lembarnya untuk menandatangani (perangkat layar sentuh)"><?= (int) $r['jml_ttd'] ?> ttd</a></td>
            <td><?= e((string) ($r['pimpinan'] ?: '-')) ?>
              <span class="sel-sub">Notulis: <?= e((string) ($r['notulis'] ?: '-')) ?></span></td>
            <td>
              <div class="aksi">
                <a class="btn btn-garis btn-kecil" href="cetak_notulen.php?id=<?= $jid ?>&amp;bagian=berita" target="_blank" rel="noopener" title="Cetak Berita Acara"><?= ikon('cetak') ?> Berita Acara</a>
                <a class="btn btn-garis btn-kecil" href="cetak_notulen.php?id=<?= $jid ?>&amp;bagian=hadir" target="_blank" rel="noopener" title="Cetak Daftar Hadir">Daftar Hadir</a>
                <a class="btn btn-garis btn-kecil" href="cetak_notulen.php?id=<?= $jid ?>&amp;bagian=isi" target="_blank" rel="noopener" title="Cetak Isi Notulen">Isi Notulen</a>
                <?php if ((int) $r['jml_foto'] > 0): ?>
                  <a class="btn btn-garis btn-kecil" href="cetak_notulen.php?id=<?= $jid ?>&amp;bagian=foto" target="_blank" rel="noopener" title="Cetak Foto Dokumentasi">Foto Dokumentasi</a>
                <?php endif; ?>
                <a class="btn btn-garis btn-kecil" href="notulen_form.php?id=<?= $jid ?>#cardFotoNotulen" title="Foto bukti kegiatan (<?= (int) $r['jml_foto'] ?> dari <?= (int) NOTULEN_FOTO_MAKS ?>)"><?= ikon('unggah') ?> Foto</a>
                <a class="btn btn-garis btn-kecil" href="notulen_form.php?id=<?= $jid ?>" title="Ubah notulen"><?= ikon('ubah') ?></a>
                <form method="post" data-konfirmasi="Hapus notulen “<?= e((string) $r['judul']) ?>” beserta daftar hadir dan fotonya?">
                  <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $jid ?>">
                  <button class="btn btn-bahaya btn-garis btn-kecil" type="submit" title="Hapus"><?= ikon('hapus') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
// Ekspor CSV (mengikuti saringan yang sedang tampil).
$headerEkspor = ['No', 'Tanggal', 'Waktu', 'Tempat', 'Topik Rapat', 'Pimpinan', 'Jabatan Pimpinan', 'Notulis', 'Jumlah Hadir', 'Jumlah Foto', 'Hasil Pembahasan', 'Keputusan'];
$barisEkspor = [];
foreach ($rows as $i => $r) {
    $barisEkspor[] = [$i + 1, ekspor_tgl((string) $r['tanggal']), $r['waktu'], $r['tempat'], $r['judul'],
        $r['pimpinan'], $r['pimpinan_jabatan'], $r['notulis'], (int) $r['jml_hadir'], (int) $r['jml_foto'],
        $r['pembahasan'], $r['keputusan']];
}
if (f('unduh') === 'csv') {
    csv_kirim('notulen-rapat', $headerEkspor, $barisEkspor);
}
?>
<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Notulen Rapat', 'sheet' => 'Notulen', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
