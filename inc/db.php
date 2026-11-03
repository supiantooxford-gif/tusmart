<?php
/**
 * Basis data TUSmart (SQLite via PDO).
 *
 * Skema dibuat/diperbarui oleh ensure_schema() yang idempoten & dijalankan dalam SATU transaksi
 * (BEGIN IMMEDIATE). Penanda versi disimpan di data/.schema_ok dan DIPERIKSA ULANG terhadap
 * keberadaan tabel/kolom wajib (skema_terkini) supaya basis data yang tertinggal dari penanda
 * dapat memulihkan diri sendiri.
 */
declare(strict_types=1);

const SCHEMA_VERSION = '8';

const SCHEMA_TABEL_WAJIB = [
    'users', 'pengaturan', 'kelas', 'guru', 'absensi_guru', 'siswa', 'siswa_induk', 'mutasi', 'kenaikan',
    'jam_kerja', 'hari_libur',
    'surat_masuk', 'surat_keluar', 'arsip', 'notulen', 'notulen_hadir', 'notulen_foto', 'notulen_ttd',
    'log_aktivitas',
];

/** Kolom wajib per tabel (untuk pemulihan diri bila penanda lebih baru daripada isi). */
const SCHEMA_KOLOM_WAJIB = [
    'users'         => ['username', 'password', 'role', 'aktif'],
    'guru'          => ['nama', 'nip', 'jabatan', 'tmt', 'keterangan'],
    'siswa'         => ['nis', 'nisn', 'nama', 'kelas_id', 'status', 'nama_ayah', 'nama_ibu'],
    'absensi_guru'  => ['guru_id', 'tanggal', 'status', 'kategori_masuk', 'kategori_pulang', 'menit_terlambat'],
    'surat_masuk'   => ['no_agenda', 'no_surat', 'berkas_url'],
    'surat_keluar'  => ['no_surat', 'status', 'verifikator', 'jenis', 'siswa_id', 'data'],
    'arsip'         => ['sumber', 'ref_id', 'status', 'berkas_url'],
    'notulen'       => ['tanggal', 'judul', 'keputusan'],
    'notulen_hadir' => ['notulen_id', 'nama', 'guru_id'],
    'notulen_foto'  => ['notulen_id', 'url', 'urutan'],
    'notulen_ttd'   => ['notulen_id', 'pihak', 'url'],
    'kenaikan'      => ['siswa_id', 'jenis', 'kelas_asal', 'kelas_tujuan', 'dibatalkan'],
    'jam_kerja'     => ['hari', 'masuk', 'pulang', 'libur'],
    'hari_libur'    => ['tanggal', 'keterangan'],
];

$pdo = null;

