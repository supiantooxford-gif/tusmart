<?php
/**
 * BUKU INDUK SISWA — definisi medan, penyimpanan, dan penampilan nilainya.
 *
 * Medan buku induk yang tidak dipakai formulir lama disimpan pada tabel `siswa_induk`
 * (SATU baris per siswa, ON DELETE CASCADE) supaya tabel `siswa` tetap ramping dan
 * halaman lain tidak perlu diubah. Identitas inti (nama, NIS, NISN, tempat/tanggal lahir,
 * jenis kelamin, alamat, ayah/ibu/wali) TETAP di tabel `siswa`; metadata di bawah menandai
 * sumber tiap medan lewat kunci 'sumber' ('siswa' atau 'induk').
 *
 * 🚨 SATU definisi ini dipakai BERSAMA oleh formulir Data Siswa (siswa_edit.php) dan lembar
 * cetak Buku Induk per siswa (cetak_buku_induk_siswa.php) — label, urutan, dan susunan bagian
 * karenanya tidak mungkin berbeda antara isian dan hasil cetak. Menambah medan cukup di sini.
 */
declare(strict_types=1);

/**
 * Susunan bagian buku induk (A–F) + bagian administrasi (formulir saja).
 *
 * Tiap baris: ['k' => nama kolom/form, 'label' => …, 'no' => penomoran, 'tipe' => teks|tanggal|
 * pilih|tekspanjang|angka|kelas|status|foto, 'pilihan' => [nilai => label], 'sumber' => siswa|induk,
 * 'maks' => panjang maksimum, 'lebar' => jumlah kolom grid, 'wajib' => bool,
 * 'cetak' => false bila tidak dicetak pada lembar buku induk].
 * Baris ber-'sub' menjadi judul sub-bagian (mis. "1) Keterangan Ayah").
 */
