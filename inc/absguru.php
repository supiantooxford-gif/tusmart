<?php
/**
 * Laporan Absensi-Guru — dibaca LANGSUNG (hanya-baca) dari basis data aplikasi
 * **Absensi Guru** (absensi-guru/data/absensi.sqlite).
 *
 * Pola yang sama dengan modul Inventaris Sarpras (e-GURU) dan Inventaris Perpustakaan
 * (e-PERPUS): data TIDAK disalin. Setiap halaman dibuka, isinya mengikuti apa pun yang
 * diisi guru pada aplikasi Absensi Guru — jadi laporan "terkirim otomatis" tanpa proses
 * sinkronisasi apa pun. Koneksi dibuka + `PRAGMA query_only = 1` sehingga TUSmart
 * MUSTAHIL mengubah data absensi.
 *
 * Laporan yang disediakan = seluruh laporan aplikasi Absensi Guru:
 *   hari_ini, hari (per tanggal), bulan (rekap semua guru), guru (rincian per guru),
 *   matriks (harian satu bulan per guru), overtime, kunjungan.
 */
declare(strict_types=1);

/** Kandidat jalur basis data Absensi Guru (pengaturan lebih dulu, lalu lokasi standar). */
function absguru_kandidat(): array
{
    $akar = dirname(__DIR__);
    $daftar = [
        trim(setting('absguru_sumber')),
        $akar . '/../absensi-guru/data/absensi.sqlite',
        dirname($akar) . '/absensi-guru/data/absensi.sqlite',
    ];
    $keluar = [];
    foreach ($daftar as $p) {
        $p = (string) $p;
        if ($p !== '' && is_file($p) && is_readable($p) && !in_array($p, $keluar, true)) {
            $keluar[] = $p;
        }
    }
    return $keluar;
}

/** Koneksi BACA-SAJA ke basis data Absensi Guru (null bila tidak tersedia). */
function absguru_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $kandidat = absguru_kandidat();
    if (!$kandidat) {
        return $pdo = null;
    }
    try {
        $p = new PDO('sqlite:' . $kandidat[0], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p->exec('PRAGMA query_only = 1');
        $p->exec('PRAGMA busy_timeout = 3000');
        // Pastikan ini benar-benar basis data Absensi Guru (bukan berkas lain).
        $tabel = [];
        foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $r) {
            $tabel[(string) $r['name']] = true;
        }
        if (!isset($tabel['guru'], $tabel['absensi'])) {
            return $pdo = null;
        }
        return $pdo = $p;
    } catch (Throwable $ex) {
        return $pdo = null;
    }
}

function absguru_ada(): bool
{
    return absguru_db() !== null;
}

/** Jalur berkas sumber yang sedang dipakai (untuk keterangan di halaman). */
function absguru_sumber_aktif(): string
{
    $k = absguru_kandidat();
    return $k[0] ?? '';
}

/** Ambil semua baris (aman: kegagalan dibalas array kosong). */
function absguru_semua(string $sql, array $arg = []): array
{
    $p = absguru_db();
    if (!$p) {
        return [];
    }
    try {
        $st = $p->prepare($sql);
        $st->execute($arg);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        return [];
    }
}

/** Pengaturan aplikasi Absensi Guru (tabel `pengaturan` kunci/nilai). */
function absguru_pengaturan(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (absguru_semua('SELECT kunci, nilai FROM pengaturan') as $r) {
        $cache[(string) $r['kunci']] = (string) ($r['nilai'] ?? '');
    }
    return $cache;
}

function absguru_setting(string $kunci, string $bawaan = ''): string
{
    $p = absguru_pengaturan();
    $v = trim((string) ($p[$kunci] ?? ''));
    return $v !== '' ? $v : $bawaan;
}

/** Identitas lembaga menurut aplikasi Absensi Guru. */
function absguru_info(): array
{
    return [
        'nama'      => absguru_setting('nama', lembaga_nama()),
        'kepala'    => absguru_setting('kepala', kepala_lembaga()),
        'nip_kepala'=> absguru_setting('nip_kepala', nip_kepala()),
        'kecamatan' => absguru_setting('kecamatan'),
        'toleransi' => (int) absguru_setting('toleransi_terlambat', '0'),
    ];
}

/** Daftar laporan (tab) — sama dengan menu Laporan aplikasi Absensi Guru. */
function absguru_jenis(): array
{
    return [
        'hari_ini'  => 'Absensi Hari Ini',
        'hari'      => 'Laporan Per Hari',
        'bulan'     => 'Rekap Per Bulan (Semua Guru)',
        'guru'      => 'Per Bulan per Guru',
        'matriks'   => 'Matriks Harian per Guru',
        'overtime'  => 'Laporan Overtime',
        'kunjungan' => 'Laporan Kunjungan',
    ];
}

function absguru_jenis_sah(string $j): bool
{
    return array_key_exists($j, absguru_jenis());
}

/** Penyaring dari query string; bulan & tanggal tidak sah dikembalikan ke bawaan. */
function absguru_filter(): array
{
    $bulan = f('bulan');
    if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
        $bulan = date('Y-m');
    }
    $tanggal = f('tanggal');
    $tanggal = tanggal_sah($tanggal) ? $tanggal : tanggal_hari_ini();
    return [
        'bulan'   => $bulan,
        'tanggal' => $tanggal,
        'guru'    => max(0, idf('guru')),
    ];
}

function absguru_qs(array $f, array $tambah = []): string
{
    return http_build_query(array_filter(array_merge([
        'bulan'   => (string) ($f['bulan'] ?? ''),
        'tanggal' => (string) ($f['tanggal'] ?? ''),
        'guru'    => (int) ($f['guru'] ?? 0) > 0 ? (string) (int) $f['guru'] : '',
    ], $tambah), static fn($v) => (string) $v !== ''));
}

/** Guru aktif (nama, NIP, jabatan) dari aplikasi Absensi Guru. */
function absguru_guru(bool $aktifSaja = true): array
{
    return absguru_semua(
        'SELECT g.*, j.nama AS jabatan FROM guru g LEFT JOIN jabatan j ON j.id = g.jabatan_id'
        . ($aktifSaja ? ' WHERE g.aktif = 1' : '') . ' ORDER BY g.nama COLLATE NOCASE'
    );
}

function absguru_guru_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $r = absguru_semua(
        'SELECT g.*, j.nama AS jabatan FROM guru g LEFT JOIN jabatan j ON j.id = g.jabatan_id WHERE g.id = ?',
        [$id]
    );
    return $r[0] ?? null;
}

/** Jam kerja per hari (0 = Minggu … 6 = Sabtu). */
function absguru_jam_kerja(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (absguru_semua('SELECT * FROM jam_kerja') as $r) {
        $cache[(int) $r['hari']] = $r;
    }
    return $cache;
}

/** Hari libur manual pada satu rentang: [tanggal => keterangan]. */
function absguru_libur_peta(string $awal, string $akhir): array
{
    $peta = [];
    foreach (absguru_semua('SELECT tanggal, keterangan FROM libur WHERE tanggal BETWEEN ? AND ?', [$awal, $akhir]) as $r) {
        $peta[(string) $r['tanggal']] = (string) ($r['keterangan'] ?? '');
    }
    return $peta;
}

