<?php
/**
 * TEMPLATE CETAK SURAT KELUAR (v1.6.0) — kop + isi baku per jenis surat.
 *
 * Semua teks baku (yang tidak diubah Kepala TU) ada di sini; isian yang dapat disesuaikan
 * berasal dari `inc/surat_jenis.php` (kolom JSON `surat_keluar.data`).
 * Tiga template mengikuti dokumen resmi madrasah: Surat Rekomendasi Pindah, Surat Keterangan
 * Pindah Sekolah, dan Persetujuan Pindah / Lolos Butuh.
 */
declare(strict_types=1);

require_once __DIR__ . '/surat_jenis.php';

function surat_e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Kop surat resmi (mengikuti dokumen madrasah): nama yayasan, nama madrasah, NPSN/NSM/
 * terakreditasi, alamat + HP, lalu email/website.
 */
function surat_kop_html(): string
{
    $yayasan = trim((string) setting('yayasan'));
    $lembaga = trim(lembaga_nama());
    $barisKode = [];
    $npsn = trim((string) setting('npsn'));
    $nsm = trim((string) setting('nsm'));
    $akre = trim((string) setting('akreditasi'));
    if ($npsn !== '') { $barisKode[] = 'NPSN : ' . $npsn; }
    if ($nsm !== '') { $barisKode[] = 'NSM : ' . $nsm; }
    if ($akre !== '') { $barisKode[] = 'Terakreditasi: ' . $akre; }

    // Alamat kop: pakai isian "Alamat" apa adanya, lalu tambahkan desa/kecamatan HANYA bila
    // belum tercantum di dalamnya (isian alamat pemilik biasanya sudah memuat kecamatan —
    // tanpa penjagaan ini kop mencetak "Kec. Mootilango" dua kali).
    $alamat = trim((string) setting('alamat'));
    $desa = trim((string) setting('desa'));
    $kec = trim((string) setting('kecamatan'));
    $potongan = [];
    if ($alamat !== '') { $potongan[] = $alamat; }
    if ($desa !== '' && stripos($alamat, $desa) === false) { $potongan[] = 'Desa ' . $desa; }
    if ($kec !== '' && stripos($alamat, $kec) === false) { $potongan[] = 'Kec. ' . $kec; }
    // Baris wilayah dari kop_baris() dipakai hanya bila isian "Alamat" masih kosong.
    $barisAlamat = trim(implode(', ', $potongan));
    if ($barisAlamat === '') {
        $wilayah = [];
        if ($desa !== '') { $wilayah[] = 'Desa ' . $desa; }
        if ($kec !== '') { $wilayah[] = 'Kec. ' . $kec; }
        $barisAlamat = implode(', ', $wilayah);
    }
    $telepon = trim((string) setting('telepon'));
    if ($telepon !== '') { $barisAlamat = trim($barisAlamat . ($barisAlamat !== '' ? '. ' : '') . 'Hp. ' . $telepon); }

    $email = trim((string) setting('email'));
    $website = trim((string) setting('website'));
    $barisKontak = [];
    if ($email !== '') { $barisKontak[] = 'Email : ' . $email; }
    if ($website !== '') { $barisKontak[] = 'website : ' . $website; }

    $logo = trim((string) setting('logo'));
    $h = '<div class="surat-kop-resmi' . ($logo !== '' ? ' ada-logo' : '') . '">';
    if ($logo !== '') { $h .= '<img class="kop-gambar" src="' . surat_e($logo) . '" alt="Logo">'; }
    $h .= '<div class="kop-teks">';
    if ($yayasan !== '') { $h .= '<div class="kop-yayasan">' . surat_e($yayasan) . '</div>'; }
    $h .= '<div class="kop-nama">' . surat_e($lembaga) . '</div>';
    if ($barisKode) { $h .= '<div class="kop-baris">' . surat_e(implode(', ', $barisKode)) . '</div>'; }
    if ($barisAlamat !== '') { $h .= '<div class="kop-baris">Alamat: ' . surat_e($barisAlamat) . '</div>'; }
    if ($barisKontak) { $h .= '<div class="kop-baris">' . surat_e(implode('  ', $barisKontak)) . '</div>'; }
    $h .= '</div></div>';
    $h .= '<hr class="surat-garis"><hr class="surat-garis2">';
    return $h;
}

