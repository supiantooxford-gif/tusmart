<?php
/**
 * Formulir Notulen Rapat — DISAMAKAN dengan aplikasi e-GURU:
 *   - Waktu & Tempat (tanggal, waktu, tempat)
 *   - Hasil Rapat (Topik/Judul Rapat + Hasil Pembahasan) — isian Agenda & Keputusan tidak dipakai
 *     (kolomnya tetap di basis data; nilai lama TIDAK ditimpa bila field tidak dikirim)
 *   - Pimpinan & Notulis (+ jumlah guru hadir terpilih)
 *   - Daftar Hadir Guru: pilih guru satu per satu (centang), ada pencarian, "Tandai semua", "Kosongkan"
 *   - Foto Bukti Kegiatan Rapat: maksimal NOTULEN_FOTO_MAKS (2) foto, diunggah setelah notulen tersimpan
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/notulen_ttd.php';   // penjaga tanda tangan digital
require_login();

$id = idf('id');
$edit = notulen_by_id($id);
if ($id > 0 && !$edit) {
    flash('Notulen tidak ditemukan.', 'err');
    redirect('notulen.php');
}

$daftarGuru = guru_list(true);
$pesertaTersimpan = $edit ? notulen_peserta((int) $edit['id']) : [];
$pilihTercentang = [];
foreach ($pesertaTersimpan as $h) {
    if ((int) ($h['guru_id'] ?? 0) > 0) {
        $pilihTercentang[(int) $h['guru_id']] = true;
    }
}

// ---------------- simpan ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aksi_post() === 'simpan') {
    csrf_wajib();
    $tanggal = f('tanggal');
    $waktu = f('waktu');
    $tempat = f('tempat');
    $judul = f('judul');
    $pembahasan = f('pembahasan');
    // Kolom agenda/keputusan tidak ada di formulir; hanya diperbarui bila field benar-benar dikirim.
    $agenda = array_key_exists('agenda', $_POST) ? trim((string) $_POST['agenda']) : (string) ($edit['agenda'] ?? '');
    $keputusan = array_key_exists('keputusan', $_POST) ? trim((string) $_POST['keputusan']) : (string) ($edit['keputusan'] ?? '');
    $pimpinan = f('pimpinan');
    $pimpinanJabatan = f('pimpinan_jabatan');
    $notulis = f('notulis');
    $pilih = array_map('intval', is_array($_POST['hadir'] ?? null) ? $_POST['hadir'] : []);

    // Susun peserta dari data guru (snapshot nama/NIP/jabatan) — hanya guru aktif yang sah.
    $peserta = [];
    foreach ($daftarGuru as $g) {
        $gid = (int) $g['id'];
        if (!in_array($gid, $pilih, true)) { continue; }
        $peserta[] = [
            'guru_id' => $gid,
            'nama'    => (string) $g['nama'],
            'nip'     => notulen_nip_guru($g),
            'jabatan' => (string) ($g['jabatan'] ?? ''),
        ];
    }

    $galat = [];
    if (!tanggal_sah($tanggal)) { $galat[] = 'Tanggal rapat wajib diisi.'; }
    if ($judul === '') { $galat[] = 'Topik / judul rapat wajib diisi.'; }
    if (!$peserta) { $galat[] = 'Pilih minimal satu guru yang menghadiri rapat pada daftar hadir.'; }
    if ($galat) {
        foreach ($galat as $g) { flash($g, 'err'); }
        redirect('notulen_form.php' . ($edit ? '?id=' . (int) $edit['id'] : ''));
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ($edit) {
            q('UPDATE notulen SET tanggal = ?, waktu = ?, tempat = ?, judul = ?, agenda = ?, pembahasan = ?,
                 keputusan = ?, pimpinan = ?, pimpinan_jabatan = ?, notulis = ? WHERE id = ?',
                [$tanggal, $waktu, $tempat, $judul, $agenda, $pembahasan, $keputusan, $pimpinan,
                 $pimpinanJabatan, $notulis, (int) $edit['id']]);
            $notulenId = (int) $edit['id'];
            q('DELETE FROM notulen_hadir WHERE notulen_id = ?', [$notulenId]);
            flash('Notulen rapat “' . $judul . '” diperbarui.');
        } else {
            q('INSERT INTO notulen (tanggal, waktu, tempat, judul, agenda, pembahasan, keputusan, pimpinan,
                 pimpinan_jabatan, notulis, dibuat, dibuat_oleh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$tanggal, $waktu, $tempat, $judul, $agenda, $pembahasan, $keputusan, $pimpinan,
                 $pimpinanJabatan, $notulis, date('Y-m-d H:i:s'), current_user()['nama'] ?? '']);
            $notulenId = (int) $pdo->lastInsertId();
        }
        $urut = 0;
        foreach ($peserta as $p) {
            q('INSERT INTO notulen_hadir (notulen_id, guru_id, nama, nip, jabatan, hadir, urutan) VALUES (?,?,?,?,?,1,?)',
                [$notulenId, $p['guru_id'], $p['nama'], $p['nip'], $p['jabatan'], $urut]);
            $urut++;
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        throw $ex;
    }
    // Bersihkan tanda tangan pihak yang sudah tidak ada lagi (peserta dikeluarkan, atau nama
    // Pimpinan/Notulis diganti) supaya tanda tangan tidak menempel pada nama orang lain.
    notulen_ttd_hapus_peserta_hilang($notulenId,
        notulen_ttd_pihak(notulen_by_id($notulenId) ?: [], notulen_peserta($notulenId)));
    log_catat('notulen', $judul . ' (' . count($peserta) . ' hadir)');
    if (!$edit) {
        flash('Notulen rapat “' . $judul . '” tersimpan beserta daftar hadir ' . count($peserta)
            . ' guru. Unggah foto bukti kegiatan di bagian bawah halaman ini.');
    }
    redirect('notulen_form.php?id=' . $notulenId . '#cardFotoNotulen');
}

$n = $edit ?? [];
$nilai = static fn(string $kunci, string $bawaan = ''): string => (string) ($n[$kunci] ?? $bawaan);

// Nilai awal notulen BARU: pimpinan = kepala lembaga, jabatan mengikuti jenis lembaga,
// notulis = nama akun yang sedang masuk (sama seperti e-GURU).
$pimpinanAwal = $edit ? $nilai('pimpinan') : kepala_lembaga();
$pimpinanJabatanAwal = $edit ? $nilai('pimpinan_jabatan') : ('Kepala ' . istilah_lembaga());
$notulisAwal = $edit ? $nilai('notulis') : (current_user()['nama'] ?? '');

$fotoTersimpan = $edit ? notulen_foto((int) $edit['id']) : [];
$jmlFoto = count($fotoTersimpan);
$sisaFoto = max(0, NOTULEN_FOTO_MAKS - $jmlFoto);

page_top($edit ? 'Ubah Notulen Rapat' : 'Buat Notulen Rapat', 'Administrasi rapat');
?>
<section class="card">
  <div class="card-kepala">
    <div>
      <h2><?= $edit ? 'Ubah Notulen Rapat' : 'Buat Notulen Rapat' ?></h2>
      <p class="card-ket">Isi berita acara rapat, lalu pilih guru yang menghadiri rapat pada daftar hadir di bawah.</p>
    </div>
    <div class="card-aksi">
      <a class="btn btn-garis" href="notulen.php">Kembali ke Daftar</a>
      <?php if ($edit): ?>
        <a class="btn btn-garis" href="cetak_notulen.php?id=<?= (int) $edit['id'] ?>&bagian=berita" target="_blank" rel="noopener">Berita Acara</a>
        <a class="btn btn-garis" href="cetak_notulen.php?id=<?= (int) $edit['id'] ?>&bagian=hadir" target="_blank" rel="noopener">Daftar Hadir</a>
        <a class="btn btn-garis" href="cetak_notulen.php?id=<?= (int) $edit['id'] ?>&bagian=isi" target="_blank" rel="noopener">Isi Notulen</a>
        <?php if ($jmlFoto > 0): ?>
          <a class="btn btn-garis" href="cetak_notulen.php?id=<?= (int) $edit['id'] ?>&bagian=foto" target="_blank" rel="noopener">Foto Dokumentasi</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="form-grid" id="formNotulen">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" value="<?= (int) ($n['id'] ?? 0) ?>">

    <h3 class="bagian-judul">Waktu &amp; Tempat</h3>
    <div class="form-grid f3">
      <div class="medan"><label for="tanggal">Tanggal Rapat *</label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($nilai('tanggal', tanggal_hari_ini())) ?>" required></div>
      <div class="medan"><label for="waktu">Waktu</label>
        <input type="text" id="waktu" name="waktu" value="<?= e($nilai('waktu')) ?>" maxlength="60" placeholder="mis. 09.00 - 11.00"></div>
      <div class="medan"><label for="tempat">Tempat</label>
        <input type="text" id="tempat" name="tempat" value="<?= e($nilai('tempat')) ?>" maxlength="150" placeholder="mis. Ruang Guru"></div>
    </div>

    <h3 class="bagian-judul">Hasil Rapat</h3>
    <div class="form-grid">
      <div class="medan"><label for="judul">Topik / Judul Rapat *</label>
        <input type="text" id="judul" name="judul" value="<?= e($nilai('judul')) ?>" maxlength="200" required
          placeholder="mis. Rapat Evaluasi Hasil Belajar Semester Ganjil"></div>
      <div class="medan"><label for="pembahasan">Hasil Pembahasan</label>
        <textarea id="pembahasan" name="pembahasan" rows="5" maxlength="6000" placeholder="Uraian hasil rapat…"><?= e($nilai('pembahasan')) ?></textarea></div>
    </div>

    <h3 class="bagian-judul">Pimpinan &amp; Notulis</h3>
    <div class="form-grid f2">
      <div class="medan"><label for="pimpinan">Pimpinan Rapat</label>
        <input type="text" id="pimpinan" name="pimpinan" value="<?= e($pimpinanAwal) ?>" maxlength="120" list="saranPimpinan">
        <datalist id="saranPimpinan"><?php foreach ($daftarGuru as $g): ?><option value="<?= e((string) $g['nama']) ?>"><?php endforeach; ?></datalist></div>
      <div class="medan"><label for="pimpinan_jabatan">Jabatan Pimpinan Rapat</label>
        <input type="text" id="pimpinan_jabatan" name="pimpinan_jabatan" value="<?= e($pimpinanJabatanAwal) ?>" maxlength="100"
          placeholder="mis. Kepala <?= e(istilah_lembaga()) ?>"></div>
      <div class="medan"><label for="notulis">Notulis</label>
        <input type="text" id="notulis" name="notulis" value="<?= e($notulisAwal) ?>" maxlength="120"></div>
      <div class="medan"><label for="ringkasHadir">Guru hadir terpilih</label>
        <input type="text" id="ringkasHadir" value="<?= count($pilihTercentang) ?> guru" readonly>
        <span class="petunjuk">Berubah otomatis mengikuti centang pada daftar hadir di bawah.</span></div>
    </div>

    <h3 class="bagian-judul">Daftar Hadir Guru *</h3>
    <?php if (!$daftarGuru): ?>
      <div class="alert alert-warn mb0">
        <div>Belum ada data guru aktif. Tambahkan lewat menu <a href="guru.php">Data Guru</a> agar daftar hadir dapat diisi.</div>
      </div>
    <?php else: ?>
      <div class="saring" style="align-items:center">
        <div class="medan cari"><label for="cariGuru">Cari guru</label>
          <input type="search" id="cariGuru" placeholder="Nama guru / NIP / jabatan…"></div>
        <button class="btn btn-garis" type="button" id="btnHadirSemua"><?= ikon('cek') ?> Tandai semua</button>
        <button class="btn btn-garis" type="button" id="btnHadirKosong">Kosongkan</button>
        <div class="medan" style="flex:1"><span class="kecil mut">Centang guru yang menghadiri rapat — hanya yang dicentang masuk ke daftar hadir.</span></div>
      </div>
      <div class="tabel-bungkus" style="max-height:420px;overflow:auto">
        <table class="tbl" id="tblPilihGuru">
          <thead><tr>
            <th class="tengah" style="width:56px">Hadir</th><th>Nama Guru</th>
            <th style="width:150px">NIP / NUPTK</th><th style="width:170px">Jabatan</th>
          </tr></thead>
          <tbody>
          <?php foreach ($daftarGuru as $g): $gid = (int) $g['id']; $nipG = notulen_nip_guru($g); ?>
            <tr data-nama="<?= e(strtolower((string) $g['nama'] . ' ' . $nipG . ' ' . (string) ($g['jabatan'] ?? ''))) ?>">
              <td class="tengah"><input type="checkbox" class="chk-hadir" name="hadir[]" value="<?= $gid ?>"
                <?= isset($pilihTercentang[$gid]) ? ' checked' : '' ?> aria-label="Hadir: <?= e((string) $g['nama']) ?>"></td>
              <td><b><?= e((string) $g['nama']) ?></b></td>
              <td><?= e($nipG !== '' ? $nipG : '-') ?></td>
              <td><?= e((string) ($g['jabatan'] ?: '-')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="tombol-baris mt16">
      <button class="btn" type="submit"><?= ikon('cek') ?> <?= $edit ? 'Simpan Perubahan' : 'Simpan Notulen' ?></button>
      <a class="btn btn-garis" href="notulen.php">Batal</a>
      <?php if (!$daftarGuru): ?><span class="kecil mut">Daftar hadir wajib berisi minimal satu guru.</span><?php endif; ?>
    </div>
  </form>
</section>

<?php // ---------------- FOTO BUKTI KEGIATAN RAPAT (maksimal NOTULEN_FOTO_MAKS = 2) ---------------- ?>
<section class="card" id="cardFotoNotulen">
  <div class="card-kepala">
    <div>
      <h2>Foto Bukti Kegiatan Rapat</h2>
      <p class="card-ket">Dokumentasi kegiatan rapat sebagai bukti pelaksanaan — maksimal <?= (int) NOTULEN_FOTO_MAKS ?> foto.</p>
    </div>
    <?php if ($edit): ?>
      <div class="card-aksi"><span class="chip <?= $jmlFoto >= NOTULEN_FOTO_MAKS ? 'emas' : 'hijau' ?>"><?= $jmlFoto ?> dari <?= (int) NOTULEN_FOTO_MAKS ?> foto</span></div>
    <?php endif; ?>
  </div>

  <?php if (!$edit): ?>
    <div class="alert alert-warn mb0">
      <div>Simpan notulen ini terlebih dahulu. Setelah tersimpan, halaman ini menampilkan pilihan untuk
        mengunggah foto bukti kegiatan (maksimal <?= (int) NOTULEN_FOTO_MAKS ?> foto).</div>
    </div>
  <?php else: ?>
    <?php if ($fotoTersimpan): ?>
      <div class="not-foto-grid">
        <?php foreach ($fotoTersimpan as $i => $f): ?>
          <div class="not-foto-item">
            <div class="not-foto-gambar"><img src="<?= e((string) $f['url']) ?>" alt="Foto bukti kegiatan <?= $i + 1 ?>"></div>
            <form method="post" action="upload_notulen_foto.php" class="not-foto-ket">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="keterangan">
              <input type="hidden" name="notulen_id" value="<?= (int) $edit['id'] ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <input type="text" name="keterangan" value="<?= e((string) ($f['keterangan'] ?? '')) ?>"
                placeholder="Keterangan foto (mis. suasana rapat)" maxlength="160">
              <button class="btn btn-garis btn-kecil" type="submit">Simpan</button>
            </form>
            <form method="post" action="upload_notulen_foto.php" data-konfirmasi="Hapus foto bukti kegiatan ini?">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="notulen_id" value="<?= (int) $edit['id'] ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-bahaya btn-garis btn-kecil" type="submit">Hapus foto <?= $i + 1 ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="mut kecil mb12">Belum ada foto bukti kegiatan pada notulen ini.</p>
    <?php endif; ?>

    <?php if ($jmlFoto >= NOTULEN_FOTO_MAKS): ?>
      <div class="alert alert-warn mb0">
        <div>Sudah <?= (int) NOTULEN_FOTO_MAKS ?> foto (batas maksimal). Hapus salah satu foto di atas bila ingin menggantinya.</div>
      </div>
    <?php else: ?>
      <form method="post" action="upload_notulen_foto.php" enctype="multipart/form-data" id="formFotoNotulen" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="unggah">
        <input type="hidden" name="notulen_id" value="<?= (int) $edit['id'] ?>">
        <div class="form-grid f2">
          <div class="medan"><label for="fotoNotulen">Pilih berkas gambar (foto kegiatan rapat)</label>
            <input type="file" id="fotoNotulen" name="foto" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,image/*" data-pratinjau="#pratinjauFotoNotulen">
            <div class="pratinjau" id="pratinjauFotoNotulen">Belum ada berkas dipilih.</div>
          </div>
          <div class="medan"><label for="fotoNotulenKet">Keterangan (opsional)</label>
            <input type="text" id="fotoNotulenKet" name="keterangan" maxlength="160" placeholder="mis. suasana pembahasan rapat">
            <span class="petunjuk">Maksimal 15 MB, format JPG/PNG/GIF/WEBP/SVG. Sisa slot: <b><?= $sisaFoto ?></b> foto.</span>
          </div>
        </div>
        <div class="tombol-baris">
          <button class="btn" type="submit"><?= ikon('unggah') ?> Unggah foto bukti</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<script>
(function () {
  var tabel = document.getElementById('tblPilihGuru');
  var ringkas = document.getElementById('ringkasHadir');
  if (!tabel) { return; }
  function hitung() {
    var n = tabel.querySelectorAll('.chk-hadir:checked').length;
    if (ringkas) { ringkas.value = n + ' guru'; }
  }
  tabel.addEventListener('change', hitung); hitung();

  var cari = document.getElementById('cariGuru');
  if (cari) {
    cari.addEventListener('input', function () {
      var q = cari.value.toLowerCase().trim();
      tabel.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.hidden = q !== '' && (tr.getAttribute('data-nama') || '').indexOf(q) < 0;
      });
    });
  }
  var semua = document.getElementById('btnHadirSemua');
  if (semua) {
    semua.addEventListener('click', function () {
      tabel.querySelectorAll('tbody tr').forEach(function (tr) {
        if (!tr.hidden) { tr.querySelector('.chk-hadir').checked = true; }
      });
      hitung();
    });
  }
  var kosong = document.getElementById('btnHadirKosong');
  if (kosong) {
    kosong.addEventListener('click', function () {
      tabel.querySelectorAll('.chk-hadir').forEach(function (c) { c.checked = false; });
      hitung();
    });
  }
})();
</script>
<?php page_bottom(); ?>