/** Absensi pada satu rentang: [guru_id][tanggal] => baris. */
function absguru_absensi_peta(string $awal, string $akhir, int $guruId = 0): array
{
    $w = ['tanggal BETWEEN ? AND ?'];
    $arg = [$awal, $akhir];
    if ($guruId > 0) {
        $w[] = 'guru_id = ?';
        $arg[] = $guruId;
    }
    $peta = [];
    foreach (absguru_semua('SELECT * FROM absensi WHERE ' . implode(' AND ', $w), $arg) as $a) {
        $peta[(int) $a['guru_id']][(string) $a['tanggal']] = $a;
    }
    return $peta;
}

/** Pengajuan izin/cuti/sakit yang DISETUJUI: [guru_id][tanggal] => jenis. */
function absguru_pengajuan_peta(string $awal, string $akhir): array
{
    $peta = [];
    foreach (absguru_semua("SELECT * FROM pengajuan WHERE status = 'disetujui' AND mulai <= ? AND selesai >= ?", [$akhir, $awal]) as $p) {
        $tsAwal = strtotime((string) $p['mulai']);
        $tsAkhir = strtotime((string) $p['selesai']);
        if ($tsAwal === false || $tsAkhir === false) {
            continue;
        }
        for ($ts = $tsAwal; $ts <= $tsAkhir && $ts <= strtotime($akhir); $ts += 86400) {
            $ymd = date('Y-m-d', $ts);
            if ($ymd >= $awal) {
                $peta[(int) $p['guru_id']][$ymd] = (string) $p['jenis'];
            }
        }
    }
    return $peta;
}

/** Konteks data untuk menghitung status (sekali muat, dipakai berkali-kali). */
function absguru_konteks(string $awal, string $akhir, int $guruId = 0): array
{
    return [
        'jam'   => absguru_jam_kerja(),
        'libur' => absguru_libur_peta($awal, $akhir),
        'absen' => absguru_absensi_peta($awal, $akhir, $guruId),
        'peng'  => absguru_pengajuan_peta($awal, $akhir),
    ];
}

/** "1 j 30 m" / "45 mnt" — sama dengan tampilan aplikasi Absensi Guru. */
function absguru_menit(int $menit): string
{
    $menit = max(0, $menit);
    $j = intdiv($menit, 60);
    $m = $menit % 60;
    if ($j > 0 && $m > 0) { return $j . ' j ' . $m . ' m'; }
    if ($j > 0) { return $j . ' jam'; }
    return $m . ' mnt';
}

/** Jam HH:MM dari kolom datetime. */
function absguru_jam(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '-';
    }
    $ts = strtotime($dt);
    return $ts === false ? '-' : date('H:i', $ts);
}

/** Jam HH:MM:SS (dipakai matriks). */
function absguru_jam_detik(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '-';
    }
    $ts = strtotime($dt);
    return $ts === false ? '-' : date('H:i:s', $ts);
}

/** Titik koordinat yang direkam saat guru absen (6 desimal). */
function absguru_koordinat($lat, $lng): string
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return '-';
    }
    return number_format((float) $lat, 6, '.', '') . ', ' . number_format((float) $lng, 6, '.', '');
}

/** Label bulan Indonesia + tahun dari 'YYYY-MM'. */
function absguru_bulan_label(string $bulan): string
{
    $b = bulan_id();
    return ($b[(int) substr($bulan, 5, 2)] ?? '-') . ' ' . substr($bulan, 0, 4);
}

/**
 * Status kehadiran satu guru pada satu tanggal — meniru aturan aplikasi Absensi Guru
 * (menu ini hanya MEMBACA, aturannya harus sama supaya angkanya cocok).
 *
 * @return array{kode:string,label:string,pil:string,absensi:?array,jenis:string}
 */
function absguru_status(array $k, int $gid, string $ymd): array
{
    $a = $k['absen'][$gid][$ymd] ?? null;
    $jenis = (string) ($k['peng'][$gid][$ymd] ?? '');
    $w = (int) date('w', strtotime($ymd));
    $jamHari = $k['jam'][$w] ?? null;
    $kerja = $jamHari !== null && (int) $jamHari['aktif'] === 1 && !isset($k['libur'][$ymd]);

    if ($a !== null && ($a['masuk_at'] ?? null) !== null) {
        $telat = (int) ($a['terlambat_menit'] ?? 0);
        return [
            'kode'    => $telat > 0 ? 'terlambat' : 'hadir',
            'label'   => $telat > 0 ? 'Terlambat ' . absguru_menit($telat) : 'Hadir',
            'pil'     => $telat > 0 ? 'pil-t' : 'pil-h',
            'absensi' => $a,
            'jenis'   => '',
        ];
    }
    if ($jenis !== '') {
        $label = ['izin' => 'Izin', 'cuti' => 'Cuti', 'sakit' => 'Sakit'][$jenis] ?? ucfirst($jenis);
        return ['kode' => $jenis, 'label' => $label, 'pil' => 'pil-i', 'absensi' => $a, 'jenis' => $jenis];
    }
    if (!$kerja) {
        $ket = $k['libur'][$ymd] ?? '';
        return [
            'kode' => 'libur',
            'label' => $ket !== '' ? 'Libur: ' . $ket : 'Libur',
            'pil' => 'pil-nonaktif',
            'absensi' => $a,
            'jenis' => '',
        ];
    }
    return ['kode' => 'alpha', 'label' => 'Belum absen', 'pil' => 'pil-a', 'absensi' => $a, 'jenis' => ''];
}

/** Keterangan kecil di bawah jam masuk (Tepat waktu / Telat). */
function absguru_ket_masuk(?array $a): string
{
    if ($a === null || ($a['masuk_at'] ?? null) === null) {
        return '';
    }
    return ((int) ($a['terlambat_menit'] ?? 0) > 0) ? 'Telat' : 'Tepat waktu';
}

/** Keterangan kecil di bawah jam pulang ("Cepat pulang"). */
function absguru_ket_pulang(?array $a): string
{
    if ($a === null || ($a['pulang_at'] ?? null) === null) {
        return '';
    }
    return ((int) ($a['pulang_cepat_menit'] ?? 0) > 0) ? 'Cepat pulang' : '';
}

/** Meta tanggal untuk identitas lembar (jam kerja & keterangan hari). */
function absguru_meta_tanggal(string $ymd): array
{
    $jam = absguru_jam_kerja();
    $w = (int) date('w', strtotime($ymd));
    $libur = absguru_libur_peta($ymd, $ymd);
    $j = $jam[$w] ?? null;
    $ket = 'Hari kerja';
    if (isset($libur[$ymd])) {
        $ket = 'Libur: ' . ($libur[$ymd] !== '' ? $libur[$ymd] : 'Libur');
    } elseif ($j === null || (int) $j['aktif'] !== 1) {
        $ket = 'Libur mingguan';
    }
    return [
        'Tanggal'    => tanggal_id($ymd, true),
        'Jam Kerja'  => ($j !== null && (int) $j['aktif'] === 1)
            ? substr((string) $j['masuk'], 0, 5) . ' – ' . substr((string) $j['pulang'], 0, 5) . ' WITA'
            : '-',
        'Keterangan' => $ket,
    ];
}

