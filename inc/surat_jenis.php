<?php
/**
 * JENIS SURAT KELUAR + MEDAN TEMPLATE (v1.6.0).
 *
 * Empat jenis surat:
 *   - rekomendasi_pindah : Surat Rekomendasi Pindah (permohonan ke Kemenag)
 *   - pindah             : Surat Keterangan Pindah Sekolah
 *   - lolos_butuh        : Persetujuan Pindah / Lolos Butuh
 *   - umum               : surat bebas (isi teks bebas) — perilaku lama
 *
 * Medan tiap jenis disimpan sebagai JSON pada kolom `surat_keluar.data`, sehingga menambah/
 * mengubah medan cukup di berkas ini (tanpa perubahan skema). Teks baku tiap Surat ada di
 * `inc/surat_template.php`.
 */
declare(strict_types=1);

const SURAT_JENIS_UMUM = 'umum';

/** Daftar jenis surat keluar (urutan tampil). */
function surat_jenis_list(): array
{
    return [
        'rekomendasi_pindah' => 'Surat Rekomendasi Pindah',
        'pindah'             => 'Surat Keterangan Pindah Sekolah',
        'lolos_butuh'        => 'Persetujuan Pindah / Lolos Butuh',
        'umum'               => 'Surat Umum (isi bebas)',
    ];
}

/** Jenis surat yang sah; nilai asing/ kosong jatuh ke 'umum'. */
function surat_jenis_sah(?string $jenis): string
{
    $jenis = trim((string) $jenis);
    return array_key_exists($jenis, surat_jenis_list()) ? $jenis : SURAT_JENIS_UMUM;
}

function surat_jenis_label(?string $jenis): string
{
    return surat_jenis_list()[surat_jenis_sah($jenis)];
}

/** Apakah jenis ini memakai data siswa (dipilih dari Data Siswa, lalu dapat disunting). */
function surat_jenis_perlu_siswa(string $jenis): bool
{
    return in_array(surat_jenis_sah($jenis), ['rekomendasi_pindah', 'pindah', 'lolos_butuh'], true);
}

/** Langkah baku proses penyelesaian pindah (tabel pada Surat Lolos Butuh). */
function surat_langkah_baku(): array
{
    return ['Melapor', 'Pemenuhan Persyaratan', 'Pemasukan Berkas Surat Pindah', 'Pengiriman Surat Pengesahan', 'Sah Diterima'];
}

/**
 * Medan isian per jenis surat.
 * Kunci tiap medan: label, tipe (teks|panjang|pilih|tanggal), bawaan, wajib, petunjuk, pilihan.
 */
