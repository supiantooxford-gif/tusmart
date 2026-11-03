<?php
/**
 * Halaman masuk TUSmart (Super Admin & Kepala Tata Usaha).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

if (is_login()) {
    redirect('index.php');
}

$galat = '';
$username = '';
$batasPercobaan = 5;

$_SESSION['masuk_gagal'] = $_SESSION['masuk_gagal'] ?? ['n' => 0, 'sejak' => 0];
$percobaan = $_SESSION['masuk_gagal'];
if ($percobaan['sejak'] > 0 && (time() - (int) $percobaan['sejak']) > 600) {
    $_SESSION['masuk_gagal'] = ['n' => 0, 'sejak' => 0];
    $percobaan = $_SESSION['masuk_gagal'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        http_response_code(419);
        $galat = 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ((int) $percobaan['n'] >= $batasPercobaan) {
        $galat = 'Percobaan masuk terlalu banyak. Coba lagi dalam 10 menit.';
    } else {
        $username = f('username');
        $sandi = (string) ($_POST['password'] ?? '');
        if ($username === '' || $sandi === '') {
            $galat = 'Nama pengguna dan kata sandi wajib diisi.';
        } else {
            $u = satu('SELECT * FROM users WHERE username = ?', [$username]);
            if (!$u || !password_verify($sandi, (string) $u['password'])) {
                $galat = 'Nama pengguna atau kata sandi salah.';
            } elseif ((int) $u['aktif'] !== 1) {
                $galat = 'Akun ini dinonaktifkan. Hubungi Super Admin.';
            } else {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $u['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                $_SESSION['masuk_gagal'] = ['n' => 0, 'sejak' => 0];
                q('UPDATE users SET terakhir = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $u['id']]);
                log_catat('masuk', role_label((string) $u['role']));
                flash('Selamat datang, ' . $u['nama'] . '.');
                redirect('index.php');
            }
        }
        if ($galat !== '') {
            if ((int) $percobaan['n'] === 0) { $percobaan['sejak'] = time(); }
            $percobaan['n'] = (int) $percobaan['n'] + 1;
            $_SESSION['masuk_gagal'] = $percobaan;
            try {
                q('INSERT INTO log_aktivitas (user_id, nama, role, aktivitas, keterangan, ip, peramban, waktu) VALUES (NULL,?,?,?,?,?,?,?)',
                    [$username, '', 'gagal', $galat, log_ip(), log_peramban(), date('Y-m-d H:i:s')]);
            } catch (Throwable $ex) {
            }
        }
    }
}

$logo = setting('logo');
$sisa = max(0, $batasPercobaan - (int) ($_SESSION['masuk_gagal']['n'] ?? 0));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</head>
<body>
<div class="masuk-wrap">
  <div class="masuk-grid">
    <div class="masuk-info">
      <?php if ($logo !== ''): ?>
        <img class="masuk-logo" src="<?= e($logo) ?>" alt="Logo <?= e(lembaga_nama()) ?>">
      <?php else: ?>
        <span class="masuk-logo" style="display:grid;place-items:center;font-weight:800;color:#0e2a5c;font-size:1.5rem">TU</span>
      <?php endif; ?>
      <h1 class="masuk-nama"><?= e(APP_NAME) ?></h1>
      <p class="masuk-sub">Tata Usaha Smart · <?= e(lembaga_nama()) ?></p>
      <ul class="masuk-fitur">
        <li><?= ikon('guru') ?><span>Administrasi kepegawaian: data &amp; absensi guru/tenaga kependidikan</span></li>
        <li><?= ikon('siswa') ?><span>Administrasi kesiswaan: data siswa, buku induk, mutasi &amp; klapper</span></li>
        <li><?= ikon('surat_masuk') ?><span>Persuratan: surat masuk, surat keluar, arsip digital</span></li>
        <li><?= ikon('sarpras') ?><span>Inventaris sarana &amp; prasarana (tersambung e-GURU)</span></li>
        <li><?= ikon('notulen') ?><span>Notulen rapat lengkap dengan daftar hadir</span></li>
      </ul>
    </div>

    <div class="masuk-kartu">
      <h2>Masuk Aplikasi</h2>
      <p class="petunjuk">Gunakan akun <b>Super Admin</b> atau <b>Kepala Tata Usaha</b> yang diberikan pengelola.</p>
      <?php if ($galat !== ''): ?>
        <div class="alert alert-err"><?= e($galat) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="medan">
            <label for="username">Nama Pengguna</label>
            <input type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
          </div>
          <div class="medan">
            <label for="password">Kata Sandi</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <button class="btn" type="submit" style="width:100%">Masuk</button>
        </div>
      </form>
      <?php if ($sisa < 5): ?>
        <p class="kecil mut mt12 mb0">Sisa percobaan masuk: <?= (int) $sisa ?> kali.</p>
      <?php endif; ?>
      <p class="kecil mut mt12 mb0"><b>Keamanan:</b> jangan bagikan kata sandi. Ganti sandi bawaan pada menu
        <i>Pengaturan &rarr; Pengaturan Akun</i> setelah masuk pertama kali.</p>
    </div>
  </div>
  <p class="masuk-kaki"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · Sistem Tata Usaha Digital<br>Dikembangkan oleh <?= e(APP_AUTHOR) ?></p>
</div>
</body>
</html>