/**
 * Data laporan satu tanggal (tab "hari ini" & "per hari").
 *
 * @return array{rows:array,ringkas:array,meta:array}
 */
function absguru_hari_data(string $tanggal): array
{
    $guru = absguru_guru(true);
    $k = absguru_konteks($tanggal, $tanggal);
    $rows = [];
    $r = ['total' => count($guru), 'hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'cuti' => 0,
        'sakit' => 0, 'alpha' => 0, 'libur' => 0, 'pulang' => 0, 'overtime' => 0, 'cepat' => 0];
    foreach ($guru as $g) {
        $gid = (int) $g['id'];
        $st = absguru_status($k, $gid, $tanggal);
        $a = $st['absensi'];
        $rows[] = ['guru' => $g, 'status' => $st, 'absensi' => $a];
        switch ($st['kode']) {
            case 'hadir': $r['hadir']++; break;
            case 'terlambat': $r['hadir']++; $r['terlambat']++; break;
            case 'izin': $r['izin']++; break;
            case 'cuti': $r['cuti']++; break;
            case 'sakit': $r['sakit']++; break;
            case 'libur': $r['libur']++; break;
            default: $r['alpha']++;
        }
        if ($a !== null && ($a['pulang_at'] ?? null) !== null) { $r['pulang']++; }
        if ($a !== null && (int) ($a['pulang_cepat_menit'] ?? 0) > 0) { $r['cepat']++; }
        if ($a !== null) { $r['overtime'] += (int) ($a['overtime_menit'] ?? 0); }
    }
    return ['rows' => $rows, 'ringkas' => $r, 'meta' => absguru_meta_tanggal($tanggal)];
}

/**
 * Rekap kehadiran per guru pada satu bulan (logika sama dengan aplikasi Absensi Guru:
 * hanya hari kerja yang sudah lewat yang dihitung).
 *
 * @return array{rekap:array,total:array,awal:string,akhir:string,label:string}
 */
function absguru_bulan_rekap(string $bulan): array
{
    $awal = $bulan . '-01';
    $akhirBulan = date('Y-m-t', strtotime($awal));
    $hariIni = tanggal_hari_ini();
    $akhir = $akhirBulan > $hariIni ? $hariIni : $akhirBulan;
    $guru = absguru_guru(true);
    $k = absguru_konteks($awal, $akhirBulan);

    $rekap = [];
    $total = ['hari_kerja' => 0, 'hadir' => 0, 'terlambat' => 0, 'menit_terlambat' => 0,
        'overtime' => 0, 'izin' => 0, 'cuti' => 0, 'sakit' => 0, 'alpha' => 0];
    foreach ($guru as $g) {
        $gid = (int) $g['id'];
        $r = ['guru' => $g, 'hari_kerja' => 0, 'hadir' => 0, 'terlambat' => 0, 'menit_terlambat' => 0,
            'pulang_cepat' => 0, 'menit_pulang_cepat' => 0, 'overtime' => 0, 'izin' => 0, 'cuti' => 0,
            'sakit' => 0, 'alpha' => 0, 'belum_pulang' => 0];
        for ($ts = strtotime($awal); $ts !== false && $ts <= strtotime($akhir); $ts += 86400) {
            $ymd = date('Y-m-d', $ts);
            $w = (int) date('w', $ts);
            $jam = $k['jam'][$w] ?? null;
            if ($jam === null || (int) $jam['aktif'] !== 1 || isset($k['libur'][$ymd])) {
                continue;
            }
            $r['hari_kerja']++;
            $a = $k['absen'][$gid][$ymd] ?? null;
            $jenis = (string) ($k['peng'][$gid][$ymd] ?? '');
            if ($jenis !== '') {
                if ($jenis === 'izin') { $r['izin']++; }
                elseif ($jenis === 'cuti') { $r['cuti']++; }
                else { $r['sakit']++; }
                continue;
            }
            if ($a !== null && ($a['masuk_at'] ?? null) !== null) {
                $r['hadir']++;
                if ((int) ($a['terlambat_menit'] ?? 0) > 0) {
                    $r['terlambat']++;
                    $r['menit_terlambat'] += (int) $a['terlambat_menit'];
                }
                if (($a['pulang_at'] ?? null) === null) {
                    $r['belum_pulang']++;
                } else {
                    if ((int) ($a['pulang_cepat_menit'] ?? 0) > 0) {
                        $r['pulang_cepat']++;
                        $r['menit_pulang_cepat'] += (int) $a['pulang_cepat_menit'];
                    }
                    $r['overtime'] += (int) ($a['overtime_menit'] ?? 0);
                }
                continue;
            }
            $r['alpha']++;
        }
        $r['persen'] = $r['hari_kerja'] > 0 ? round($r['hadir'] / $r['hari_kerja'] * 100, 1) : 0.0;
        foreach ($total as $kk => $vv) { $total[$kk] += (int) $r[$kk]; }
        $rekap[$gid] = $r;
    }
    return [
        'rekap' => $rekap,
        'total' => $total,
        'awal'  => $awal,
        'akhir' => $akhir,
        'label' => absguru_bulan_label($bulan),
    ];
}

/** Rincian harian satu guru pada satu bulan (tab "Per Bulan per Guru"). */
function absguru_guru_bulan(string $bulan, int $guruId): array
{
    $guru = absguru_guru_by_id($guruId);
    $awal = $bulan . '-01';
    $akhirBulan = date('Y-m-t', strtotime($awal));
    $hariIni = tanggal_hari_ini();
    $akhir = $akhirBulan > $hariIni ? $hariIni : $akhirBulan;
    if ($guru === null) {
        return ['guru' => null, 'baris' => [], 'rekap' => null];
    }
    $k = absguru_konteks($awal, $akhirBulan, $guruId);
    $baris = [];
    for ($ts = strtotime($awal); $ts !== false && $ts <= strtotime($akhir); $ts += 86400) {
        $ymd = date('Y-m-d', $ts);
        $baris[] = ['tanggal' => $ymd, 'status' => absguru_status($k, $guruId, $ymd)];
    }
    $rekapBulan = absguru_bulan_rekap($bulan);
    return ['guru' => $guru, 'baris' => $baris, 'rekap' => $rekapBulan['rekap'][$guruId] ?? null];
}

/**
 * Matriks harian satu bulan: tiap guru DUA baris (Masuk & Pulang), kolom tanggal 1..31.
 * X = libur / bukan hari kerja · I/C/S = izin/cuti/sakit · A = tanpa keterangan.
 */