function surat_medan(string $jenis): array
{
    $kecamatan = static fn(): string => (string) setting('kecamatan');
    $kabupaten = static fn(): string => (string) setting('kabupaten');

    if ($jenis === 'rekomendasi_pindah') {
        return [
            'perihal' => ['label' => 'Hal / Perihal', 'bawaan' => 'Permohonan Rekomendasi Pindah Sekolah', 'wajib' => true],
            'lampiran' => ['label' => 'Lampiran', 'bawaan' => '-'],
            'tujuan_jabatan' => ['label' => 'Surat ditujukan kepada (jabatan)', 'bawaan' => 'Kepala Kantor Kementerian Agama ' . $kabupaten(), 'wajib' => true],
            'tujuan_kota' => ['label' => 'Kota pada alamat tujuan', 'bawaan' => $kabupaten()],
            'pemberi_nama' => ['label' => 'Nama penanda tangan', 'bawaan' => kepala_lembaga()],
            'pemberi_nip' => ['label' => 'NIP penanda tangan', 'bawaan' => (string) setting('nip_kepala'), 'petunjuk' => 'Boleh dikosongkan / tanda hubung.'],
            'nama' => ['label' => 'Nama siswa', 'wajib' => true],
            'ttl' => ['label' => 'Tempat, tanggal lahir', 'petunjuk' => 'mis. Batudaa, 05 Agustus 2008'],
            'jenis_kelamin' => ['label' => 'Jenis kelamin', 'tipe' => 'pilih', 'pilihan' => ['Laki-Laki' => 'Laki-Laki', 'Perempuan' => 'Perempuan'], 'bawaan' => 'Laki-Laki'],
            'nama_ibu' => ['label' => 'Nama ibu kandung'],
            'alamat' => ['label' => 'Alamat'],
            'kelas' => ['label' => 'Kelas', 'petunjuk' => 'mis. X (Sepuluh)'],
            'nisn' => ['label' => 'NISN'],
            'nism' => ['label' => 'NISM', 'petunjuk' => 'Bila dikosongkan, dicetak dari NSM + NIS siswa.'],
            'sekolah_asal' => ['label' => 'Madrasah / sekolah asal', 'bawaan' => lembaga_nama()],
            'sekolah_tujuan' => ['label' => 'Madrasah / sekolah tujuan', 'wajib' => true, 'petunjuk' => 'mis. SMA Negeri Pinogaluman'],
            'alasan' => ['label' => 'Alasan pindah', 'bawaan' => 'atas keinginan orangtua'],
            'tempat' => ['label' => 'Tempat penandatanganan', 'bawaan' => $kecamatan()],
        ];
    }

    if ($jenis === 'pindah') {
        return [
            'perihal' => ['label' => 'Perihal (dipakai pada daftar surat)', 'bawaan' => 'Surat Keterangan Pindah Sekolah'],
            'nama' => ['label' => 'Nama siswa', 'wajib' => true],
            'jenis_kelamin' => ['label' => 'Jenis kelamin', 'tipe' => 'pilih', 'pilihan' => ['Laki-Laki' => 'Laki-Laki', 'Perempuan' => 'Perempuan'], 'bawaan' => 'Laki-Laki'],
            'kelas_tahun' => ['label' => 'Kelas / Tahun Pelajaran', 'petunjuk' => 'mis. XI-MIPA / 2024-2025'],
            'ortu_nama' => ['label' => 'Nama orang tua / wali'],
            'ortu_pekerjaan' => ['label' => 'Pekerjaan orang tua / wali'],
            'emis_nomor' => ['label' => 'Nomor Dokumen Surat Mutasi EMIS', 'petunjuk' => 'mis. 2407YJP1664689'],
            'sekolah_tujuan' => ['label' => 'Pindah ke (madrasah / sekolah tujuan)', 'wajib' => true],
            'tempat' => ['label' => 'Tempat penandatanganan', 'bawaan' => $kecamatan()],
            'pemberi_nama' => ['label' => 'Nama penanda tangan', 'bawaan' => kepala_lembaga()],
            'pemberi_nip' => ['label' => 'NIP penanda tangan', 'bawaan' => (string) setting('nip_kepala'), 'petunjuk' => 'Boleh dikosongkan / tanda hubung.'],
        ];
    }

    if ($jenis === 'lolos_butuh') {
        $persyaratan = implode("\n", [
            'Membawa Surat Pindah dari Sekolah Asal',
            'Membawa Surat Keterangan Mutasi dari EMIS/Dapodik',
            'Membawa Buku Laporan Hasil Belajar',
            'Surat Rekomendasi dari Kemenag/Dikbud Provinsi atau Kab/Kota',
            'Bukti Screenshot Pengeluaran Data Siswa di Dapodik (Sekolah Umum)',
            'No. HP Operator Madrasah/Sekolah dan No. HP Admin Dapodik Provinsi atau Kab/Kota',
        ]);
        $berkas = implode("\n", [
            'Foto Copy Ijazah SLTP / MTs (2 rangkap)',
            'Foto Copy Transkip Nilai SLTP / MTs (2 rangkap)',
            'Foto Copy Sertifikat MOS dan PTA (2 rangkap)',
            'Pas Foto terbaru ukuran 3 x 4 cm (4 lembar)',
            'Foto Copy Akta Kelahiran (2 rangkap)',
            'Foto Copy Kartu Keluarga (2 rangkap)',
        ]);
        return [
            'perihal' => ['label' => 'Perihal (dipakai pada daftar surat)', 'bawaan' => 'Persetujuan Pindah / Lolos Butuh'],
            'nama' => ['label' => 'Nama siswa', 'wajib' => true],
            'ttl' => ['label' => 'Tempat, tanggal lahir', 'petunjuk' => 'mis. Batudaa, 05 Agustus 2008'],
            'jenis_kelamin' => ['label' => 'Jenis kelamin', 'tipe' => 'pilih', 'pilihan' => ['Laki-Laki' => 'Laki-Laki', 'Perempuan' => 'Perempuan'], 'bawaan' => 'Laki-Laki'],
            'asal_sekolah' => ['label' => 'Asal sekolah', 'petunjuk' => 'Sekolah asal siswa sebelum pindah ke sini.'],
            'kelas_jurusan' => ['label' => 'Kelas / Jurusan'],
            'nis_nisn' => ['label' => 'NIS / NISN', 'petunjuk' => 'mis. 1755 / 0027276340'],
            'tujuan_pindah' => ['label' => 'Disetujui pindah ke', 'bawaan' => lembaga_nama()],
            'persyaratan' => ['label' => 'Persyaratan yang harus dipenuhi', 'tipe' => 'panjang', 'baris' => 6, 'bawaan' => $persyaratan,
                'petunjuk' => 'Satu butir per baris — dicetak bernomor.'],
            'berkas' => ['label' => 'Berkas yang dilengkapi', 'tipe' => 'panjang', 'baris' => 6, 'bawaan' => $berkas,
                'petunjuk' => 'Satu butir per baris — dicetak bernomor.'],
            'tercatat_sebagai' => ['label' => 'Selama belum disahkan masih tercatat sebagai siswa di', 'bawaan' => lembaga_nama()],
            'tempat' => ['label' => 'Tempat penandatanganan', 'bawaan' => $kecamatan()],
            'pemberi_nama' => ['label' => 'Nama penanda tangan', 'bawaan' => kepala_lembaga()],
            'pemberi_nip' => ['label' => 'NIP penanda tangan', 'bawaan' => (string) setting('nip_kepala'), 'petunjuk' => 'Boleh dikosongkan / tanda hubung.'],
        ];
    }

    return [];
}

