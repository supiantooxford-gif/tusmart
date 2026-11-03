<?php
/**
 * Manajemen Pengguna (khusus Super Admin): akun Super Admin & Kepala Tata Usaha.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_super();

$editId = idf('edit');
$edit = $editId > 0 ? satu('SELECT * FROM users WHERE id = ?', [$editId]) : null;
$galat = [];
$sandiSekali = $_SESSION['sandi_sekali'] ?? null;
unset($_SESSION['sandi_sekali']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $aksi = aksi_post();
    $id = idf('id');

    if ($aksi === 'simpan') {
        $nama = f('nama');
        $username = f('username');
        $role = in_array(f('role'), ['super', 'kepala_tu'], true) ? f('role') : 'kepala_tu';
        $jabatan = f('jabatan');
        $telepon = f('telepon');
        $aktif = f('aktif') === '0' ? 0 : 1;
        $sandi = (string) ($_POST['sandi'] ?? '');
        $lama = $id > 0 ? satu('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if ($nama === '') { $galat[] = 'Nama wajib diisi.'; }
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) { $galat[] = 'Nama pengguna 3–40 karakter (huruf, angka, titik, garis bawah, tanda hubung).'; }
        if (satu('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) { $galat[] = 'Nama pengguna sudah dipakai.'; }
        if (!$lama && strlen($sandi) < 6) { $galat[] = 'Kata sandi akun baru minimal 6 karakter.'; }
        if ($sandi !== '' && strlen($sandi) < 6) { $galat[] = 'Kata sandi minimal 6 karakter.'; }
        if ($id > 0 && $lama && (int) $lama['id'] === (int) current_user()['id'] && ($aktif === 0 || $role !== $lama['role'])) {
            $galat[] = 'Anda tidak dapat menonaktifkan atau mengubah peran akun Anda sendiri.';
        }
        if (!$galat) {
            if ($id > 0) {
                q('UPDATE users SET nama = ?, username = ?, role = ?, jabatan = ?, telepon = ?, aktif = ? WHERE id = ?',
                    [$nama, $username, $role, $jabatan, $telepon, $aktif, $id]);
                if ($sandi !== '') {
                    q('UPDATE users SET password = ? WHERE id = ?', [password_hash($sandi, PASSWORD_DEFAULT), $id]);
                }
                log_catat('ubah_pengguna', $username);
                flash('Akun ' . $username . ' diperbarui.' . ($sandi !== '' ? ' Kata sandi diganti.' : ''));
            } else {
                q('INSERT INTO users (username, nama, password, role, jabatan, telepon, aktif, dibuat) VALUES (?,?,?,?,?,?,?,?)',
                    [$username, $nama, password_hash($sandi, PASSWORD_DEFAULT), $role, $jabatan, $telepon, $aktif, date('Y-m-d H:i:s')]);
                log_catat('tambah_pengguna', $username);
                flash('Akun ' . $username . ' dibuat.');
            }
            redirect('pengguna.php');
        }
        $edit = ['id' => $id, 'nama' => $nama, 'username' => $username, 'role' => $role, 'jabatan' => $jabatan, 'telepon' => $telepon, 'aktif' => $aktif, 'foto' => $lama['foto'] ?? ''];
    }

    if ($aksi === 'reset') {
        $t = satu('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$t) {
            flash('Akun tidak ditemukan.', 'err');
            redirect('pengguna.php');
        }
        $baru = bin2hex(random_bytes(4));
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), $id]);
        $_SESSION['sandi_sekali'] = ['username' => $t['username'], 'sandi' => $baru];
        log_catat('reset_sandi', $t['username']);
        flash('Kata sandi ' . $t['username'] . ' direset. Salin sandi baru yang tampil (hanya muncul sekali).');
        redirect('pengguna.php');
    }

    if ($aksi === 'toggle') {
        $t = satu('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$t) {
            flash('Akun tidak ditemukan.', 'err');
        } elseif ((int) $t['id'] === (int) current_user()['id']) {
            flash('Anda tidak dapat menonaktifkan akun Anda sendiri.', 'err');
        } else {
            $baru = (int) $t['aktif'] === 1 ? 0 : 1;
            q('UPDATE users SET aktif = ? WHERE id = ?', [$baru, $id]);
            log_catat('status_pengguna', $t['username'] . ' → ' . ($baru === 1 ? 'aktif' : 'nonaktif'));
            flash('Akun ' . $t['username'] . ' ' . ($baru === 1 ? 'diaktifkan' : 'dinonaktifkan') . '.');
        }
        redirect('pengguna.php');
    }

    if ($aksi === 'hapus') {
        $t = satu('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$t) {
            flash('Akun tidak ditemukan.', 'err');
        } elseif ((int) $t['id'] === (int) current_user()['id']) {
            flash('Anda tidak dapat menghapus akun Anda sendiri.', 'err');
        } elseif ((int) kolom('SELECT COUNT(*) FROM users') <= 1) {
            flash('Akun terakhir tidak dapat dihapus.', 'err');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            log_catat('hapus_pengguna', $t['username']);
            flash('Akun ' . $t['username'] . ' dihapus.');
        }
        redirect('pengguna.php');
    }
}

$daftar = semua('SELECT * FROM users ORDER BY role, nama');
$nilai = [
    'id' => $edit['id'] ?? 0, 'nama' => $edit['nama'] ?? '', 'username' => $edit['username'] ?? '',
    'role' => $edit['role'] ?? 'kepala_tu', 'jabatan' => $edit['jabatan'] ?? '', 'telepon' => $edit['telepon'] ?? '', 'aktif' => (string) ($edit['aktif'] ?? '1'),
];
page_top('Manajemen Pengguna', 'Khusus Super Admin');
?>
<?php if ($sandiSekali): ?>
  <div class="alert alert-warn">
    <div><b>Kata sandi baru untuk <?= e($sandiSekali['username']) ?>:</b>
      <span class="mono copy-teks" id="sandiBaru"><?= e($sandiSekali['sandi']) ?></span>
      <button class="btn btn-garis btn-kecil" type="button" data-salin="#sandiBaru"><?= ikon('salin') ?> Salin</button>
      <br><span class="kecil">Sandi ini hanya ditampilkan sekali — sampaikan kepada pemiliknya lalu minta segera menggantinya.</span></div>
  </div>
<?php endif; ?>
<?php foreach ($galat as $er): ?><div class="alert alert-err"><?= e($er) ?></div><?php endforeach; ?>

<div class="card">
  <div class="card-kepala">
    <div><h2><?= $nilai['id'] > 0 ? 'Ubah Akun' : 'Tambah Akun' ?></h2><p class="card-ket">Aplikasi ini memakai dua jenis akun: Super Admin dan Kepala Tata Usaha.</p></div>
    <div class="card-aksi"><a class="btn btn-garis" href="pengguna.php"><?= $nilai['id'] > 0 ? 'Batal Ubah' : 'Bersihkan' ?></a></div>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int) $nilai['id'] ?>">
    <div class="form-grid f3">
      <div class="medan"><label for="nama">Nama Lengkap</label><input type="text" id="nama" name="nama" value="<?= e($nilai['nama']) ?>" required maxlength="120"></div>
      <div class="medan"><label for="username">Nama Pengguna</label><input type="text" id="username" name="username" value="<?= e($nilai['username']) ?>" required maxlength="40"></div>
      <div class="medan"><label for="role">Peran</label><select id="role" name="role"><?= opsi(['kepala_tu' => 'Kepala Tata Usaha', 'super' => 'Super Admin'], $nilai['role']) ?></select></div>
      <div class="medan"><label for="jabatan">Jabatan</label><input type="text" id="jabatan" name="jabatan" value="<?= e($nilai['jabatan']) ?>" maxlength="80"></div>
      <div class="medan"><label for="telepon">Telepon</label><input type="text" id="telepon" name="telepon" value="<?= e($nilai['telepon']) ?>" maxlength="40"></div>
      <div class="medan"><label for="aktif">Status Akun</label><select id="aktif" name="aktif"><?= opsi(['1' => 'Aktif', '0' => 'Nonaktif'], $nilai['aktif']) ?></select></div>
      <div class="medan"><label for="sandi">Kata Sandi <?= $nilai['id'] > 0 ? '(kosongkan bila tidak diganti)' : '' ?></label>
        <input type="password" id="sandi" name="sandi" autocomplete="new-password" minlength="6" <?= $nilai['id'] > 0 ? '' : 'required' ?>>
        <span class="petunjuk">Minimal 6 karakter.</span></div>
    </div>
    <div class="tombol-baris mt16"><button class="btn" type="submit"><?= ikon('cek') ?> Simpan Akun</button></div>
  </form>
</div>

<div class="card">
  <div class="card-kepala"><div><h2>Daftar Akun</h2><p class="card-ket"><?= count($daftar) ?> akun terdaftar.</p></div></div>
  <div class="tabel-bungkus">
    <table class="tbl">
      <thead><tr><th class="tengah" style="width:38px">No</th><th>Nama / Pengguna</th><th>Peran</th><th>Kontak</th><th class="tengah">Status</th><th class="tengah">Terakhir Masuk</th><th style="width:210px">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $a): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><b><?= e($a['nama']) ?></b><span class="sel-sub mono"><?= e($a['username']) ?></span></td>
          <td><?= e(role_label((string) $a['role'])) ?><?= $a['jabatan'] ? '<span class="sel-sub">' . e($a['jabatan']) . '</span>' : '' ?></td>
          <td><?= e($a['telepon'] ?: '-') ?></td>
          <td class="tengah"><span class="pil <?= (int) $a['aktif'] === 1 ? 'pil-aktif' : 'pil-nonaktif' ?>"><?= (int) $a['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="tengah nowrap"><?= e(waktu_id($a['terakhir'] ?? '')) ?></td>
          <td>
            <div class="aksi">
              <a class="btn btn-garis btn-kecil" href="pengguna.php?edit=<?= (int) $a['id'] ?>"><?= ikon('ubah') ?> Ubah</a>
              <form method="post" data-konfirmasi="Reset kata sandi akun <?= e($a['username']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-garis btn-kecil" type="submit">Reset Sandi</button>
              </form>
              <form method="post" data-konfirmasi="<?= (int) $a['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= e($a['username']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-garis btn-kecil" type="submit"><?= (int) $a['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-konfirmasi="Hapus akun <?= e($a['username']) ?>? Tindakan ini tidak dapat dibatalkan.">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-bahaya btn-garis btn-kecil" type="submit"><?= ikon('hapus') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_bottom(); ?>