function db(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $baru = !is_file(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($baru) { @chmod(DB_FILE, 0666); }
    return $pdo;
}

function schema_penanda(): string
{
    $f = dirname(DB_FILE) . '/.schema_ok';
    return is_file($f) ? trim((string) @file_get_contents($f)) : '';
}

function skema_terkini(PDO $pdo): bool
{
    foreach (SCHEMA_TABEL_WAJIB as $tabel) {
        $ada = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $ada->execute([$tabel]);
        if ($ada->fetchColumn() === false) { return false; }
    }
    foreach (SCHEMA_KOLOM_WAJIB as $tabel => $kols) {
        $info = $pdo->query('PRAGMA table_info(' . $tabel . ')')->fetchAll(PDO::FETCH_ASSOC);
        $nama = array_column($info, 'name');
        foreach ((array) $kols as $kol) {
            if (!in_array($kol, $nama, true)) { return false; }
        }
    }
    return true;
}

/** Menambah kolom bila belum ada (idempoten) — dipakai migrasi skema. */
function db_add_column(PDO $pdo, string $tabel, string $kolom, string $definisi): void
{
    $info = $pdo->query('PRAGMA table_info(' . $tabel . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($info as $k) {
        if (strcasecmp((string) $k['name'], $kolom) === 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $tabel . ' ADD COLUMN ' . $kolom . ' ' . $definisi);
}

function ensure_schema(): void
{
    $pdo = db();
    $penanda = schema_penanda();
    if ($penanda === SCHEMA_VERSION && skema_terkini($pdo)) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    nama TEXT NOT NULL,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'kepala_tu',
    jabatan TEXT,
    telepon TEXT,
    foto TEXT,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT,
    terakhir TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pengaturan (
    kunci TEXT PRIMARY KEY,
    nilai TEXT NOT NULL DEFAULT ''
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS kelas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama TEXT NOT NULL,
    tingkat TEXT,
    wali TEXT,
    urutan INTEGER NOT NULL DEFAULT 0,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS guru (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nip TEXT,
    nuptk TEXT,
    nama TEXT NOT NULL,
    jenis_kelamin TEXT NOT NULL DEFAULT 'L',
    jabatan TEXT,
    mapel TEXT,
    email TEXT,
    telepon TEXT,
    alamat TEXT,
    tmt TEXT,
    status TEXT NOT NULL DEFAULT 'aktif',
    keterangan TEXT,
    foto TEXT,
    urutan INTEGER NOT NULL DEFAULT 0,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT,
    diubah TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS absensi_guru (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guru_id INTEGER NOT NULL REFERENCES guru(id) ON DELETE CASCADE,
    tanggal TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'H',
    jam_masuk TEXT,
    jam_keluar TEXT,
    kategori_masuk TEXT,
    kategori_pulang TEXT,
    menit_terlambat INTEGER NOT NULL DEFAULT 0,
    keterangan TEXT,
    dicatat_oleh TEXT,
    dibuat TEXT,
    UNIQUE (guru_id, tanggal)
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_absensi_tanggal ON absensi_guru (tanggal)');
        // v4: JAM KERJA PER HARI (1=Senin … 7=Minggu) + HARI LIBUR MANUAL.
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS jam_kerja (
    hari INTEGER PRIMARY KEY,
    masuk TEXT NOT NULL DEFAULT '07:30',
    pulang TEXT NOT NULL DEFAULT '14:00',
    libur INTEGER NOT NULL DEFAULT 0,
    diubah TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS hari_libur (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tanggal TEXT NOT NULL UNIQUE,
    keterangan TEXT,
    oleh TEXT,
    dibuat TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hari_libur_tgl ON hari_libur (tanggal)');
        // v3: kategori jam masuk/pulang dihitung saat menyimpan (jam kerja berlaku untuk semua guru).
        db_add_column($pdo, 'absensi_guru', 'kategori_masuk', 'TEXT');
        db_add_column($pdo, 'absensi_guru', 'kategori_pulang', 'TEXT');
        db_add_column($pdo, 'absensi_guru', 'menit_terlambat', 'INTEGER NOT NULL DEFAULT 0');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS siswa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nis TEXT,
    nisn TEXT,
    nama TEXT NOT NULL,
    jenis_kelamin TEXT NOT NULL DEFAULT 'L',
    kelas_id INTEGER REFERENCES kelas(id) ON DELETE SET NULL,
    tempat_lahir TEXT,
    tanggal_lahir TEXT,
    nama_ayah TEXT,
    nama_ibu TEXT,
    nama_wali TEXT,
    telepon TEXT,
    alamat TEXT,
    status TEXT NOT NULL DEFAULT 'aktif',
    tanggal_status TEXT,
    foto TEXT,
    aktif INTEGER NOT NULL DEFAULT 1,
    urutan INTEGER NOT NULL DEFAULT 0,
    dibuat TEXT,
    diubah TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_siswa_kelas ON siswa (kelas_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_siswa_nama ON siswa (nama)');
        // v8: BUKU INDUK SISWA (detail) — medan formulir buku induk yang tidak ada di tabel `siswa`.
        // Hanya MENAMBAH tabel baru; tabel lama tidak dibangun ulang dan datanya tidak berubah.
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS siswa_induk (
    siswa_id INTEGER PRIMARY KEY REFERENCES siswa(id) ON DELETE CASCADE,
    nik TEXT,
    agama TEXT,
    jumlah_saudara TEXT,
    provinsi TEXT,
    kabupaten TEXT,
    kecamatan TEXT,
    desa TEXT,
    kode_pos TEXT,
    jarak_rumah TEXT,
    asal_sekolah TEXT,
    tanggal_masuk TEXT,
    diterima_kelas TEXT,
    npsn_asal TEXT,
    no_blanko_skhu TEXT,
    no_ijazah_sd TEXT,
    total_nilai_un TEXT,
    tanggal_lulus_sd TEXT,
    no_peserta_un TEXT,
    ayah_kk TEXT,
    ayah_tanggal_lahir TEXT,
    ayah_nik TEXT,
    ayah_pendidikan TEXT,
    ayah_pekerjaan TEXT,
    ayah_status TEXT,
    ibu_tanggal_lahir TEXT,
    ibu_nik TEXT,
    ibu_pendidikan TEXT,
    ibu_pekerjaan TEXT,
    ibu_status TEXT,
    penghasilan_ortu TEXT,
    no_kks TEXT,
    no_pkh TEXT,
    no_kip TEXT,
    wali_tanggal_lahir TEXT,
    wali_nik TEXT,
    wali_pendidikan TEXT,
    wali_pekerjaan TEXT,
    wali_penghasilan TEXT,
    putus_alasan TEXT,
    putus_tanggal TEXT,
    pindah_ke TEXT,
    pindah_tanggal TEXT,
    lulus_tanggal TEXT,
    no_ijazah_lulus TEXT,
    no_shun TEXT,
    diubah TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS mutasi (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    jenis TEXT NOT NULL DEFAULT 'masuk',
    siswa_id INTEGER REFERENCES siswa(id) ON DELETE SET NULL,
    nis TEXT,
    nisn TEXT,
    nama TEXT NOT NULL,
    jenis_kelamin TEXT,
    kelas_asal TEXT,
    kelas_tujuan TEXT,
    tanggal TEXT NOT NULL,
    no_surat TEXT,
    asal_sekolah TEXT,
    alasan TEXT,
    keterangan TEXT,
    oleh TEXT,
    dibuat TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS surat_masuk (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    no_agenda TEXT NOT NULL,
    no_surat TEXT,
    tanggal_surat TEXT,
    tanggal_terima TEXT,
    pengirim TEXT,
    perihal TEXT,
    sifat TEXT NOT NULL DEFAULT 'biasa',
    lampiran TEXT,
    disposisi TEXT,
    keterangan TEXT,
    berkas_url TEXT,
    berkas_nama TEXT,
    berkas_ukuran INTEGER,
    oleh TEXT,
    dibuat TEXT,
    diubah TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_surat_masuk_tgl ON surat_masuk (tanggal_terima)');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS surat_keluar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    no_agenda TEXT NOT NULL,
    no_surat TEXT NOT NULL,
    jenis TEXT NOT NULL DEFAULT 'umum',
    siswa_id INTEGER,
    data TEXT,
    tanggal_surat TEXT,
    tujuan TEXT,
    perihal TEXT,
    sifat TEXT NOT NULL DEFAULT 'biasa',
    lampiran TEXT,
    isi TEXT,
    status TEXT NOT NULL DEFAULT 'draf',
    verifikator TEXT,
    verifikasi_pada TEXT,
    catatan_verifikasi TEXT,
    berkas_url TEXT,
    berkas_nama TEXT,
    berkas_ukuran INTEGER,
    oleh TEXT,
    dibuat TEXT,
    diubah TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_surat_keluar_tgl ON surat_keluar (tanggal_surat)');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS arsip (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sumber TEXT NOT NULL DEFAULT 'masuk',
    ref_id INTEGER,
    kode TEXT NOT NULL,
    judul TEXT,
    tanggal TEXT,
    status TEXT NOT NULL DEFAULT 'aktif',
    berkas_url TEXT,
    berkas_nama TEXT,
    berkas_ukuran INTEGER,
    keterangan TEXT,
    oleh TEXT,
    dibuat TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_arsip_status ON arsip (status)');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS notulen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tanggal TEXT NOT NULL,
    waktu TEXT,
    tempat TEXT,
    judul TEXT NOT NULL,
    agenda TEXT,
    pembahasan TEXT,
    keputusan TEXT,
    pimpinan TEXT,
    pimpinan_jabatan TEXT,
    notulis TEXT,
    dibuat TEXT,
    dibuat_oleh TEXT
)
SQL);
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS notulen_hadir (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notulen_id INTEGER NOT NULL REFERENCES notulen(id) ON DELETE CASCADE,
    guru_id INTEGER REFERENCES guru(id) ON DELETE SET NULL,
    nama TEXT NOT NULL,
    nip TEXT,
    jabatan TEXT,
    hadir INTEGER NOT NULL DEFAULT 1,
    urutan INTEGER NOT NULL DEFAULT 0
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notulen_hadir ON notulen_hadir (notulen_id)');
        // v5: peserta rapat ditautkan ke data guru (dipakai untuk mencentang ulang daftar hadir).
        db_add_column($pdo, 'notulen_hadir', 'guru_id', 'INTEGER');
        // v7: TANDA TANGAN DIGITAL (layar sentuh) pada lembar Daftar Hadir Rapat.
        // Satu baris per pihak: 'kepala', 'pimpinan', 'notulis', atau 'hadir:<id notulen_hadir>'.
        // Yang disimpan HANYA URL CDN hasil media proxy (bukan berkas di server aplikasi).
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS notulen_ttd (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notulen_id INTEGER NOT NULL REFERENCES notulen(id) ON DELETE CASCADE,
    pihak TEXT NOT NULL,
    url TEXT NOT NULL,
    lebar INTEGER NOT NULL DEFAULT 0,
    tinggi INTEGER NOT NULL DEFAULT 0,
    oleh TEXT,
    ip TEXT,
    dibuat TEXT,
    diubah TEXT,
    UNIQUE (notulen_id, pihak)
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notulen_ttd ON notulen_ttd (notulen_id)');
        // v5: FOTO BUKTI KEGIATAN RAPAT (maksimal NOTULEN_FOTO_MAKS = 2 per notulen).
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS notulen_foto (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notulen_id INTEGER NOT NULL REFERENCES notulen(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    keterangan TEXT,
    urutan INTEGER NOT NULL DEFAULT 0,
    dibuat TEXT,
    dibuat_oleh INTEGER
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notulen_foto ON notulen_foto (notulen_id)');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS kenaikan (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    siswa_id INTEGER REFERENCES siswa(id) ON DELETE SET NULL,
    nama TEXT NOT NULL,
    nis TEXT,
    jenis TEXT NOT NULL DEFAULT 'naik',
    kelas_asal TEXT,
    kelas_tujuan TEXT,
    ta TEXT,
    tanggal TEXT NOT NULL,
    oleh TEXT,
    dibatalkan INTEGER NOT NULL DEFAULT 0,
    dibatalkan_pada TEXT,
    dibuat TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_kenaikan_siswa ON kenaikan (siswa_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_kenaikan_jenis ON kenaikan (jenis, dibatalkan)');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS log_aktivitas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    nama TEXT,
    role TEXT,
    aktivitas TEXT NOT NULL,
    keterangan TEXT,
    ip TEXT,
    peramban TEXT,
    waktu TEXT
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_waktu ON log_aktivitas (waktu)');

        // v6: JENIS SURAT KELUAR + DATA TEMPLATE (Surat Rekomendasi Pindah, Surat Pindah,
        // Surat Lolos Butuh). Hanya MENAMBAH kolom — surat lama tetap berjenis 'umum'
        // sehingga isinya (isi teks bebas) tidak berubah sama sekali.
        db_add_column($pdo, 'surat_keluar', 'jenis', "TEXT NOT NULL DEFAULT 'umum'");
        db_add_column($pdo, 'surat_keluar', 'siswa_id', 'INTEGER');
        db_add_column($pdo, 'surat_keluar', 'data', 'TEXT');
        $pdo->exec("UPDATE surat_keluar SET jenis = 'umum' WHERE jenis IS NULL OR TRIM(jenis) = ''");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_surat_keluar_jenis ON surat_keluar (jenis)');
        // Nama yayasan/penyelenggara pada kop surat resmi (hanya diisi bila belum ada;
        // suntingan pengelola tidak pernah ditimpa).
        $simpKop = $pdo->prepare('INSERT OR IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)');
        $simpKop->execute(['yayasan', (string) YAYASAN_BAWAAN]);
        $simpKop->execute(['akreditasi', (string) AKREDITASI_BAWAAN]);

        db_seed($pdo);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        throw $ex;
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    @file_put_contents(dirname(DB_FILE) . '/.schema_ok', SCHEMA_VERSION);
}

/** Mengisi akun, identitas lembaga, dan data awal (SEKALI SAJA). */
function db_seed(PDO $pdo): void
{
    // ---- akun bawaan ----
    $adaUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($adaUser === 0) {
        $st = $pdo->prepare('INSERT INTO users (username, nama, password, role, jabatan, aktif, dibuat) VALUES (?,?,?,?,?,1,?)');
        $st->execute(['admin', 'Super Admin TUSmart', password_hash('admin123', PASSWORD_DEFAULT), 'super', 'Super Admin', date('Y-m-d H:i:s')]);
        $st->execute(['kepalatu', 'Kepala Tata Usaha', password_hash('kepalatu123', PASSWORD_DEFAULT), 'kepala_tu', 'Kepala Tata Usaha', date('Y-m-d H:i:s')]);
    }

    // ---- identitas lembaga (hanya kunci yang belum ada) ----
    $simp = $pdo->prepare('INSERT OR IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)');
    foreach (LEMBAGA_BAWAAN as $k => $v) {
        $simp->execute([$k, (string) $v]);
    }
    // Jalur berkas e-GURU untuk Inventaris Sarpras (tidak bisa ditulis di dalam const).
    $simp->execute(['sarpras_sumber', dirname(dirname(__DIR__)) . '/e-guru/data/eguru.sqlite']);
    // Jalur berkas e-PERPUS untuk Inventaris Perpustakaan.
    $simp->execute(['perpus_sumber', PERPUS_SUMBER_DEFAULT]);
    // Jalur berkas aplikasi ABSENSI GURU untuk menu Laporan Absensi-Guru (dibaca hanya-baca).
    $simp->execute(['absguru_sumber', ABSGURU_SUMBER_DEFAULT]);
    // Jam kerja (batas masuk & pulang) yang berlaku untuk SEMUA guru/tenaga kependidikan.
    $simp->execute(['jam_masuk_batas', JAM_MASUK_BAWAAN]);
    $simp->execute(['jam_pulang_batas', JAM_PULANG_BAWAAN]);

    // Jam kerja PER HARI — diisi sekali saja (INSERT OR IGNORE) agar suntingan tidak tertimpa.
    // Libur mingguan bawaan: MADRASAH = Jumat, sekolah umum = Minggu.
    $jenis = (string) $pdo->query("SELECT nilai FROM pengaturan WHERE kunci = 'jenis'")->fetchColumn();
    $liburMingguan = $jenis === 'sekolah' ? 7 : 5;
    $stJam = $pdo->prepare('INSERT OR IGNORE INTO jam_kerja (hari, masuk, pulang, libur, diubah) VALUES (?,?,?,?,?)');
    for ($h = 1; $h <= 7; $h++) {
        $stJam->execute([$h, JAM_MASUK_BAWAAN, JAM_PULANG_BAWAAN, $h === $liburMingguan ? 1 : 0, date('Y-m-d H:i:s')]);
    }

    // ---- data awal guru / kelas / siswa (dari web-sekolah & e-GURU) ----
    $bertanda = (string) $pdo->query("SELECT COALESCE((SELECT nilai FROM pengaturan WHERE kunci = 'seed_data'), '')")->fetchColumn();
    if ($bertanda === '') {
        if ((int) $pdo->query('SELECT COUNT(*) FROM kelas')->fetchColumn() === 0 && defined('SEED_KELAS')) {
            $st = $pdo->prepare('INSERT INTO kelas (nama, tingkat, urutan, aktif, dibuat) VALUES (?,?,?,1,?)');
            $u = 10;
            foreach (SEED_KELAS as $k) {
                $st->execute([$k['nama'], $k['tingkat'], $u, date('Y-m-d H:i:s')]);
                $u += 10;
            }
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM guru')->fetchColumn() === 0 && defined('SEED_GURU')) {
            $st = $pdo->prepare('INSERT INTO guru (nama, nip, jenis_kelamin, jabatan, mapel, email, telepon, tmt, keterangan, status, urutan, aktif, dibuat) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
            foreach (SEED_GURU as $g) {
                $st->execute([
                    $g['nama'], $g['nip'], $g['jenis_kelamin'], $g['jabatan'], $g['mapel'],
                    $g['email'], $g['telepon'], $g['tmt'], $g['keterangan'],
                    $g['status'] === 'nonaktif' ? 'nonaktif' : 'aktif', $g['urutan'], date('Y-m-d H:i:s'),
                ]);
            }
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM siswa')->fetchColumn() === 0 && defined('SEED_SISWA')) {
            $petaKelas = [];
            foreach ($pdo->query('SELECT id, nama FROM kelas')->fetchAll(PDO::FETCH_ASSOC) as $k) {
                $petaKelas[$k['nama']] = (int) $k['id'];
            }
            $st = $pdo->prepare('INSERT INTO siswa (nis, nisn, nama, jenis_kelamin, kelas_id, tempat_lahir, tanggal_lahir, nama_ayah, nama_ibu, nama_wali, telepon, alamat, status, aktif, dibuat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach (SEED_SISWA as $s) {
                $kid = $petaKelas[$s['kelas']] ?? null;
                $st->execute([
                    $s['nis'], $s['nisn'], $s['nama'], $s['jenis_kelamin'], $kid,
                    $s['tempat_lahir'], $s['tanggal_lahir'], $s['nama_ayah'], $s['nama_ibu'], $s['nama_wali'],
                    $s['telepon'], $s['alamat'], $s['status'] === 'aktif' ? 'aktif' : $s['status'],
                    $s['status'] === 'aktif' ? 1 : 0, date('Y-m-d H:i:s'),
                ]);
            }
        }
        $pdo->prepare("INSERT OR REPLACE INTO pengaturan (kunci, nilai) VALUES ('seed_data', ?)")->execute([date('Y-m-d H:i:s')]);
    }
}