/**
 * Data baku (semua kunci medan + langkah proses) untuk sebuah jenis surat.
 * Langkah proses hanya dipakai Surat Lolos Butuh.
 */
function surat_data_baku(string $jenis): array
{
    $data = [];
    foreach (surat_medan($jenis) as $kunci => $m) {
        $data[$kunci] = (string) ($m['bawaan'] ?? '');
    }
    if ($jenis === 'lolos_butuh') {
        foreach (surat_langkah_baku() as $i => $nama) {
            $data['langkah_' . $i . '_tanggal'] = '';
            $data['langkah_' . $i . '_ket'] = '';
        }
    }
    return $data;
}

/** Angka tingkat dalam huruf (untuk "X (Sepuluh)"). */
function surat_angka_ejaan(string $tingkat): string
{
    $peta = [
        '1' => 'Satu', '2' => 'Dua', '3' => 'Tiga', '4' => 'Empat', '5' => 'Lima', '6' => 'Enam',
        '7' => 'Tujuh', '8' => 'Delapan', '9' => 'Sembilan', '10' => 'Sepuluh', '11' => 'Sebelas',
        '12' => 'Dua Belas',
    ];
    $romawi = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];
    $t = strtoupper(trim($tingkat));
    if (isset($romawi[$t])) { return $peta[(string) $romawi[$t]] ?? ''; }
    if (preg_match('/^\d{1,2}$/', $t)) { return $peta[(string) (int) $t] ?? ''; }
    return '';
}

/** Medan yang dapat diisi otomatis dari sebuah baris Data Siswa. */
function surat_data_siswa(array $siswa): array
{
    $jk = strtoupper((string) ($siswa['jenis_kelamin'] ?? 'L')) === 'P' ? 'Perempuan' : 'Laki-Laki';
    $tempat = trim((string) ($siswa['tempat_lahir'] ?? ''));
    $tgl = trim((string) ($siswa['tanggal_lahir'] ?? ''));
    $ttl = trim($tempat . ($tgl !== '' ? ($tempat !== '' ? ', ' : '') . tanggal_id($tgl) : ''));

    $kelas = $siswa['kelas_id'] ? kelas_by_id((int) $siswa['kelas_id']) : null;
    $tingkat = trim((string) ($kelas['tingkat'] ?? ''));
    if ($tingkat === '') {
        $namaKelas = trim((string) ($kelas['nama'] ?? ''));
        if (preg_match('/(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/', strtoupper($namaKelas), $m)) { $tingkat = $m[1]; }
    }
    $ejaan = $tingkat !== '' ? surat_angka_ejaan($tingkat) : '';
    $kelasTeks = $tingkat !== '' ? $tingkat . ($ejaan !== '' ? ' (' . $ejaan . ')' : '') : '';
    $ta = trim((string) setting('tahun_ajaran'));

    $nis = trim((string) ($siswa['nis'] ?? ''));
    $nisn = trim((string) ($siswa['nisn'] ?? ''));
    $nsm = trim((string) setting('nsm'));

    return [
        'nama' => trim((string) ($siswa['nama'] ?? '')),
        'ttl' => $ttl,
        'jenis_kelamin' => $jk,
        'nama_ibu' => trim((string) ($siswa['nama_ibu'] ?? '')),
        'alamat' => trim((string) ($siswa['alamat'] ?? '')),
        'kelas' => $kelasTeks,
        'kelas_jurusan' => $kelasTeks,
        'kelas_tahun' => trim($tingkat . ($ta !== '' ? ($tingkat !== '' ? ' / ' : '') . $ta : '')),
        'nisn' => $nisn,
        'nism' => ($nsm !== '' && $nis !== '') ? $nsm . $nis : '',
        'nis_nisn' => trim($nis . ($nisn !== '' ? ($nis !== '' ? ' / ' : '') . $nisn : '')),
        'sekolah_asal' => lembaga_nama(),
    ];
}