function absguru_matriks(string $bulan, int $guruId = 0): array
{
    $tahun = (int) substr($bulan, 0, 4);
    $bln = (int) substr($bulan, 5, 2);
    $jmlHari = (int) date('t', strtotime($bulan . '-01'));
    $awal = $bulan . '-01';
    $akhir = $bulan . '-' . str_pad((string) $jmlHari, 2, '0', STR_PAD_LEFT);
    $hariIni = tanggal_hari_ini();
    $k = absguru_konteks($awal, $akhir, $guruId);
    $guru = $guruId > 0 ? [absguru_guru_by_id($guruId)] : absguru_guru(true);
    $guru = array_values(array_filter($guru));

    $sel = static function (int $gid, string $ymd, string $mode) use ($k): array {
        $w = (int) date('w', strtotime($ymd));
        $jam = $k['jam'][$w] ?? null;
        $kerja = $jam !== null && (int) $jam['aktif'] === 1 && !isset($k['libur'][$ymd]);
        if (!$kerja) {
            return ['teks' => 'X', 'kelas' => 'mat-x', 'hitung' => '', 'judul' => 'Libur'];
        }
        $a = $k['absen'][$gid][$ymd] ?? null;
        $jenis = (string) ($k['peng'][$gid][$ymd] ?? '');
        if ($mode === 'masuk') {
            if ($a !== null && ($a['masuk_at'] ?? null) !== null) {
                $telat = (int) ($a['terlambat_menit'] ?? 0) > 0;
                return [
                    'teks' => absguru_jam_detik((string) $a['masuk_at']),
                    'kelas' => $telat ? 'mat-telat' : 'mat-masuk',
                    'hitung' => $telat ? 'T' : 'H',
                    'judul' => 'Masuk ' . tanggal_id($ymd) . ' ' . absguru_jam_detik((string) $a['masuk_at'])
                        . ($telat ? ' (terlambat ' . absguru_menit((int) $a['terlambat_menit']) . ')' : ' (tepat waktu)'),
                ];
            }
            if ($jenis !== '') {
                $huruf = $jenis === 'cuti' ? 'C' : ($jenis === 'sakit' ? 'S' : 'I');
                return ['teks' => $huruf, 'kelas' => 'mat-izin', 'hitung' => $huruf, 'judul' => ucfirst($jenis)];
            }
            return ['teks' => 'A', 'kelas' => 'mat-alpha', 'hitung' => 'A', 'judul' => 'Tanpa keterangan'];
        }
        if ($a !== null && ($a['pulang_at'] ?? null) !== null) {
            $cepat = (int) ($a['pulang_cepat_menit'] ?? 0) > 0;
            return [
                'teks' => absguru_jam_detik((string) $a['pulang_at']),
                'kelas' => $cepat ? 'mat-cepat' : 'mat-pulang',
                'hitung' => '',
                'judul' => 'Pulang ' . tanggal_id($ymd) . ' ' . absguru_jam_detik((string) $a['pulang_at'])
                    . ($cepat ? ' (cepat pulang ' . absguru_menit((int) $a['pulang_cepat_menit']) . ')' : ''),
            ];
        }
        if ($jenis !== '') {
            return ['teks' => '', 'kelas' => 'mat-izin', 'hitung' => '', 'judul' => ucfirst($jenis)];
        }
        return ['teks' => ($a !== null && ($a['masuk_at'] ?? null) !== null) ? '-' : '', 'kelas' => 'mat-kosong', 'hitung' => '', 'judul' => ''];
    };

    $baris = [];
    foreach ($guru as $g) {
        $gid = (int) $g['id'];
        $masuk = [];
        $pulang = [];
        $hitung = ['T' => 0, 'A' => 0, 'I' => 0, 'C' => 0, 'S' => 0, 'H' => 0, 'kerja' => 0];
        for ($d = 1; $d <= $jmlHari; $d++) {
            $ymd = $bulan . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            if ($ymd > $hariIni) {
                $masuk[$d] = ['teks' => '', 'kelas' => 'mat-kosong', 'hitung' => '', 'judul' => ''];
                $pulang[$d] = ['teks' => '', 'kelas' => 'mat-kosong', 'hitung' => '', 'judul' => ''];
                continue;
            }
            $m = $sel($gid, $ymd, 'masuk');
            $masuk[$d] = $m;
            $pulang[$d] = $sel($gid, $ymd, 'pulang');
            $isi = (string) $m['hitung'];
            if ($isi !== '') { $hitung[$isi] = ($hitung[$isi] ?? 0) + 1; }
            $w = (int) date('w', strtotime($ymd));
            $jam = $k['jam'][$w] ?? null;
            if ($jam !== null && (int) $jam['aktif'] === 1 && !isset($k['libur'][$ymd])) {
                $hitung['kerja']++;
            }
        }
        $baris[] = ['guru' => $g, 'masuk' => $masuk, 'pulang' => $pulang, 'hitung' => $hitung];
    }

    return [
        'bulan' => $bulan,
        'label' => absguru_bulan_label($bulan),
        'tahun' => $tahun,
        'bln' => $bln,
        'jml_hari' => $jmlHari,
        'guru' => $guru,
        'baris' => $baris,
        'hari_ini' => $hariIni,
    ];
}

/** Laporan overtime satu bulan: rekap per guru + rincian per hari. */
function absguru_overtime(string $bulan, int $guruId = 0): array
{
    $w = ['substr(a.tanggal,1,7) = ?', 'a.overtime_menit > 0'];
    $arg = [$bulan];
    if ($guruId > 0) {
        $w[] = 'a.guru_id = ?';
        $arg[] = $guruId;
    }
    $detail = absguru_semua(
        'SELECT a.*, g.nama, g.nip, j.nama AS jabatan, l.nama AS lokasi
         FROM absensi a JOIN guru g ON g.id = a.guru_id
         LEFT JOIN jabatan j ON j.id = g.jabatan_id
         LEFT JOIN lokasi l ON l.id = a.pulang_lokasi_id
         WHERE ' . implode(' AND ', $w) . '
         ORDER BY a.tanggal DESC, g.nama COLLATE NOCASE',
        $arg
    );
    $perGuru = [];
    foreach ($detail as $d) {
        $id = (int) $d['guru_id'];
        if (!isset($perGuru[$id])) {
            $perGuru[$id] = ['nama' => (string) $d['nama'], 'jabatan' => (string) ($d['jabatan'] ?? ''),
                'hari' => 0, 'menit' => 0];
        }
        $perGuru[$id]['hari']++;
        $perGuru[$id]['menit'] += (int) $d['overtime_menit'];
    }
    uasort($perGuru, static fn($a, $b) => $b['menit'] <=> $a['menit']);
    $total = 0;
    foreach ($perGuru as $p) { $total += (int) $p['menit']; }
    $guru = absguru_guru_by_id($guruId);
    return [
        'detail'  => $detail,
        'per_guru'=> $perGuru,
        'total'   => $total,
        'hari'    => (int) array_sum(array_column($perGuru, 'hari')),
        'label'   => absguru_bulan_label($bulan),
        'guru'    => $guru,
        'jam'     => absguru_jam_kerja(),
    ];
}