/** Baris identitas "Label : Nilai" (dipakai pada daftar keterangan siswa/pemberi). */
function surat_baris_identitas(array $pasangan, string $kelas = 'surat-identitas'): string
{
    $h = '<table class="' . surat_e($kelas) . '"><tbody>';
    foreach ($pasangan as $label => $nilai) {
        $label = (string) $label;
        $nilai = trim((string) $nilai);
        $h .= '<tr><td class="lbl">' . surat_e($label) . '</td><td class="titik">:</td><td>'
            . ($nilai !== '' ? surat_e($nilai) : '&nbsp;') . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/** Blok tanda tangan kepala (kanan): tempat/tanggal, jabatan, ruang tanda tangan, nama, NIP. */
function surat_ttd_html(array $s, array $d, bool $pakaiNip = true): string
{
    $nama = trim((string) ($d['pemberi_nama'] ?? ''));
    if ($nama === '') { $nama = kepala_lembaga(); }
    $nip = trim((string) ($d['pemberi_nip'] ?? ''));
    $h = '<div class="surat-tutup"><div class="surat-ttd">';
    $h .= '<div class="ttd-tempat">' . surat_e(surat_tempat_tanggal($s, $d)) . '</div>';
    $h .= '<div>' . surat_e('Kepala ' . istilah_lembaga()) . '</div>';
    $h .= '<div class="spasi">' . ttd_kepala_img('surat-ttd-gambar') . '</div>';
    $h .= '<div class="nama">' . surat_e($nama) . '</div>';
    if ($pakaiNip) {
        $h .= '<div class="ttd-ket">NIP. ' . surat_e($nip !== '' ? $nip : '&ndash;') . '</div>';
    }
    return $h . '</div></div>';
}

/** Nomor yang ditampilkan dengan spasi seperti dokumen asli: "019 / MAS.STM/IV/2025". */
function surat_nomor_tampil(?string $nomor): string
{
    $nomor = trim((string) $nomor);
    if ($nomor === '') { return ''; }
    return (string) preg_replace('#^(\d+)\s*/#', '$1 / ', $nomor);
}

/** Daftar butir bernomor dari teks multi-baris. */
function surat_daftar_butir(string $teks, string $kelas = 'surat-syarat'): string
{
    $baris = preg_split('/\R/u', trim($teks)) ?: [];
    $baris = array_values(array_filter(array_map('trim', $baris), static fn($b) => $b !== ''));
    if (!$baris) { return ''; }
    $h = '<ol class="' . surat_e($kelas) . '">';
    foreach ($baris as $b) { $h .= '<li>' . surat_e((string) preg_replace('/^\d+[.)]\s*/', '', $b)) . '</li>'; }
    return $h . '</ol>';
}

/** Kop + isi template sesuai jenis surat. Mengembalikan HTML badan lembar (tanpa <html>). */
function surat_template_html(array $s): string
{
    $jenis = surat_jenis_sah($s['jenis'] ?? SURAT_JENIS_UMUM);
    $d = surat_data($s);
    $h = surat_kop_html();

    if ($jenis === 'rekomendasi_pindah') {
        $h .= '<table class="surat-identitas"><tbody>';
        $h .= '<tr><td class="lbl">Nomor</td><td class="titik">:</td><td>' . surat_e(surat_nomor_tampil($s['no_surat'] ?? '')) . '</td>'
            . '<td class="kanan" style="text-align:right">' . surat_e(surat_tempat_tanggal($s, $d)) . '</td></tr>';
        $h .= '<tr><td class="lbl">Lampiran</td><td class="titik">:</td><td colspan="2">' . surat_e(($d['lampiran'] ?? '') !== '' ? $d['lampiran'] : '-') . '</td></tr>';
        $h .= '<tr><td class="lbl">Hal</td><td class="titik">:</td><td colspan="2"><b>' . surat_e($d['perihal'] ?? '') . '</b></td></tr>';
        $h .= '</tbody></table>';

        $h .= '<p class="surat-alamat">Yth, <b>' . surat_e($d['tujuan_jabatan'] ?? '') . '</b><br>Di,<br>'
            . '&nbsp;&nbsp;&nbsp;&nbsp;' . surat_e($d['tujuan_kota'] ?? '') . '</p>';

        $h .= '<p class="surat-p">Yang bertanda tangan di bawah ini :</p>';
        $h .= surat_baris_identitas([
            'Nama' => $d['pemberi_nama'] ?? '',
            'NIP' => ($d['pemberi_nip'] ?? '') !== '' ? $d['pemberi_nip'] : '-',
        ]);
        $h .= '<p class="surat-p">Dengan ini menerangkan :</p>';
        $h .= surat_baris_identitas([
            'Nama' => $d['nama'] ?? '',
            'Tempat Tanggal Lahir' => $d['ttl'] ?? '',
            'Jenis Kelamin' => $d['jenis_kelamin'] ?? '',
            'Nama Ibu Kandung' => $d['nama_ibu'] ?? '',
            'Alamat' => $d['alamat'] ?? '',
            'Kelas' => $d['kelas'] ?? '',
            'NISN' => $d['nisn'] ?? '',
            'NISM' => ($d['nism'] ?? '') !== '' ? $d['nism'] : surat_nism_cadangan($s),
            'Madrasah Asal' => $d['sekolah_asal'] ?? '',
            'Madrasah/Sekolah Tujuan' => $d['sekolah_tujuan'] ?? '',
        ]);
        $h .= '<p class="surat-isi">Menindaklanjuti permohonan pindah dari orang tua peserta didik, '
            . 'dengan alasan ' . surat_e($d['alasan'] ?? '') . ', maka kami dengan ini memohon diberikan '
            . 'surat rekomendasi mutasi/pindah untuk dapat melanjutkan pendidikan.</p>';
        $h .= '<p class="surat-isi">Demikian surat permohonan ini kami buat dengan sesungguhnya agar dapat '
            . 'digunakan sebagaimana mestinya, dan disampaikan terima kasih.</p>';
        return $h . surat_ttd_html($s, $d);
    }

    if ($jenis === 'pindah') {
        $h .= '<h1 class="surat-judul">SURAT KETERANGAN PINDAH SEKOLAH</h1>';
        $h .= '<p class="surat-nomor-teks">Nomor: ' . surat_e(surat_nomor_tampil($s['no_surat'] ?? '')) . '</p>';
        // "Kabupaten"/"Kota" hanya ditambahkan bila isian kabupaten belum memuatnya
        // (isian pemilik sudah berbunyi "Kabupaten Gorontalo" — tanpa penjagaan ini
        // kalimatnya menjadi "Kabupaten Kabupaten Gorontalo").
        $kab = trim((string) setting('kabupaten'));
        $prov = trim((string) setting('provinsi'));
        $lingkup = trim(lembaga_nama()
            . ($kab !== '' ? ' ' . (preg_match('/^(kabupaten|kab\.|kota)\b/i', $kab) ? $kab : 'Kabupaten ' . $kab) : '')
            . ($prov !== '' ? ' Provinsi ' . $prov : ''));
        $h .= '<p class="surat-isi">Yang bertanda tangan dibawah ini Kepala ' . surat_e($lingkup)
            . ', menerangkan bahwa:</p>';
        $h .= surat_baris_identitas([
            'Nama' => $d['nama'] ?? '',
            'Jenis Kelamin' => $d['jenis_kelamin'] ?? '',
            'Kelas / Tahun Pelajaran' => $d['kelas_tahun'] ?? '',
        ]);
        $h .= '<p class="surat-p">Sesuai permohonan Orang tua / Wali murid:</p>';
        $h .= surat_baris_identitas([
            'Nama' => $d['ortu_nama'] ?? '',
            'Pekerjaan' => $d['ortu_pekerjaan'] ?? '',
        ]);
        $h .= '<p class="surat-isi">Berdasarkan Surat Mutasi EMIS Dengan Nomor Dokumen : '
            . '<b>' . surat_e($d['emis_nomor'] ?? '') . '</b> telah mengajukan permohonan pindah ke '
            . '<b>' . surat_e($d['sekolah_tujuan'] ?? '') . '</b></p>';
        $h .= '<p class="surat-isi">Demikian Surat Keterangan ini dibuat, untuk diketahui dan dipergunakan '
            . 'sebagaimana mestinya.</p>';
        return $h . surat_ttd_html($s, $d);
    }

    if ($jenis === 'lolos_butuh') {
        $lembaga = lembaga_nama();
        $h .= '<h1 class="surat-judul">PERSETUJUAN PINDAH/LOLOS BUTUH</h1>';
        $h .= '<p class="surat-nomor-teks">Nomor : ' . surat_e(surat_nomor_tampil($s['no_surat'] ?? '')) . '</p>';
        $h .= '<p class="surat-isi">Yang bertanda tangan di bawah ini Kepala ' . surat_e($lembaga)
            . ', menerangkan kepada :</p>';
        $h .= surat_baris_identitas([
            'Nama' => $d['nama'] ?? '',
            'Tempat, tgl lahir' => $d['ttl'] ?? '',
            'Jenis Kelamin' => $d['jenis_kelamin'] ?? '',
            'Asal sekolah' => $d['asal_sekolah'] ?? '',
            'Kelas/Jurusan' => $d['kelas_jurusan'] ?? '',
            'NIS/NISN' => $d['nis_nisn'] ?? '',
        ]);
        $tujuan = trim((string) ($d['tujuan_pindah'] ?? ''));
        $h .= '<p class="surat-isi">Telah melapor dan telah disetujui perpindahannya ke '
            . '<b>' . surat_e($tujuan !== '' ? $tujuan : $lembaga) . '</b>. Sebelum Sah sebagai siswa '
            . '<b>' . surat_e($lembaga) . '</b>, agar memenuhi persyaratan sebagai berikut :</p>';
        $h .= surat_daftar_butir((string) ($d['persyaratan'] ?? ''));
        $h .= '<p class="surat-isi">Berkas tersebut dilengkapi :</p>';
        $h .= surat_daftar_butir((string) ($d['berkas'] ?? ''));
        $h .= '<p class="surat-isi">Proses penyelesaian pindah sebagai berikut :</p>';
        $h .= '<table class="surat-proses"><thead><tr><th style="width:26px">No</th><th>Uraian</th>'
            . '<th style="width:96px">Tanggal</th><th style="width:86px">Paraf Kepala Madrasah</th>'
            . '<th style="width:96px">Keterangan</th></tr></thead><tbody>';
        foreach (surat_langkah_baku() as $i => $namaLangkah) {
            $h .= '<tr><td class="tengah">' . ($i + 1) . '</td><td>' . surat_e($namaLangkah) . '</td>'
                . '<td class="tengah">' . surat_e((string) ($d['langkah_' . $i . '_tanggal'] ?? '')) . '</td>'
                . '<td></td>'
                . '<td>' . surat_e((string) ($d['langkah_' . $i . '_ket'] ?? '')) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        $tercatat = trim((string) ($d['tercatat_sebagai'] ?? ''));
        $h .= '<p class="surat-isi">Selama belum ada pengesahan sebagai siswa <b>' . surat_e($lembaga)
            . '</b> yang bersangkutan masih tercatat sebagai Siswa <b>' . surat_e($tercatat !== '' ? $tercatat : $lembaga)
            . '</b> dan wajib mengikuti proses pembelajaran di madrasah asal.</p>';
        $h .= '<p class="surat-isi">Demikian persetujuan pindah ini diberikan untuk digunakan '
            . 'sebagaimana mestinya.</p>';
        return $h . surat_ttd_html($s, $d);
    }

    // SURAT UMUM — perilaku lama (kop + Nomor/Lampiran/Perihal + isi bebas + salam).
    $h .= '<table class="surat-identitas"><tbody>';
    $h .= '<tr><td class="lbl">Nomor</td><td class="titik">:</td><td>' . surat_e($s['no_surat'] ?? '') . '</td>'
        . '<td class="kanan" style="text-align:right">' . surat_e(tanggal_id($s['tanggal_surat'] ?? '')) . '</td></tr>';
    $h .= '<tr><td class="lbl">Lampiran</td><td class="titik">:</td><td colspan="2">'
        . surat_e(($s['lampiran'] ?? '') !== '' ? $s['lampiran'] : '-') . '</td></tr>';
    $h .= '<tr><td class="lbl">Perihal</td><td class="titik">:</td><td colspan="2"><b>'
        . surat_e($s['perihal'] ?? '') . '</b></td></tr>';
    $h .= '</tbody></table>';
    $h .= '<p class="surat-p">Kepada<br><b>' . surat_e(($s['tujuan'] ?? '') !== '' ? $s['tujuan'] : '-')
        . '</b><br>di tempat</p>';
    $h .= '<p class="surat-p">Assalamu&rsquo;alaikum warahmatullahi wabarakatuh.</p>';
    $isi = trim((string) ($s['isi'] ?? ''));
    $h .= '<div class="surat-isi">' . ($isi !== ''
        ? nl2br(surat_e($isi))
        : '<i>Isi surat belum diisi. Lengkapi pada menu Surat Keluar &rarr; Ubah.</i>') . '</div>';
    $h .= '<p class="surat-p" style="margin-top:12px">Demikian surat ini kami sampaikan. Atas perhatian dan '
        . 'kerja sama Bapak/Ibu, kami ucapkan terima kasih.</p>';
    $h .= '<p class="surat-p">Wassalamu&rsquo;alaikum warahmatullahi wabarakatuh.</p>';
    return $h . surat_ttd_html($s, ['pemberi_nama' => kepala_lembaga(), 'pemberi_nip' => nip_kepala(), 'tempat' => '']);
}