/** Merapikan isian pengguna menjadi data siap simpan (hanya kunci yang dikenal). */
function surat_data_bersih(string $jenis, array $masukan): array
{
    $data = surat_data_baku($jenis);
    foreach (surat_medan($jenis) as $kunci => $m) {
        if (!array_key_exists($kunci, $masukan)) { continue; }
        $nilai = $masukan[$kunci];
        if (is_array($nilai)) { $nilai = implode("\n", array_map('strval', $nilai)); }
        $nilai = trim(str_replace(["\r\n", "\r"], "\n", (string) $nilai));
        $batas = (($m['tipe'] ?? 'teks') === 'panjang') ? 3000 : 250;
        if (function_exists('mb_substr')) { $nilai = mb_substr($nilai, 0, $batas); }
        else { $nilai = substr($nilai, 0, $batas); }
        if (($m['tipe'] ?? '') === 'pilih' && !array_key_exists($nilai, (array) ($m['pilihan'] ?? []))) {
            $nilai = (string) ($m['bawaan'] ?? array_key_first((array) ($m['pilihan'] ?? [])) ?? '');
        }
        $data[$kunci] = $nilai;
    }
    if ($jenis === 'lolos_butuh') {
        foreach (surat_langkah_baku() as $i => $nama) {
            $data['langkah_' . $i . '_tanggal'] = trim((string) ($masukan['langkah_' . $i . '_tanggal'] ?? ''));
            $data['langkah_' . $i . '_ket'] = trim((string) ($masukan['langkah_' . $i . '_ket'] ?? ''));
        }
    }
    return $data;
}

/** Melengkapi medan yang masih kosong dari data siswa (tanpa menimpa isian pengguna). */
function surat_lengkapi_siswa(array $data, array $siswa): array
{
    if (!$siswa) { return $data; }
    foreach (surat_data_siswa($siswa) as $kunci => $nilai) {
        if (!array_key_exists($kunci, $data)) { continue; }
        if (trim((string) $data[$kunci]) === '' && trim((string) $nilai) !== '') { $data[$kunci] = (string) $nilai; }
    }
    return $data;
}

/** Membaca kolom JSON `data` pada baris surat_keluar (selalu array aman). */
function surat_data(array $s): array
{
    $jenis = surat_jenis_sah($s['jenis'] ?? SURAT_JENIS_UMUM);
    $mentah = trim((string) ($s['data'] ?? ''));
    $data = $mentah !== '' ? json_decode($mentah, true) : [];
    if (!is_array($data)) { $data = []; }
    // Lengkapi kunci yang hilang dengan nilai baku supaya cetak tidak pernah kosong strukturnya.
    foreach (surat_data_baku($jenis) as $kunci => $bawaan) {
        if (!array_key_exists($kunci, $data)) { $data[$kunci] = $bawaan; }
    }
    return $data;
}

/** Ringkasan isi surat untuk kolom daftar (nama siswa untuk surat template). */
function surat_ringkas(array $s): string
{
    $jenis = surat_jenis_sah($s['jenis'] ?? SURAT_JENIS_UMUM);
    if ($jenis === SURAT_JENIS_UMUM) { return ''; }
    $d = surat_data($s);
    $nama = trim((string) ($d['nama'] ?? ''));
    $tujuan = trim((string) ($d['sekolah_tujuan'] ?? ($d['tujuan_pindah'] ?? '')));
    $bagian = [];
    if ($nama !== '') { $bagian[] = 'Siswa: ' . $nama; }
    if ($tujuan !== '') { $bagian[] = 'Pindah ke: ' . $tujuan; }
    return implode(' · ', $bagian);
}

/** Tempat & tanggal penandatanganan, mis. "Mootilango, 22 April 2025". */
function surat_tempat_tanggal(array $s, array $d): string
{
    $tempat = trim((string) ($d['tempat'] ?? ''));
    if ($tempat === '') { $tempat = (string) setting('kecamatan'); }
    $tanggal = tanggal_id((string) ($s['tanggal_surat'] ?: tanggal_hari_ini()));
    return trim($tempat . ($tempat !== '' ? ', ' : '') . $tanggal);
}

/** NISM cadangan: NSM + NIS (dipakai bila medan NISM dikosongkan). */
function surat_nism_cadangan(array $s): string
{
    $nsm = trim((string) setting('nsm'));
    $nis = '';
    if (!empty($s['siswa_id'])) {
        $row = satu('SELECT nis FROM siswa WHERE id = ?', [(int) $s['siswa_id']]);
        $nis = trim((string) ($row['nis'] ?? ''));
    }
    return ($nsm !== '' && $nis !== '') ? $nsm . $nis : '';
}
