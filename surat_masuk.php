<?php
/**
 * Surat Masuk: registrasi, penomoran agenda otomatis, log pencatatan, unggah berkas surat.
 * Berkas yang diunggah otomatis masuk ke menu Arsip Digital.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$editId = idf('edit');
$edit = $editId > 0 ? satu('SELECT * FROM surat_masuk WHERE id = ?', [$editId]) : null;
$galat = [];

// ---------- simpan ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'simpan') {
    csrf_wajib();
    $id = idf('id');
    $lama = $id > 0 ? satu('SELECT * FROM surat_masuk WHERE id = ?', [$id]) : null;
    $kol = [
        'no_surat' => f('no_surat'),
        'tanggal_surat' => f('tanggal_surat'),
        'tanggal_terima' => f('tanggal_terima'),
        'pengirim' => f('pengirim'),
        'perihal' => f('perihal'),
        'sifat' => array_key_exists(f('sifat'), sifat_surat_list()) ? f('sifat') : 'biasa',
        'lampiran' => f('lampiran'),
        'disposisi' => f('disposisi'),
        'keterangan' => f('keterangan'),
    ];
    if ($kol['perihal'] === '') { $galat[] = 'Perihal surat wajib diisi.'; }
    if ($kol['tanggal_terima'] === '') { $kol['tanggal_terima'] = tanggal_hari_ini(); }
    if (!tanggal_sah($kol['tanggal_terima'])) { $galat[] = 'Tanggal terima tidak sah.'; }
    if ($kol['tanggal_surat'] !== '' && !tanggal_sah($kol['tanggal_surat'])) { $galat[] = 'Tanggal surat tidak sah.'; }

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
        $noAgenda = f('no_agenda');
        if ($noAgenda === '') {
            $noAgenda = agenda_berikut('surat_masuk', (int) substr($kol['tanggal_terima'], 0, 4), 'tanggal_terima');
        }
        $kol['no_agenda'] = $noAgenda;
        $kol['berkas_url'] = $berkas['url'];
        $kol['berkas_nama'] = $berkas['nama'];
        $kol['berkas_ukuran'] = $berkas['ukuran'];
        if ($id > 0) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
            $arg = array_values($kol);
            $arg[] = current_user()['nama'];
            $arg[] = date('Y-m-d H:i:s');
            $arg[] = $id;
            q("UPDATE surat_masuk SET $set, oleh = ?, diubah = ? WHERE id = ?", $arg);
            log_catat('ubah_surat_masuk', $noAgenda . ' · ' . $kol['perihal']);
            flash('Surat masuk ' . $noAgenda . ' diperbarui.');
        } else {
            $kol['oleh'] = current_user()['nama'];
            $kol['dibuat'] = date('Y-m-d H:i:s');
            $kol['diubah'] = date('Y-m-d H:i:s');
            $nm = array_keys($kol);
            q('INSERT INTO surat_masuk (' . implode(',', $nm) . ') VALUES (' . implode(',', array_fill(0, count($nm), '?')) . ')', array_values($kol));
            $id = (int) $pdo->lastInsertId();
            log_catat('tambah_surat_masuk', $noAgenda . ' · ' . $kol['perihal']);
            flash('Surat masuk ' . $noAgenda . ' teregistrasi.');
        }
        arsip_otomatis('masuk', $id, 'Surat Masuk ' . $noAgenda . ' — ' . $kol['perihal'], (string) $kol['tanggal_terima'], (string) $kol['berkas_url'], (string) $kol['berkas_nama'], (int) $kol['berkas_ukuran']);
        redirect('surat_masuk.php');
    }
    $edit = array_merge([
        'id' => $id, 'no_agenda' => f('no_agenda'), 'no_surat' => $kol['no_surat'], 'tanggal_surat' => $kol['tanggal_surat'],
        'tanggal_terima' => $kol['tanggal_terima'], 'pengirim' => $kol['pengirim'], 'perihal' => $kol['perihal'],
        'sifat' => $kol['sifat'], 'lampiran' => $kol['lampiran'], 'disposisi' => $kol['disposisi'], 'keterangan' => $kol['keterangan'],
        'berkas_url' => $berkas['url'], 'berkas_nama' => $berkas['nama'], 'berkas_ukuran' => $berkas['ukuran'],
    ]);
}

// ---------- hapus ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'hapus') {
    csrf_wajib();
    $id = idf('id');
    $s = satu('SELECT * FROM surat_masuk WHERE id = ?', [$id]);
    if (!$s) {
        flash('Surat masuk tidak ditemukan.', 'err');
    } else {
        q('DELETE FROM surat_masuk WHERE id = ?', [$id]);
        q("DELETE FROM arsip WHERE sumber = 'masuk' AND ref_id = ?", [$id]);
        log_catat('hapus_surat_masuk', $s['no_agenda'] . ' · ' . $s['perihal']);
        flash('Surat masuk ' . $s['no_agenda'] . ' beserta arsipnya dihapus.');
    }
    redirect('surat_masuk.php');
}

// ---------- daftar + saringan ----------
$cari = f('cari');
$sifat = f('sifat');
$dari = tanggal_sah(f('dari')) ? f('dari') : '';
$sampai = tanggal_sah(f('sampai')) ? f('sampai') : '';
$w = [];
$arg = [];
if ($cari !== '') {
    $w[] = '(no_agenda LIKE ? OR no_surat LIKE ? OR pengirim LIKE ? OR perihal LIKE ? OR disposisi LIKE ? OR keterangan LIKE ?)';
    $k = '%' . $cari . '%';
    array_push($arg, $k, $k, $k, $k, $k, $k);
}
if ($sifat !== '') { $w[] = 'sifat = ?'; $arg[] = $sifat; }
if ($dari !== '') { $w[] = 'tanggal_terima >= ?'; $arg[] = $dari; }
if ($sampai !== '') { $w[] = 'tanggal_terima <= ?'; $arg[] = $sampai; }
$daftar = semua('SELECT * FROM surat_masuk' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY COALESCE(tanggal_terima, tanggal_surat) DESC, id DESC', $arg);
$qCetak = http_build_query(array_filter(['cari' => $cari, 'sifat' => $sifat, 'dari' => $dari, 'sampai' => $sampai]));

$headerEkspor = ['No', 'No Agenda', 'No Surat', 'Tanggal Surat', 'Tanggal Terima', 'Pengirim', 'Perihal', 'Sifat', 'Lampiran', 'Disposisi', 'Keterangan', 'Berkas'];
$barisEkspor = [];
foreach ($daftar as $i => $s) {
    $barisEkspor[] = [$i + 1, $s['no_agenda'], $s['no_surat'], ekspor_tgl($s['tanggal_surat']), ekspor_tgl($s['tanggal_terima']),
        $s['pengirim'], $s['perihal'], sifat_surat_list()[$s['sifat']] ?? $s['sifat'], $s['lampiran'], $s['disposisi'], $s['keterangan'], $s['berkas_nama']];
}
if (f('unduh') === 'csv') {
    csv_kirim('surat-masuk', $headerEkspor, $barisEkspor);
}

$nilai = [
    'id' => $edit['id'] ?? 0, 'no_agenda' => $edit['no_agenda'] ?? '', 'no_surat' => $edit['no_surat'] ?? '',
    'tanggal_surat' => $edit['tanggal_surat'] ?? tanggal_hari_ini(), 'tanggal_terima' => $edit['tanggal_terima'] ?? tanggal_hari_ini(),
    'pengirim' => $edit['pengirim'] ?? '', 'perihal' => $edit['perihal'] ?? '', 'sifat' => $edit['sifat'] ?? 'biasa',
    'lampiran' => $edit['lampiran'] ?? '', 'disposisi' => $edit['disposisi'] ?? '', 'keterangan' => $edit['keterangan'] ?? '',
    'berkas_url' => $edit['berkas_url'] ?? '', 'berkas_nama' => $edit['berkas_nama'] ?? '', 'berkas_ukuran' => $edit['berkas_ukuran'] ?? 0,
];
$tampilForm = $edit !== null || f('aksi') === 'baru' || $galat;

$GLOBALS['__butuh_xlsx'] = true;
page_top('Surat Masuk', 'Administrasi persuratan · registrasi, penomoran, dan log surat masuk');
?>
<div class="card">
  <div class="card-kepala">
    <div><h2>Registrasi Surat Masuk</h2>
      <p class="card-ket">Nomor agenda dibuat otomatis (urut per tahun). Berkas yang diunggah otomatis tersimpan di <a href="arsip.php">Arsip Digital</a>.</p></div>
    <div class="card-aksi">
      <?php if ($tampilForm): ?><a class="btn btn-garis" href="surat_masuk.php">Tutup Formulir</a><?php else: ?><a class="btn" href="surat_masuk.php?aksi=baru"><?= ikon('tambah') ?> Registrasi Surat</a><?php endif; ?>
    </div>
  </div>
  <?php if ($tampilForm): ?>
    <?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int) $nilai['id'] ?>">
      <div class="form-grid f3">
        <div class="medan"><label for="no_agenda">No. Agenda</label>
          <input type="text" id="no_agenda" name="no_agenda" value="<?= e($nilai['no_agenda']) ?>" placeholder="otomatis bila dikosongkan" maxlength="40"></div>
        <div class="medan"><label for="no_surat">No. Surat</label><input type="text" id="no_surat" name="no_surat" value="<?= e($nilai['no_surat']) ?>" maxlength="80"></div>
        <div class="medan"><label for="sifat">Sifat Surat</label><select id="sifat" name="sifat"><?= opsi(sifat_surat_list(), $nilai['sifat']) ?></select></div>
        <div class="medan"><label for="tanggal_surat">Tanggal Surat</label><input type="date" id="tanggal_surat" name="tanggal_surat" value="<?= e($nilai['tanggal_surat']) ?>"></div>
        <div class="medan"><label for="tanggal_terima">Tanggal Terima</label><input type="date" id="tanggal_terima" name="tanggal_terima" value="<?= e($nilai['tanggal_terima']) ?>" required></div>
        <div class="medan"><label for="lampiran">Lampiran</label><input type="text" id="lampiran" name="lampiran" value="<?= e($nilai['lampiran']) ?>" maxlength="80" placeholder="mis. 1 lembar"></div>
        <div class="medan" style="grid-column:1/2"><label for="pengirim">Pengirim / Asal Surat</label>
          <input type="text" id="pengirim" name="pengirim" value="<?= e($nilai['pengirim']) ?>" maxlength="150" placeholder="mis. Kementerian Agama Kab. Gorontalo"></div>
        <div class="medan" style="grid-column:2/4"><label for="perihal">Perihal</label>
          <input type="text" id="perihal" name="perihal" value="<?= e($nilai['perihal']) ?>" maxlength="200" required></div>
        <div class="medan"><label for="disposisi">Disposisi / Tujuan</label><input type="text" id="disposisi" name="disposisi" value="<?= e($nilai['disposisi']) ?>" maxlength="150" placeholder="mis. Kepala Madrasah"></div>
        <div class="medan"><label for="keterangan">Keterangan</label><input type="text" id="keterangan" name="keterangan" value="<?= e($nilai['keterangan']) ?>" maxlength="200"></div>
        <div class="medan"><label for="berkas">Berkas Surat (PDF/gambar/dokumen)</label>
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
        <button class="btn" type="submit"><?= ikon('cek') ?> Simpan Surat Masuk</button>
        <a class="btn btn-garis" href="surat_masuk.php">Batal</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-kepala">
    <div><h2>Log Surat Masuk</h2><p class="card-ket"><?= count($daftar) ?> surat ditampilkan.</p></div>
    <div class="card-aksi">
      <button class="btn btn-garis" type="button" data-ekspor="#dataEkspor" data-nama="surat-masuk"><?= ikon('unduh') ?> Ekspor Excel</button>
      <a class="btn btn-garis" href="?unduh=csv&<?= e($qCetak) ?>"><?= ikon('unduh') ?> Unduh CSV</a>
      <?= tombol_cetak('cetak_surat_masuk.php?' . $qCetak, 'Cetak / PDF') ?>
    </div>
  </div>
  <form class="saring" method="get">
    <div class="medan cari"><label for="cari">Cari</label><input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="No agenda, no surat, pengirim, perihal…"></div>
    <div class="medan"><label for="sifat">Sifat</label><select id="sifat" name="sifat" data-auto><?= opsi(sifat_surat_list(), $sifat, 'Semua sifat') ?></select></div>
    <div class="medan"><label for="dari">Dari Tanggal</label><input type="date" id="dari" name="dari" value="<?= e($dari) ?>" data-auto></div>
    <div class="medan"><label for="sampai">Sampai</label><input type="date" id="sampai" name="sampai" value="<?= e($sampai) ?>" data-auto></div>
    <button class="btn" type="submit"><?= ikon('saring') ?> Terapkan</button>
    <?php if ($cari !== '' || $sifat !== '' || $dari !== '' || $sampai !== ''): ?><a class="btn btn-garis" href="surat_masuk.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$daftar): ?>
    <div class="kosong">Belum ada surat masuk yang cocok.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr>
          <th class="tengah" style="width:38px">No</th><th class="tengah">No. Agenda</th><th>No. Surat / Tanggal</th><th>Pengirim</th>
          <th>Perihal</th><th class="tengah">Sifat</th><th class="tengah">Diterima</th><th class="tengah">Berkas</th><th style="width:112px">Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $s): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td class="tengah mono"><?= e($s['no_agenda']) ?></td>
            <td><?= e($s['no_surat'] ?: '-') ?><span class="sel-sub"><?= e($s['tanggal_surat'] ? tanggal_id($s['tanggal_surat']) : '-') ?></span></td>
            <td><?= e($s['pengirim'] ?: '-') ?><?= $s['disposisi'] ? '<span class="sel-sub">Disposisi: ' . e($s['disposisi']) . '</span>' : '' ?></td>
            <td><?= e($s['perihal']) ?><?= $s['lampiran'] ? '<span class="sel-sub">Lampiran: ' . e($s['lampiran']) . '</span>' : '' ?></td>
            <td class="tengah"><span class="chip <?= $s['sifat'] === 'penting' || $s['sifat'] === 'segera' ? 'emas' : ($s['sifat'] === 'rahasia' ? 'merah' : 'mut') ?>"><?= e(sifat_surat_list()[$s['sifat']] ?? $s['sifat']) ?></span></td>
            <td class="tengah nowrap"><?= e(tanggal_id($s['tanggal_terima'])) ?></td>
            <td class="tengah">
              <?php if ($s['berkas_url']): ?>
                <a class="btn btn-garis btn-kecil" href="<?= e($s['berkas_url']) ?>" target="_blank" rel="noopener"><?= ikon('unduh') ?> Lihat</a>
              <?php else: ?><span class="mut kecil">-</span><?php endif; ?>
            </td>
            <td>
              <div class="aksi">
                <a class="btn btn-garis btn-kecil" href="surat_masuk.php?edit=<?= (int) $s['id'] ?>#konten"><?= ikon('ubah') ?> Ubah</a>
                <form method="post" data-konfirmasi="Hapus surat masuk <?= e($s['no_agenda']) ?>? Berkas arsipnya ikut dihapus dari daftar.">
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

<script type="application/json" id="dataEkspor"><?= json_encode(['judul' => 'Surat Masuk', 'sheet' => 'Surat Masuk', 'header' => $headerEkspor, 'baris' => $barisEkspor], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_bottom(); ?>