/** Laporan kunjungan dinas satu bulan. */
function absguru_kunjungan(string $bulan, int $guruId = 0): array
{
    $w = ['substr(k.tanggal,1,7) = ?'];
    $arg = [$bulan];
    if ($guruId > 0) {
        $w[] = 'k.guru_id = ?';
        $arg[] = $guruId;
    }
    $daftar = absguru_semua(
        'SELECT k.*, g.nama, j.nama AS jabatan FROM kunjungan k JOIN guru g ON g.id = k.guru_id
         LEFT JOIN jabatan j ON j.id = g.jabatan_id
         WHERE ' . implode(' AND ', $w) . ' ORDER BY k.tanggal DESC, k.mulai_at DESC',
        $arg
    );
    $selesai = 0;
    foreach ($daftar as $k) {
        if (($k['status'] ?? '') === 'selesai') { $selesai++; }
    }
    return [
        'daftar'  => $daftar,
        'jumlah'  => count($daftar),
        'selesai' => $selesai,
        'label'   => absguru_bulan_label($bulan),
        'guru'    => absguru_guru_by_id($guruId),
    ];
}

/** Angka ringkas untuk kartu di atas halaman. */
function absguru_ringkas(): array
{
    $hariIni = tanggal_hari_ini();
    $bulan = date('Y-m');
    $d = absguru_hari_data($hariIni);
    $kunjungan = absguru_semua('SELECT COUNT(*) AS n FROM kunjungan WHERE substr(tanggal,1,7) = ?', [$bulan]);
    $overtime = absguru_semua(
        'SELECT COUNT(*) AS n, COALESCE(SUM(overtime_menit),0) AS m FROM absensi WHERE substr(tanggal,1,7) = ?',
        [$bulan]
    );
    return [
        'guru'       => count(absguru_guru(true)),
        'hadir'      => $d['ringkas']['hadir'],
        'terlambat'  => $d['ringkas']['terlambat'],
        'izin'       => $d['ringkas']['izin'] + $d['ringkas']['cuti'] + $d['ringkas']['sakit'],
        'alpha'      => $d['ringkas']['alpha'],
        'kunjungan'  => (int) ($kunjungan[0]['n'] ?? 0),
        'overtime'   => (int) ($overtime[0]['m'] ?? 0),
        'overtime_n' => (int) ($overtime[0]['n'] ?? 0),
        'tanggal'    => $hariIni,
        'bulan'      => $bulan,
    ];
}

/* =====================================================================
   PENYUSUN LAPORAN (dipakai halaman layar & lembar cetak)
   Sel tabel boleh berupa teks biasa ("07:12\nTelat" = dua baris) atau
   array ['html' => …, 'teks' => …] untuk sel yang butuh HTML (mis. foto).
   ===================================================================== */

/** Label status => kelas pil TUSmart. */
function absguru_pil_kelas(string $label): string
{
    $l = strtolower($label);
    if (str_starts_with($l, 'terlambat')) { return 'pil-t'; }
    if ($l === 'hadir') { return 'pil-h'; }
    if (in_array($l, ['izin', 'cuti', 'sakit'], true)) { return 'pil-i'; }
    if (str_starts_with($l, 'libur')) { return 'pil-nonaktif'; }
    return 'pil-a';
}

/** Sel tabel → HTML (aman: seluruh teks di-escape). */
function absguru_sel_html($v): string
{
    if (is_array($v)) {
        return (string) ($v['html'] ?? e((string) ($v['teks'] ?? '')));
    }
    $v = (string) $v;
    $baris = explode("\n", $v);
    $out = e($baris[0]);
    for ($i = 1; $i < count($baris); $i++) {
        $ket = trim($baris[$i]);
        if ($ket === '') { continue; }
        $kelas = 'ket-waktu';
        $lk = strtolower($ket);
        if (str_starts_with($lk, 'telat')) { $kelas .= ' telat'; }
        elseif (str_starts_with($lk, 'cepat')) { $kelas .= ' cepat'; }
        $out .= '<br><span class="' . $kelas . '">' . e($ket) . '</span>';
    }
    return $out;
}

/** Nilai sel → teks biasa (untuk CSV / Excel). */
function absguru_sel_teks($v): string
{
    if (is_array($v)) {
        return (string) ($v['teks'] ?? '');
    }
    return str_replace("\n", ' ', (string) $v);
}

/**
 * Menyusun satu laporan lengkap (judul, kartu ringkas, tabel, meta).
 *
 * @return array{judul:string,berkas:string,meta:array,kartu:array,tabel:array,kosong:string,matriks:?array}
 */