function siswa_medan_grup(): array
{
    $jk = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

    return [
        'ADM' => [
            'judul' => 'Keterangan Administrasi',
            'kolom' => 'f3',
            'cetak' => false,
            'baris' => [
                ['k' => 'nis', 'label' => 'NIS / NIS Lokal', 'maks' => 30, 'sumber' => 'siswa'],
                ['k' => 'nisn', 'label' => 'NISN', 'maks' => 30, 'sumber' => 'siswa'],
                ['k' => 'nik', 'label' => 'NIK Siswa', 'maks' => 30, 'sumber' => 'induk'],
                ['k' => 'kelas_id', 'label' => 'Kelas', 'tipe' => 'kelas', 'sumber' => 'siswa'],
                ['k' => 'status', 'label' => 'Status Siswa', 'tipe' => 'status', 'sumber' => 'siswa'],
                ['k' => 'telepon', 'label' => 'Telepon / WhatsApp', 'maks' => 40, 'sumber' => 'siswa'],
                ['k' => 'foto', 'label' => 'Pas Foto 3 x 4', 'tipe' => 'foto', 'sumber' => 'siswa', 'lebar' => 2],
            ],
        ],

        'A' => [
            'judul' => 'Identitas Siswa',
            'kolom' => 'f3',
            'baris' => [
                ['k' => 'nama', 'label' => 'Nama Siswa', 'no' => '1.', 'sumber' => 'siswa', 'wajib' => true, 'maks' => 150, 'lebar' => 2],
                ['k' => 'tempat_lahir', 'label' => 'Tempat Lahir', 'no' => '2.', 'sumber' => 'siswa', 'maks' => 80],
                ['k' => 'tanggal_lahir', 'label' => 'Tanggal Lahir', 'no' => '3.', 'sumber' => 'siswa', 'tipe' => 'tanggal'],
                ['k' => 'jenis_kelamin', 'label' => 'Jenis Kelamin', 'no' => '4.', 'sumber' => 'siswa', 'tipe' => 'pilih', 'pilihan' => $jk],
                ['k' => 'agama', 'label' => 'Agama Siswa', 'no' => '5.', 'sumber' => 'induk', 'tipe' => 'pilih',
                    'pilihan' => ['Islam' => 'Islam', 'Kristen' => 'Kristen', 'Katolik' => 'Katolik', 'Hindu' => 'Hindu', 'Buddha' => 'Buddha', 'Konghucu' => 'Konghucu']],
                ['k' => 'jumlah_saudara', 'label' => 'Jumlah Saudara', 'no' => '6.', 'sumber' => 'induk', 'maks' => 10],
                ['k' => 'alamat', 'label' => 'Alamat', 'no' => '7.', 'sumber' => 'siswa', 'tipe' => 'tekspanjang', 'maks' => 300, 'lebar' => 3],
                ['k' => 'provinsi', 'label' => 'Provinsi', 'no' => '›', 'sumber' => 'induk', 'maks' => 80],
                ['k' => 'kabupaten', 'label' => 'Kab./Kota', 'no' => '›', 'sumber' => 'induk', 'maks' => 80],
                ['k' => 'kecamatan', 'label' => 'Kecamatan', 'no' => '›', 'sumber' => 'induk', 'maks' => 80],
                ['k' => 'desa', 'label' => 'Desa/Kelurahan', 'no' => '›', 'sumber' => 'induk', 'maks' => 80],
                ['k' => 'kode_pos', 'label' => 'Kode Pos', 'no' => '›', 'sumber' => 'induk', 'maks' => 10],
                ['k' => 'jarak_rumah', 'label' => 'Jarak Rumah Siswa Ke Madrasah', 'no' => '›', 'sumber' => 'induk', 'maks' => 30, 'contoh' => 'mis. < 3 Km'],
            ],
        ],

        'B' => [
            'judul' => 'Keterangan Sekolah Asal',
            'kolom' => 'f3',
            'baris' => [
                ['k' => 'asal_sekolah', 'label' => 'Nama Sekolah Asal', 'no' => '1.', 'sumber' => 'induk', 'maks' => 150, 'lebar' => 2],
                ['k' => 'tanggal_masuk', 'label' => 'Tanggal Masuk Ke Sekolah ini', 'no' => '2.', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'diterima_kelas', 'label' => 'DiTerima di Kelas', 'no' => '3.', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'npsn_asal', 'label' => 'NPSN Sekolah', 'no' => '4.', 'sumber' => 'induk', 'maks' => 20],
                ['k' => 'no_blanko_skhu', 'label' => 'Nomor Blanko SKHU SD/MI', 'no' => '5.', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'no_ijazah_sd', 'label' => 'Nomor Seri Ijazah SD/MI', 'no' => '6.', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'total_nilai_un', 'label' => 'Total Nilai UN', 'no' => '7.', 'sumber' => 'induk', 'maks' => 20],
                ['k' => 'tanggal_lulus_sd', 'label' => 'Tanggal Kelulusan', 'no' => '8.', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'no_peserta_un', 'label' => 'Nomor Peserta UN SD/MI', 'no' => '9.', 'sumber' => 'induk', 'maks' => 60, 'lebar' => 2],
            ],
        ],

        'C' => [
            'judul' => 'Identitas Orang Tua',
            'kolom' => 'f3',
            'baris' => [
                ['sub' => '1) Keterangan Ayah'],
                ['k' => 'ayah_kk', 'label' => 'No. Kartu Keluarga', 'no' => '›', 'sumber' => 'induk', 'maks' => 30],
                ['k' => 'nama_ayah', 'label' => 'Nama Ayah', 'no' => '›', 'sumber' => 'siswa', 'maks' => 120],
                ['k' => 'ayah_tanggal_lahir', 'label' => 'Tanggal Lahir', 'no' => '›', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'ayah_nik', 'label' => 'NIK/Nomor KTP', 'no' => '›', 'sumber' => 'induk', 'maks' => 30],
                ['k' => 'ayah_pendidikan', 'label' => 'Pendidikan', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'ayah_pekerjaan', 'label' => 'Pekerjaan', 'no' => '›', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'ayah_status', 'label' => 'Status', 'no' => '›', 'sumber' => 'induk', 'maks' => 40, 'contoh' => 'mis. Kepala Keluarga'],
                ['sub' => '2) Keterangan Ibu'],
                ['k' => 'nama_ibu', 'label' => 'Nama Ibu', 'no' => '›', 'sumber' => 'siswa', 'maks' => 120],
                ['k' => 'ibu_tanggal_lahir', 'label' => 'Tanggal Lahir', 'no' => '›', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'ibu_nik', 'label' => 'NIK/Nomor KTP', 'no' => '›', 'sumber' => 'induk', 'maks' => 30],
                ['k' => 'ibu_pendidikan', 'label' => 'Pendidikan', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'ibu_pekerjaan', 'label' => 'Pekerjaan', 'no' => '›', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'ibu_status', 'label' => 'Status', 'no' => '›', 'sumber' => 'induk', 'maks' => 40, 'contoh' => 'mis. Ibu Rumah Tangga'],
                ['k' => 'penghasilan_ortu', 'label' => '3) Rata-Rata Penghasilan Orang Tua', 'no' => '›', 'sumber' => 'induk', 'maks' => 40, 'lebar' => 2],
            ],
        ],

        'D' => [
            'judul' => 'Kartu Bantuan Pemerintah',
            'kolom' => 'f3',
            'baris' => [
                ['k' => 'no_kks', 'label' => 'Nomor KKS/KPS', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'no_pkh', 'label' => 'Nomor Kartu PKH', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'no_kip', 'label' => 'Nomor KIP', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
            ],
        ],

        'E' => [
            'judul' => 'Keterangan Wali Siswa',
            'kolom' => 'f3',
            'baris' => [
                ['k' => 'nama_wali', 'label' => 'Nama Wali', 'no' => '›', 'sumber' => 'siswa', 'maks' => 120],
                ['k' => 'wali_tanggal_lahir', 'label' => 'Tanggal Lahir', 'no' => '›', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'wali_nik', 'label' => 'NIK/Nomor KTP', 'no' => '›', 'sumber' => 'induk', 'maks' => 30],
                ['k' => 'wali_pendidikan', 'label' => 'Pendidikan', 'no' => '›', 'sumber' => 'induk', 'maks' => 40],
                ['k' => 'wali_pekerjaan', 'label' => 'Pekerjaan', 'no' => '›', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'wali_penghasilan', 'label' => 'Rata-Rata Penghasilan Wali Siswa', 'no' => '›', 'sumber' => 'induk', 'maks' => 40, 'lebar' => 2],
            ],
        ],

        'F' => [
            'judul' => 'Keterangan Siswa',
            'kolom' => 'f3',
            'baris' => [
                ['sub' => '1) Putus Sekolah'],
                ['k' => 'putus_alasan', 'label' => 'Alasan', 'no' => '-', 'sumber' => 'induk', 'maks' => 150, 'lebar' => 2],
                ['k' => 'putus_tanggal', 'label' => 'Tanggal', 'no' => '-', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['sub' => '2) Mutasi/Pindah Sekolah'],
                ['k' => 'pindah_ke', 'label' => 'Pindah ke Sekolah', 'no' => '-', 'sumber' => 'induk', 'maks' => 150, 'lebar' => 2],
                ['k' => 'pindah_tanggal', 'label' => 'Tanggal', 'no' => '-', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['sub' => '3) Lulus Sekolah'],
                ['k' => 'lulus_tanggal', 'label' => 'Tanggal', 'no' => '-', 'sumber' => 'induk', 'tipe' => 'tanggal'],
                ['k' => 'no_ijazah_lulus', 'label' => 'NO. Seri Ijazah', 'no' => '-', 'sumber' => 'induk', 'maks' => 60],
                ['k' => 'no_shun', 'label' => 'NO. Seri SHUN', 'no' => '-', 'sumber' => 'induk', 'maks' => 60],
            ],
        ],
    ];
}

/** Semua baris medan (tanpa judul sub-bagian), ditandai kode bagiannya. */
function siswa_medan_baris(): array
{
    $out = [];
    foreach (siswa_medan_grup() as $kode => $grup) {
        foreach ($grup['baris'] as $baris) {
            if (isset($baris['k'])) {
                $baris['grup'] = $kode;
                $out[$baris['k']] = $baris;
            }
        }
    }
    return $out;
}

/** Nama kolom tabel `siswa_induk` (tanpa siswa_id & diubah). */
function siswa_induk_kunci(): array
{
    $out = [];
    foreach (siswa_medan_baris() as $k => $baris) {
        $tipe = $baris['tipe'] ?? 'teks';
        if (($baris['sumber'] ?? 'induk') !== 'induk') { continue; }
        if (in_array($tipe, ['kelas', 'status', 'foto'], true)) { continue; }
        $out[] = $k;
    }
    return $out;
}

/** Baris siswa_induk milik satu siswa (array kosong bila belum ada). */
function siswa_induk_data(int $siswaId): array
{
    if ($siswaId <= 0) { return []; }
    $r = satu('SELECT * FROM siswa_induk WHERE siswa_id = ?', [$siswaId]);
    return $r === null ? [] : $r;
}

/**
 * Menyimpan medan buku induk. Hanya kolom yang DIKIRIM (ada di $post) yang diubah — bagian
 * yang tidak dikirim tetap seperti semula, sehingga formulir/kiriman lama tidak mengosongkan data.
 */
function siswa_induk_simpan(int $siswaId, array $post): void
{
    if ($siswaId <= 0) { return; }
    $ada = siswa_induk_data($siswaId);
    $kol = [];
    foreach (siswa_induk_kunci() as $k) {
        if (array_key_exists($k, $post)) {
            $kol[$k] = trim((string) $post[$k]);
        } elseif ($ada) {
            $kol[$k] = (string) ($ada[$k] ?? '');
        } else {
            $kol[$k] = '';
        }
    }
    $kol['diubah'] = date('Y-m-d H:i:s');
    if ($ada) {
        $set = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($kol)));
        q('UPDATE siswa_induk SET ' . $set . ' WHERE siswa_id = ?', array_merge(array_values($kol), [$siswaId]));
    } else {
        $nama = array_keys($kol);
        q('INSERT INTO siswa_induk (siswa_id, ' . implode(', ', $nama) . ') VALUES (?, ' . implode(', ', array_fill(0, count($nama), '?')) . ')',
            array_merge([$siswaId], array_values($kol)));
    }
}

/** Nilai satu medan untuk DITAMPILKAN (formulir & lembar cetak). */
function siswa_medan_nilai(array $data, array $baris): string
{
    $v = trim((string) ($data[$baris['k']] ?? ''));
    $tipe = $baris['tipe'] ?? 'teks';
    if ($tipe === 'tanggal') {
        return ($v !== '' && tanggal_sah($v)) ? tanggal_id($v) : ($v !== '' ? $v : '-');
    }
    if ($tipe === 'pilih' && isset($baris['pilihan'][$v])) {
        return (string) $baris['pilihan'][$v];
    }
    return $v !== '' ? $v : '-';
}

/**
 * Identitas untuk KAFER (sampul) Buku Induk.
 *
 * Bentuk & singkatan lembaga diturunkan dari NAMA lembaga (mis. "MA. Sirojuth Tholibiin
 * Mootilango" → "MADRASAH ALIYAH SWASTA" + "(MAS)" + "SIROJUTH THOLIBIIN MOOTILANGO"),
 * dan dapat ditimpa lewat Pengaturan Data Lembaga (kafer_bentuk / kafer_singkat).
 */
function kafer_identitas(): array
{
    $nama = trim(lembaga_nama());
    $bentuk = trim(setting('kafer_bentuk'));
    $singkat = trim(setting('kafer_singkat'));

    // Singkatan di depan nama lembaga (MA., MTs., SMK, …) dipisahkan dari nama induknya.
    $peta = [
        'MA' => 'MADRASAH ALIYAH', 'MAS' => 'MADRASAH ALIYAH',
        'MAK' => 'MADRASAH ALIYAH KEJURUAN', 'MAKN' => 'MADRASAH ALIYAH KEJURUAN NEGERI',
        'MTS' => 'MADRASAH TSANAWIYAH', 'MTSN' => 'MADRASAH TSANAWIYAH NEGERI',
        'MI' => 'MADRASAH IBTIDAIYAH', 'MIN' => 'MADRASAH IBTIDAIYAH NEGERI',
        'SD' => 'SEKOLAH DASAR', 'SDN' => 'SEKOLAH DASAR NEGERI',
        'SMP' => 'SEKOLAH MENENGAH PERTAMA', 'SMPN' => 'SEKOLAH MENENGAH PERTAMA NEGERI',
        'SMA' => 'SEKOLAH MENENGAH ATAS', 'SMAN' => 'SEKOLAH MENENGAH ATAS NEGERI',
        'SMK' => 'SEKOLAH MENENGAH KEJURUAN', 'SMKN' => 'SEKOLAH MENENGAH KEJURUAN NEGERI',
    ];
    $namaSaja = $nama;
    $kunci = '';
    if (preg_match('/^\s*([A-Za-z]{2,4})\s*[.\-]?\s+(.+)$/u', $nama, $m)) {
        $calon = strtoupper(str_replace('.', '', $m[1]));
        if (isset($peta[$calon])) {
            $kunci = $calon;
            $namaSaja = trim($m[2]);
        }
    }
    if ($kunci !== '') {
        $negeri = str_ends_with($kunci, 'N') || stripos($nama, 'negeri') !== false;
        $dasar = $peta[$kunci];
        $otomatisBentuk = $negeri ? $dasar : (str_contains($dasar, 'NEGERI') ? $dasar : $dasar . ' SWASTA');
        $dasarTanpaNegeri = str_replace(' NEGERI', '', $dasar);
        $otomatisSingkat = $negeri ? $kunci : ($kunci === 'MA' ? 'MAS' : $kunci);
        if ($kunci === 'MAS') { $otomatisBentuk = 'MADRASAH ALIYAH SWASTA'; }
        if ($kunci === 'MTS') { $otomatisSingkat = 'MTS'; }
        if (str_starts_with($dasarTanpaNegeri, 'SEKOLAH')) { $otomatisSingkat = $kunci; }
    } else {
        $otomatisBentuk = is_madrasah() ? 'MADRASAH' : 'SEKOLAH';
        $otomatisSingkat = '';
    }

    return [
        'judul'   => 'BUKU INDUK PESERTA DIDIK',
        'bentuk'  => strtoupper($bentuk !== '' ? $bentuk : $otomatisBentuk),
        'singkat' => strtoupper($singkat !== '' ? $singkat : $otomatisSingkat),
        'nama'    => strtoupper($namaSaja !== '' ? $namaSaja : $nama),
        'nama_resmi' => $nama,
        'tahun'   => trim(setting('tahun_ajaran', TA_DEFAULT)),
    ];
}
