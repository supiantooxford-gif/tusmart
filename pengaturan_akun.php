<?php
/**
 * Pengaturan Akun: profil pengguna, foto, ganti kata sandi, dan riwayat keamanan.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$u = current_user();
$galat = [];
$pesanTab = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();

    if ($aksi === 'profil') {
        $nama = f('nama');
        $username = f('username');
        $jabatan = f('jabatan');
        $telepon = f('telepon');
        if ($nama === '') { $galat[] = 'Nama wajib diisi.'; }
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) { $galat[] = 'Nama pengguna 3–40 karakter (huruf, angka, titik, garis bawah, tanda hubung).'; }
        $bentrok = satu('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $u['id']]);
        if ($bentrok) { $galat[] = 'Nama pengguna sudah dipakai akun lain.'; }
        $foto = (string) ($u['foto'] ?? '');
        if (!$galat && !empty($_FILES['foto']['name'])) {
            $up = unggah_berkas('foto', FOTO_EKSTENSI, FOTO_MAKS_BYTE);
            if (!empty($up['ok'])) { $foto = $up['url']; }
            elseif (empty($up['kosong'])) { $galat[] = 'Foto: ' . ($up['pesan'] ?? 'gagal diunggah.'); }
        }
        if (!$galat && f('hapus_foto') === '1') { $foto = ''; }
        if (!$galat) {
            q('UPDATE users SET nama = ?, username = ?, jabatan = ?, telepon = ?, foto = ? WHERE id = ?',
                [$nama, $username, $jabatan, $telepon, $foto, (int) $u['id']]);
            log_catat('ubah_profil', $username);
            flash('Profil berhasil diperbarui.');
            redirect('pengaturan_akun.php');
        }
        $pesanTab = 'profil';
    }

    if ($aksi === 'sandi') {
        $lama = (string) ($_POST['sandi_lama'] ?? '');
        $baru = (string) ($_POST['sandi_baru'] ?? '');
        $ulang = (string) ($_POST['sandi_ulang'] ?? '');
        if (!password_verify($lama, (string) $u['password'])) { $galat[] = 'Kata sandi lama tidak sesuai.'; }
        if (strlen($baru) < 6) { $galat[] = 'Kata sandi baru minimal 6 karakter.'; }
        if ($baru !== $ulang) { $galat[] = 'Ulangi kata sandi baru dengan tepat.'; }
        if ($baru !== '' && $baru === $lama) { $galat[] = 'Kata sandi baru tidak boleh sama dengan sandi lama.'; }
        if (!$galat) {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), (int) $u['id']]);
            log_catat('ganti_sandi', '');
            flash('Kata sandi berhasil diganti. Gunakan sandi baru saat masuk berikutnya.');
            redirect('pengaturan_akun.php');
        }
        $pesanTab = 'sandi';
    }
}

$riwayat = semua('SELECT * FROM log_aktivitas WHERE user_id = ? ORDER BY waktu DESC, id DESC LIMIT 12', [(int) $u['id']]);
$u = current_user(); // muat ulang
page_top('Pengaturan Akun', 'Profil, keamanan, dan riwayat aktivitas');
?>
<?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

<div class="grid g2">
  <section class="card">
    <div class="card-kepala"><div><h2>Profil Pengguna</h2><p class="card-ket">Peran akun: <b><?= e(role_label()) ?></b> · Terakhir masuk: <?= e(waktu_id($u['terakhir'] ?? '')) ?></p></div></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="profil">
      <div class="form-grid f2">
        <div class="medan"><label for="nama">Nama Lengkap</label><input type="text" id="nama" name="nama" value="<?= e($u['nama']) ?>" required maxlength="120"></div>
        <div class="medan"><label for="username">Nama Pengguna</label><input type="text" id="username" name="username" value="<?= e($u['username']) ?>" required maxlength="40"></div>
        <div class="medan"><label for="jabatan">Jabatan</label><input type="text" id="jabatan" name="jabatan" value="<?= e($u['jabatan'] ?? '') ?>" maxlength="80"></div>
        <div class="medan"><label for="telepon">Telepon / WhatsApp</label><input type="text" id="telepon" name="telepon" value="<?= e($u['telepon'] ?? '') ?>" maxlength="40"></div>
        <div class="medan" style="grid-column:1/-1"><label for="foto">Foto Profil</label>
          <input type="file" id="foto" name="foto" accept="image/*" data-pratinjau="#pratinjauFoto">
          <div class="pratinjau" id="pratinjauFoto">
            <?php if (!empty($u['foto'])): ?><img src="<?= e($u['foto']) ?>" alt="Foto profil"><?php else: ?>Belum ada foto profil.<?php endif; ?>
          </div>
          <?php if (!empty($u['foto'])): ?><label class="centang"><input type="checkbox" name="hapus_foto" value="1"> Hapus foto profil</label><?php endif; ?>
        </div>
      </div>
      <div class="tombol-baris mt16"><button class="btn" type="submit"><?= ikon('cek') ?> Simpan Profil</button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-kepala"><div><h2>Keamanan &amp; Kata Sandi</h2><p class="card-ket">Gunakan kata sandi yang kuat dan tidak dipakai di aplikasi lain.</p></div></div>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="sandi">
      <div class="form-grid">
        <div class="medan"><label for="sandi_lama">Kata Sandi Lama</label><input type="password" id="sandi_lama" name="sandi_lama" required autocomplete="current-password"></div>
        <div class="medan"><label for="sandi_baru">Kata Sandi Baru</label><input type="password" id="sandi_baru" name="sandi_baru" required minlength="6" autocomplete="new-password">
          <span class="petunjuk">Minimal 6 karakter.</span></div>
        <div class="medan"><label for="sandi_ulang">Ulangi Kata Sandi Baru</label><input type="password" id="sandi_ulang" name="sandi_ulang" required minlength="6" autocomplete="new-password"></div>
      </div>
      <div class="tombol-baris mt16"><button class="btn" type="submit"><?= ikon('kunci') ?> Ganti Kata Sandi</button></div>
    </form>
    <div class="alert alert-info mt16 mb0">
      <div><b>Catatan keamanan:</b> aplikasi ini hanya dapat diakses dengan akun yang terdaftar (Super Admin &amp; Kepala Tata Usaha).
        Setiap masuk/keluar dan percobaan masuk yang gagal tercatat pada riwayat aktivitas.</div>
    </div>
  </section>
</div>

<section class="card">
  <div class="card-kepala"><div><h2>Riwayat Aktivitas Akun Ini</h2><p class="card-ket">12 aktivitas terakhir.</p></div></div>
  <?php if (!$riwayat): ?>
    <div class="kosong">Belum ada aktivitas tercatat.</div>
  <?php else: ?>
    <div class="tabel-bungkus">
      <table class="tbl">
        <thead><tr><th class="tengah" style="width:38px">No</th><th>Waktu</th><th>Aktivitas</th><th>Keterangan</th><th>IP</th><th>Peramban</th></tr></thead>
        <tbody>
        <?php foreach ($riwayat as $i => $r): ?>
          <tr><td class="tengah"><?= $i + 1 ?></td><td class="nowrap"><?= e(waktu_id($r['waktu'])) ?></td>
            <td><span class="chip <?= $r['aktivitas'] === 'gagal' ? 'merah' : ($r['aktivitas'] === 'masuk' ? 'hijau' : 'mut') ?>"><?= e(ucfirst($r['aktivitas'])) ?></span></td>
            <td><?= e($r['keterangan'] ?: '-') ?></td><td><?= e($r['ip'] ?: '-') ?></td><td><?= e($r['peramban'] ?: '-') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php page_bottom(); ?>