function absguru_laporan(string $jenis, array $f): array
{
    $out = [
        'judul' => absguru_jenis()[$jenis] ?? 'Laporan Absensi Guru',
        'berkas' => 'laporan-absensi-guru-' . str_replace('_', '-', $jenis),
        'meta' => [],
        'kartu' => [],
        'tabel' => [],
        'kosong' => '',
        'matriks' => null,
    ];
    if (!absguru_ada()) {
        $out['kosong'] = 'Basis data aplikasi Absensi Guru belum terhubung.';
        return $out;
    }

    if ($jenis === 'hari_ini' || $jenis === 'hari') {
        $tanggal = (string) $f['tanggal'];
        $d = absguru_hari_data($tanggal);
        $r = $d['ringkas'];
        $rows = [];
        foreach ($d['rows'] as $i => $row) {
            $g = $row['guru'];
            $a = $row['absensi'];
            $foto = [];
            if ($a !== null && !empty($a['masuk_foto'])) {
                $foto[] = ['html' => '<a href="' . e((string) $a['masuk_foto']) . '" target="_blank" rel="noopener"><img class="foto-bukti" src="' . e((string) $a['masuk_foto']) . '" alt="Foto masuk"></a>',
                    'teks' => 'Ada'];
            }
            if ($a !== null && !empty($a['pulang_foto'])) {
                $foto[] = ['html' => '<a href="' . e((string) $a['pulang_foto']) . '" target="_blank" rel="noopener"><img class="foto-bukti" src="' . e((string) $a['pulang_foto']) . '" alt="Foto pulang"></a>',
                    'teks' => 'Ada'];
            }
            $rows[] = [
                (string) ($i + 1),
                (string) $g['nama'],
                (string) ($g['jabatan'] ?? '-'),
                $a !== null && ($a['masuk_at'] ?? null) !== null
                    ? absguru_jam((string) $a['masuk_at']) . (absguru_ket_masuk($a) !== '' ? "\n" . absguru_ket_masuk($a) : '')
                    : '-',
                $a !== null && ($a['pulang_at'] ?? null) !== null
                    ? absguru_jam((string) $a['pulang_at']) . (absguru_ket_pulang($a) !== '' ? "\n" . absguru_ket_pulang($a) : '')
                    : '-',
                $a !== null && (int) ($a['terlambat_menit'] ?? 0) > 0 ? absguru_menit((int) $a['terlambat_menit']) : '-',
                $a !== null && (int) ($a['pulang_cepat_menit'] ?? 0) > 0 ? absguru_menit((int) $a['pulang_cepat_menit']) : '-',
                $a !== null && (int) ($a['overtime_menit'] ?? 0) > 0 ? absguru_menit((int) $a['overtime_menit']) : '-',
                $row['status']['label'],
                ($a !== null && ($a['masuk_lat'] ?? null) !== null)
                    ? absguru_koordinat($a['masuk_lat'], $a['masuk_lng'])
                    : '-',
                $foto ? ['html' => '<div class="foto-baris">' . implode('', array_column($foto, 'html')) . '</div>',
                    'teks' => count($foto) . ' foto'] : '-',
            ];
        }
        $out['meta'] = $d['meta'];
        $out['meta']['Jumlah Guru'] = (string) $r['total'];
        $out['kartu'] = [
            ['Hadir', (string) $r['hadir'], 'terlambat ' . $r['terlambat'] . ' guru', 'hijau'],
            ['Izin / Cuti / Sakit', $r['izin'] . ' / ' . $r['cuti'] . ' / ' . $r['sakit'], 'pengajuan disetujui', 'biru'],
            ['Belum Absen', (string) $r['alpha'], 'hari kerja', 'merah'],
            ['Overtime', absguru_menit($r['overtime']), 'akumulasi tanggal ini', 'emas'],
        ];
        $out['tabel'] = [[
            'judul' => 'Absensi ' . ($jenis === 'hari_ini' ? 'Hari Ini' : 'Per Hari'),
            'kolom' => ['No', 'Nama Guru', 'Jabatan', 'Masuk', 'Pulang', 'Terlambat', 'Pulang Cepat', 'Overtime', 'Status', 'Titik Koordinat', 'Bukti Foto'],
            'align' => ['c', 'l', 'l', 'c', 'c', 'c', 'c', 'c', 'c', 'l', 'c'],
            'rows' => $rows,
            'pil' => [8],
            'foot' => ['Hadir ' . $r['hadir'] . ' (terlambat ' . $r['terlambat'] . ') · Izin ' . $r['izin']
                . ' · Cuti ' . $r['cuti'] . ' · Sakit ' . $r['sakit'] . ' · Belum absen ' . $r['alpha']
                . ' · Total guru ' . $r['total']],
            'foot_span' => 'all',
        ]];
        $out['kosong'] = 'Belum ada data guru aktif pada aplikasi Absensi Guru.';
        return $out;
    }

    if ($jenis === 'bulan') {
        $b = absguru_bulan_rekap((string) $f['bulan']);
        $rows = [];
        $no = 0;
        foreach ($b['rekap'] as $r) {
            $no++;
            $rows[] = [
                (string) $no, (string) $r['guru']['nama'], (string) ($r['guru']['jabatan'] ?? '-'),
                (string) (int) $r['hari_kerja'], (string) (int) $r['hadir'], (string) (int) $r['terlambat'],
                (string) (int) $r['menit_terlambat'], absguru_menit((int) $r['overtime']),
                (string) (int) $r['izin'], (string) (int) $r['cuti'], (string) (int) $r['sakit'],
                (string) (int) $r['alpha'], (string) $r['persen'] . '%',
            ];
        }
        $t = $b['total'];
        $out['meta'] = [
            'Periode' => $b['label'] . ' (' . tanggal_id($b['awal']) . ' – ' . tanggal_id($b['akhir']) . ')',
            'Jumlah Guru' => (string) count($b['rekap']),
        ];
        $out['kartu'] = [
            ['Hadir', (string) $t['hadir'], 'dari ' . $t['hari_kerja'] . ' hari kerja', 'hijau'],
            ['Terlambat', $t['terlambat'] . '×', absguru_menit((int) $t['menit_terlambat']), 'emas'],
            ['Overtime', absguru_menit((int) $t['overtime']), 'akumulasi', 'biru'],
            ['Izin / Cuti / Sakit', $t['izin'] . ' / ' . $t['cuti'] . ' / ' . $t['sakit'], 'Alpa: ' . $t['alpha'], 'merah'],
        ];
        $out['tabel'] = [[
            'judul' => 'Rekap Kehadiran ' . $b['label'],
            'kolom' => ['No', 'Nama Guru', 'Jabatan', 'Hari Kerja', 'Hadir', 'Terlambat', 'Menit Terlambat', 'Overtime', 'Izin', 'Cuti', 'Sakit', 'Alpa', '% Hadir'],
            'align' => ['c', 'l', 'l', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c', 'c'],
            'rows' => $rows,
            'foot' => ['Jumlah', (string) $t['hari_kerja'], (string) $t['hadir'], (string) $t['terlambat'],
                (string) $t['menit_terlambat'], absguru_menit((int) $t['overtime']), (string) $t['izin'],
                (string) $t['cuti'], (string) $t['sakit'], (string) $t['alpha'], ''],
            'foot_span' => 3,
        ]];
        $out['kosong'] = 'Belum ada guru aktif pada aplikasi Absensi Guru.';
        return $out;
    }

    if ($jenis === 'guru') {
        $gid = (int) $f['guru'];
        if ($gid <= 0) {
            $semua = absguru_guru(true);
            $gid = (int) ($semua[0]['id'] ?? 0);
        }
        $d = absguru_guru_bulan((string) $f['bulan'], $gid);
        if ($d['guru'] === null) {
            $out['kosong'] = 'Guru tidak ditemukan pada aplikasi Absensi Guru.';
            return $out;
        }
        $rows = [];
        foreach ($d['baris'] as $i => $b) {
            $a = $b['status']['absensi'];
            $rows[] = [
                (string) ($i + 1),
                tanggal_id((string) $b['tanggal'], true),
                $a !== null && ($a['masuk_at'] ?? null) !== null
                    ? absguru_jam((string) $a['masuk_at']) . (absguru_ket_masuk($a) !== '' ? "\n" . absguru_ket_masuk($a) : '')
                    : '-',
                $a !== null && ($a['pulang_at'] ?? null) !== null
                    ? absguru_jam((string) $a['pulang_at']) . (absguru_ket_pulang($a) !== '' ? "\n" . absguru_ket_pulang($a) : '')
                    : '-',
                $a !== null && (int) ($a['terlambat_menit'] ?? 0) > 0 ? absguru_menit((int) $a['terlambat_menit']) : '-',
                $a !== null && (int) ($a['pulang_cepat_menit'] ?? 0) > 0 ? absguru_menit((int) $a['pulang_cepat_menit']) : '-',
                $a !== null && (int) ($a['overtime_menit'] ?? 0) > 0 ? absguru_menit((int) $a['overtime_menit']) : '-',
                ($a !== null && ($a['masuk_lat'] ?? null) !== null) ? absguru_koordinat($a['masuk_lat'], $a['masuk_lng']) : '-',
                $b['status']['label'],
            ];
        }
        $r = $d['rekap'];
        $out['meta'] = [
            'Nama' => (string) $d['guru']['nama'],
            'Jabatan' => (string) ($d['guru']['jabatan'] ?? '-'),
            'NIP/NUPTK' => (string) (($d['guru']['nip'] ?? '') !== '' ? $d['guru']['nip'] : '-'),
            'Periode' => absguru_bulan_label((string) $f['bulan']),
        ];
        if ($r !== null) {
            $out['kartu'] = [
                ['Hadir', (string) $r['hadir'] . '/' . $r['hari_kerja'], 'hari kerja (' . $r['persen'] . '%)', 'hijau'],
                ['Terlambat', (string) $r['terlambat'] . '×', absguru_menit((int) $r['menit_terlambat']), 'emas'],
                ['Overtime', absguru_menit((int) $r['overtime']), 'akumulasi', 'biru'],
                ['Izin / Cuti / Sakit', $r['izin'] . ' / ' . $r['cuti'] . ' / ' . $r['sakit'], 'Alpa: ' . $r['alpha'], 'merah'],
            ];
        }
        $tabel = [[
            'judul' => 'Rincian Harian — ' . $d['guru']['nama'],
            'kolom' => ['No', 'Hari / Tanggal', 'Masuk', 'Pulang', 'Terlambat', 'Pulang Cepat', 'Overtime', 'Titik Koordinat', 'Status'],
            'align' => ['c', 'l', 'c', 'c', 'c', 'c', 'c', 'l', 'c'],
            'rows' => $rows,
            'pil' => [8],
        ]];
        if ($r !== null) {
            $tabel[0]['catatan'] = 'Rekap: hadir ' . (int) $r['hadir'] . '/' . (int) $r['hari_kerja'] . ' hari kerja ('
                . $r['persen'] . '%) · terlambat ' . (int) $r['terlambat'] . '× (' . absguru_menit((int) $r['menit_terlambat'])
                . ') · pulang cepat ' . (int) $r['pulang_cepat'] . '× (' . absguru_menit((int) $r['menit_pulang_cepat'])
                . ') · overtime ' . absguru_menit((int) $r['overtime']) . ' · izin ' . (int) $r['izin']
                . ' · cuti ' . (int) $r['cuti'] . ' · sakit ' . (int) $r['sakit'] . ' · alpa ' . (int) $r['alpha'] . '.';
        }
        $out['tabel'] = $tabel;
        $out['kosong'] = 'Belum ada catatan pada bulan ini.';
        return $out;
    }

    if ($jenis === 'matriks') {
        $gid = (int) $f['guru'];
        $m = absguru_matriks((string) $f['bulan'], $gid);
        $out['matriks'] = $m;
        $out['meta'] = [
            'Bulan' => $m['label'],
            'Guru' => $gid > 0 && $m['guru'] ? (string) $m['guru'][0]['nama'] : 'Semua guru (' . count($m['guru']) . ' orang)',
            'Jumlah Hari' => $m['jml_hari'] . ' hari',
        ];
        $out['kosong'] = 'Belum ada data guru aktif pada aplikasi Absensi Guru.';
        return $out;
    }

    if ($jenis === 'overtime') {
        $o = absguru_overtime((string) $f['bulan'], (int) $f['guru']);
        $rekap = [];
        $no = 0;
        foreach ($o['per_guru'] as $p) {
            $no++;
            $rekap[] = [(string) $no, $p['nama'], $p['jabatan'] !== '' ? $p['jabatan'] : '-',
                (string) (int) $p['hari'], absguru_menit((int) $p['menit'])];
        }
        $detail = [];
        foreach ($o['detail'] as $i => $d) {
            $jam = $o['jam'][(int) date('w', strtotime((string) $d['tanggal']))] ?? null;
            $detail[] = [
                (string) ($i + 1), tanggal_id((string) $d['tanggal'], true), (string) $d['nama'],
                absguru_jam((string) ($d['masuk_at'] ?? '')), absguru_jam((string) ($d['pulang_at'] ?? '')),
                ($jam !== null && (int) $jam['aktif'] === 1
                    ? substr((string) $jam['masuk'], 0, 5) . '–' . substr((string) $jam['pulang'], 0, 5) : '-'),
                absguru_menit((int) $d['overtime_menit']), (string) (($d['lokasi'] ?? '') !== '' ? $d['lokasi'] : '-'),
            ];
        }
        $out['meta'] = [
            'Periode' => $o['label'],
            'Guru' => $o['guru'] !== null ? (string) $o['guru']['nama'] : 'Semua guru',
            'Total Overtime' => absguru_menit((int) $o['total']),
        ];
        $out['kartu'] = [
            ['Total Overtime', absguru_menit((int) $o['total']), 'periode ' . $o['label'], 'biru'],
            ['Guru Overtime', (string) count($o['per_guru']), 'guru dengan overtime', 'hijau'],
            ['Hari Overtime', (string) (int) $o['hari'], 'total catatan', 'emas'],
        ];
        $out['tabel'] = [
            [
                'judul' => 'Rekap Overtime per Guru',
                'kolom' => ['No', 'Nama Guru', 'Jabatan', 'Jumlah Hari', 'Akumulasi Overtime'],
                'align' => ['c', 'l', 'l', 'c', 'c'],
                'rows' => $rekap,
                'foot' => ['Total', absguru_menit((int) $o['total'])],
                'foot_span' => 4,
                'kosong' => 'Tidak ada overtime pada periode ini.',
            ],
            [
                'judul' => 'Rincian Overtime per Hari',
                'kolom' => ['No', 'Tanggal', 'Nama Guru', 'Masuk', 'Pulang', 'Jam Kerja', 'Overtime', 'Lokasi Pulang'],
                'align' => ['c', 'l', 'l', 'c', 'c', 'c', 'c', 'l'],
                'rows' => $detail,
                'kosong' => 'Belum ada catatan overtime.',
            ],
        ];
        $out['kosong'] = 'Tidak ada overtime pada periode ini.';
        return $out;
    }

    // kunjungan
    $k = absguru_kunjungan((string) $f['bulan'], (int) $f['guru']);
    $rows = [];
    foreach ($k['daftar'] as $i => $r) {
        $rows[] = [
            (string) ($i + 1), tanggal_id((string) $r['tanggal'], true), (string) $r['nama'],
            (string) $r['tujuan'], (string) (($r['alamat'] ?? '') !== '' ? $r['alamat'] : '-'),
            (string) (($r['keperluan'] ?? '') !== '' ? $r['keperluan'] : '-'),
            absguru_jam((string) ($r['mulai_at'] ?? '')),
            ($r['selesai_at'] ?? null) !== null ? absguru_jam((string) $r['selesai_at']) : '-',
            ($r['status'] ?? '') === 'selesai' ? 'Selesai' : 'Berlangsung',
        ];
    }
    $out['meta'] = [
        'Periode' => $k['label'],
        'Guru' => $k['guru'] !== null ? (string) $k['guru']['nama'] : 'Semua guru',
        'Jumlah Kunjungan' => (string) $k['jumlah'] . ' (' . $k['selesai'] . ' selesai)',
    ];
    $out['kartu'] = [
        ['Jumlah Kunjungan', (string) $k['jumlah'], 'periode ' . $k['label'], 'biru'],
        ['Selesai', (string) $k['selesai'], 'kunjungan tuntas', 'hijau'],
        ['Berlangsung', (string) max(0, $k['jumlah'] - $k['selesai']), 'belum selesai', 'emas'],
    ];
    $out['tabel'] = [[
        'judul' => 'Daftar Kunjungan Dinas ' . $k['label'],
        'kolom' => ['No', 'Tanggal', 'Nama Guru', 'Tujuan', 'Alamat', 'Keperluan', 'Mulai', 'Selesai', 'Status'],
        'align' => ['c', 'l', 'l', 'l', 'l', 'l', 'c', 'c', 'c'],
        'rows' => $rows,
        'pil' => [8],
        'kosong' => 'Tidak ada kunjungan pada periode ini.',
    ]];
    $out['kosong'] = 'Tidak ada kunjungan pada periode ini.';
    return $out;
}

/** Satu tabel laporan → HTML (dipakai layar & lembar cetak). */
function absguru_tabel_html(array $t): string
{
    $kolom = $t['kolom'] ?? [];
    $align = $t['align'] ?? [];
    $rows = $t['rows'] ?? [];
    $pil = array_map('intval', (array) ($t['pil'] ?? []));
    $kelas = 'tbl' . (!empty($t['padat']) ? ' padat' : '');
    $out = '';
    if (!empty($t['judul'])) {
        $out .= '<h3 class="tabel-judul">' . e((string) $t['judul']) . '</h3>';
    }
    if (!$rows) {
        $out .= '<div class="kosong">' . e((string) ($t['kosong'] ?? 'Belum ada data.')) . '</div>';
        return $out;
    }
    $out .= '<div class="tabel-bungkus"><table class="' . $kelas . '"><thead><tr>';
    foreach ($kolom as $i => $judul) {
        $a = $align[$i] ?? 'l';
        $out .= '<th class="' . ($a === 'c' ? 'center' : ($a === 'r' ? 'kanan' : '')) . '">' . e((string) $judul) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach (array_values($row) as $i => $sel) {
            $a = $align[$i] ?? 'l';
            $isi = absguru_sel_html($sel);
            if (in_array($i, $pil, true) && !is_array($sel)) {
                $teks = explode("\n", (string) $sel)[0];
                $isi = '<span class="pil ' . absguru_pil_kelas($teks) . '">' . e($teks) . '</span>';
            }
            $out .= '<td class="' . ($a === 'c' ? 'center' : ($a === 'r' ? 'kanan' : '')) . '">' . $isi . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody>';
    if (!empty($t['foot'])) {
        $foot = array_values((array) $t['foot']);
        $span = $t['foot_span'] ?? 1;
        $out .= '<tfoot><tr>';
        if ($span === 'all') {
            $out .= '<td colspan="' . count($kolom) . '">' . absguru_sel_html($foot[0] ?? '') . '</td>';
        } else {
            $span = (int) $span;
            if ($span > 0) {
                $label = array_shift($foot);
                $out .= '<td colspan="' . $span . '">' . absguru_sel_html($label ?? '') . '</td>';
            }
            foreach ($foot as $sel) {
                $out .= '<td class="center">' . absguru_sel_html($sel) . '</td>';
            }
            // Lengkapi sel kosong supaya jumlah kolom baris jumlah tetap tepat.
            for ($i = count($kolom) - $span - count($foot); $i > 0; $i--) {
                $out .= '<td></td>';
            }
        }
        $out .= '</tr></tfoot>';
    }
    $out .= '</table></div>';
    // Catatan rekap dicetak SESUDAH tabel (jangan di dalam <table>).
    if (!empty($t['catatan'])) {
        $out .= '<p class="kecil mut mt8 mb0">' . e((string) $t['catatan']) . '</p>';
    }
    return $out;
}

/** Header + baris untuk ekspor Excel/CSV dari tabel pertama laporan. */
function absguru_ekspor(array $L): array
{
    $t = $L['tabel'][0] ?? [];
    $rows = [];
    foreach (($t['rows'] ?? []) as $row) {
        $rows[] = array_map(static fn($v) => absguru_sel_teks($v), array_values($row));
    }
    return [
        'judul' => (string) ($L['judul'] ?? 'Laporan'),
        'sheet' => substr((string) ($L['judul'] ?? 'Laporan'), 0, 28),
        'berkas' => (string) ($L['berkas'] ?? 'laporan') . '.csv',
        'header' => array_values($t['kolom'] ?? []),
        'rows' => $rows,
        'meta' => $L['meta'] ?? [],
    ];
}

/**
 * Tabel matriks harian (tiap guru dua baris: Masuk & Pulang) → HTML.
 * Dipakai halaman layar dan lembar cetak (A4 melintang).
 */
function absguru_matriks_html(array $m): string
{
    $hari = nama_hari();
    $jml = (int) $m['jml_hari'];
    $bln = (int) $m['bln'];
    $tahun = (int) $m['tahun'];
    $out = '<div class="tabel-bungkus matriks-wrap"><table class="tbl matriks-absguru"><thead>';
    $out .= '<tr><th class="k-no" rowspan="2">No</th><th class="k-nama" rowspan="2">Nama Guru</th>';
    for ($d = 1; $d <= $jml; $d++) {
        $w = (int) date('w', strtotime(sprintf('%04d-%02d-%02d', $tahun, $bln, $d)));
        $out .= '<th class="k-hari' . ($w === 0 ? ' libur' : '') . '">' . $d . '</th>';
    }
    $out .= '<th colspan="5" class="k-ket">Keterangan</th></tr><tr>';
    for ($d = 1; $d <= $jml; $d++) {
        $ts = strtotime(sprintf('%04d-%02d-%02d', $tahun, $bln, $d));
        $w = (int) date('w', $ts);
        $out .= '<th class="k-sub' . ($w === 0 ? ' libur' : '') . '">' . e(substr($hari[$w === 0 ? 7 : $w] ?? '', 0, 3)) . '</th>';
    }
    foreach (['T', 'A', 'I', 'C', 'S'] as $kode) {
        $out .= '<th class="k-ket">' . $kode . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    $no = 0;
    foreach ($m['baris'] as $b) {
        $no++;
        $out .= '<tr class="baris-masuk"><td class="k-no" rowspan="2">' . $no . '</td>';
        $out .= '<td class="k-nama" rowspan="2">' . e((string) $b['guru']['nama']) . '<br><span class="mut kecil">Masuk / Pulang</span></td>';
        for ($d = 1; $d <= $jml; $d++) {
            $s = $b['masuk'][$d] ?? ['teks' => '', 'kelas' => 'mat-kosong', 'judul' => ''];
            $out .= '<td class="sel-masuk ' . e($s['kelas']) . '" title="' . e((string) ($s['judul'] ?? '')) . '">' . e((string) $s['teks']) . '</td>';
        }
        foreach (['T', 'A', 'I', 'C', 'S'] as $kode) {
            $n = (int) ($b['hitung'][$kode] ?? 0);
            $out .= '<td class="k-ket center">' . ($n > 0 ? $n : '') . '</td>';
        }
        $out .= '</tr><tr class="baris-pulang">';
        for ($d = 1; $d <= $jml; $d++) {
            $s = $b['pulang'][$d] ?? ['teks' => '', 'kelas' => 'mat-kosong', 'judul' => ''];
            $out .= '<td class="sel-pulang ' . e($s['kelas']) . '" title="' . e((string) ($s['judul'] ?? '')) . '">' . e((string) $s['teks']) . '</td>';
        }
        $out .= '<td class="k-ket" colspan="5"></td></tr>';
    }
    $out .= '</tbody></table></div>';
    $out .= '<p class="kecil mut matriks-legend">Keterangan: <b>X</b> = hari libur / bukan hari kerja · '
        . '<b>A</b> = tanpa keterangan · <b>I</b> = izin · <b>C</b> = cuti · <b>S</b> = sakit · '
        . 'jam merah = terlambat (Masuk) atau pulang lebih awal (Pulang).</p>';
    return $out;
}
