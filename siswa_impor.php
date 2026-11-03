<?php
/**
 * Impor Data Siswa dari Excel/CSV (dibaca di peramban, dikirim sebagai JSON).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_login();

$petaKelas = [];
foreach (kelas_list() as $k) {
    $petaKelas[strtolower(trim((string) $k['nama']))] = (int) $k['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_wajib();
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($payload) || empty($payload['baris'])) {
        flash('Tidak ada baris yang dapat diimpor. Pastikan berkas dipilih dan kolom Nama terisi.', 'err');
        redirect('siswa_impor.php');
    }
    $perbarui = f('perbarui') === '1';
    $baru = 0; $ubah = 0; $lewat = 0; $kelasBaru = []; $tglGagal = 0;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($payload['baris'] as $r) {
            if (!is_array($r)) { $lewat++; continue; }
            $nama = trim((string) ($r['nama'] ?? ''));
            if ($nama === '') { $lewat++; continue; }
            $jk = strtoupper(trim((string) ($r['jenis_kelamin'] ?? '')));
            $jk = in_array($jk, ['P', 'PEREMPUAN', 'WANITA'], true) ? 'P' : 'L';
            $namaKelas = trim((string) ($r['kelas'] ?? ''));
            $kelasId = $namaKelas !== '' ? ($petaKelas[strtolower($namaKelas)] ?? null) : null;
            if ($namaKelas !== '' && $kelasId === null) { $kelasBaru[$namaKelas] = true; }
            $tgl = tgl_impor($r['tanggal_lahir'] ?? '');
            if (trim((string) ($r['tanggal_lahir'] ?? '')) !== '' && $tgl === '') { $tglGagal++; }
            $status = strtolower(trim((string) ($r['status'] ?? 'aktif')));
            if (!array_key_exists($status, status_siswa_list())) { $status = 'aktif'; }
            $kol = [
                'nis' => trim((string) ($r['nis'] ?? '')),
                'nisn' => trim((string) ($r['nisn'] ?? '')),
                'nama' => $nama,
                'jenis_kelamin' => $jk,
                'kelas_id' => $kelasId,
                'tempat_lahir' => trim((string) ($r['tempat_lahir'] ?? '')),
                'tanggal_lahir' => $tgl,
                'nama_ayah' => trim((string) ($r['nama_ayah'] ?? '')),
                'nama_ibu' => trim((string) ($r['nama_ibu'] ?? '')),
                'nama_wali' => trim((string) ($r['nama_wali'] ?? '')),
                'telepon' => trim((string) ($r['telepon'] ?? '')),
                'alamat' => trim((string) ($r['alamat'] ?? '')),
                'status' => $status,
                'aktif' => $status === 'aktif' ? 1 : 0,
            ];
            if ($kol['nama_wali'] === '') {
                $kol['nama_wali'] = $kol['nama_ayah'] !== '' ? $kol['nama_ayah'] : $kol['nama_ibu'];
            }
            $ada = null;
            if ($kol['nis'] !== '') {
                $ada = satu('SELECT id, nama_wali FROM siswa WHERE nis = ?', [$kol['nis']]);
            }
            if (!$ada) {
                $ada = satu('SELECT id, nama_wali FROM siswa WHERE lower(nama) = lower(?)', [$nama]);
            }
            if ($ada) {
                if (!$perbarui) { $lewat++; continue; }
                // jangan timpa wali yang sudah ada bila berkas tidak memuatnya
                if (trim((string) ($r['nama_wali'] ?? '')) === '' && trim((string) ($ada['nama_wali'] ?? '')) !== '') {
                    $kol['nama_wali'] = (string) $ada['nama_wali'];
                }
                $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kol)));
                $arg = array_values($kol);
                $arg[] = date('Y-m-d H:i:s');
                $arg[] = (int) $ada['id'];
                q("UPDATE siswa SET $set, diubah = ? WHERE id = ?", $arg);
                $ubah++;
            } else {
                $kol['urutan'] = (int) kolom('SELECT COALESCE(MAX(urutan),0) + 10 FROM siswa');
                $kol['dibuat'] = date('Y-m-d H:i:s');
                $nm = array_keys($kol);
                q('INSERT INTO siswa (' . implode(',', $nm) . ') VALUES (' . implode(',', array_fill(0, count($nm), '?')) . ')', array_values($kol));
                $baru++;
            }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        flash('Impor gagal: ' . $ex->getMessage(), 'err');
        redirect('siswa_impor.php');
    }
    $pesan = 'Impor selesai: ' . $baru . ' siswa baru, ' . $ubah . ' diperbarui, ' . $lewat . ' dilewati.';
    if ($kelasBaru) { $pesan .= ' Kelas tidak ditemukan (data tetap diimpor tanpa kelas): ' . implode(', ', array_keys($kelasBaru)) . '.'; }
    if ($tglGagal > 0) { $pesan .= ' ' . $tglGagal . ' tanggal lahir tidak dapat dikenali (dikosongkan).'; }
    log_catat('impor_siswa', $baru . ' baru, ' . $ubah . ' diperbarui');
    flash($pesan);
    redirect('siswa.php');
}

$GLOBALS['__butuh_xlsx'] = true;
page_top('Impor Data Siswa', 'Administrasi kesiswaan');
?>
<div class="card">
  <div class="card-kepala">
    <div><h2>Impor Data Siswa dari Excel / CSV</h2>
      <p class="card-ket">Berkas dibaca di peramban; server hanya menerima barisnya. Nama kolom dikenali otomatis (urutan bebas).</p></div>
    <div class="card-aksi"><a class="btn btn-garis" href="siswa.php">Kembali</a></div>
  </div>
  <div class="alert alert-info">
    <div><b>Kolom yang dikenali:</b> NIS, NISN, Nama <i>(wajib)</i>, Jenis Kelamin, Kelas, Tempat Lahir, Tanggal Lahir, Nama Ayah, Nama Ibu, Wali, Telepon, Alamat, Status.
      <br>Kelas dicocokkan dengan daftar kelas yang ada (menu Data Siswa → Kelas pada Pengaturan Data Lembaga/kelas). Tanggal boleh ditulis 2011-04-12, 12/04/2011, atau 12 April 2011.</div>
  </div>
  <form method="post" id="formImpor">
    <?= csrf_field() ?>
    <input type="hidden" name="payload" id="payload" value="">
    <div class="form-grid f2">
      <div class="medan"><label for="berkas">Berkas Excel (.xlsx/.xls) atau CSV</label>
        <input type="file" id="berkas" accept=".xlsx,.xls,.csv"></div>
      <div class="medan"><label>Pilihan impor</label>
        <label class="centang"><input type="checkbox" name="perbarui" value="1"> Perbarui data siswa yang NIS/namanya sudah ada</label></div>
    </div>
    <div id="pratinjauImpor" class="mt16"></div>
    <div class="tombol-baris mt16">
      <button class="btn" type="submit" id="tombolImpor" disabled><?= ikon('unggah') ?> Impor Sekarang</button>
      <a class="btn btn-garis" href="siswa.php">Batal</a>
      <button class="btn btn-garis" type="button" id="unduhContoh"><?= ikon('unduh') ?> Unduh Contoh CSV</button>
    </div>
  </form>
</div>
<script>
(function () {
  var input = document.getElementById('berkas');
  var kotak = document.getElementById('pratinjauImpor');
  var payload = document.getElementById('payload');
  var tombol = document.getElementById('tombolImpor');

  var kolom = [
    ['nama', ['nama lengkap', 'nama siswa', 'nama']],
    ['nis', ['nis']],
    ['nisn', ['nisn']],
    ['jenis_kelamin', ['jenis kelamin', 'jk', 'l/p']],
    ['kelas', ['kelas', 'rombel']],
    ['tempat_lahir', ['tempat lahir', 'tempat']],
    ['tanggal_lahir', ['tanggal lahir', 'tgl lahir', 'lahir']],
    ['nama_ayah', ['nama ayah', 'ayah']],
    ['nama_ibu', ['nama ibu', 'ibu']],
    ['nama_wali', ['nama wali', 'wali']],
    ['telepon', ['telepon', 'hp', 'no hp']],
    ['alamat', ['alamat']],
    ['status', ['status']]
  ];
  function normal(s) { return String(s == null ? '' : s).toLowerCase().replace(/\s+/g, ' ').trim(); }
  function petakan(header) {
    var peta = {}, dipakai = {};
    kolom.forEach(function (k) {
      header.forEach(function (h, i) {
        if (dipakai[i]) { return; }
        if (k[1].indexOf(normal(h)) >= 0) { peta[k[0]] = i; dipakai[i] = true; }
      });
    });
    kolom.forEach(function (k) {
      if (peta[k[0]] != null) { return; }
      for (var i = 0; i < header.length; i++) {
        if (dipakai[i]) { continue; }
        var h = normal(header[i]);
        if (!h) { continue; }
        if (k[1].some(function (n) { return h.indexOf(n) === 0; })) { peta[k[0]] = i; dipakai[i] = true; break; }
      }
    });
    return peta;
  }
  input.addEventListener('change', function () {
    kotak.innerHTML = ''; payload.value = ''; tombol.disabled = true;
    var f = input.files && input.files[0];
    if (!f) { return; }
    if (typeof XLSX === 'undefined') { kotak.innerHTML = '<div class="alert alert-err">Modul pembaca Excel belum termuat.</div>'; return; }
    var reader = new FileReader();
    reader.onload = function (ev) {
      var wb;
      try { wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array', raw: false }); }
      catch (e) { kotak.innerHTML = '<div class="alert alert-err">Berkas tidak dapat dibaca.</div>'; return; }
      var ws = wb.Sheets[wb.SheetNames[0]];
      var aoa = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
      if (!aoa.length) { kotak.innerHTML = '<div class="alert alert-err">Berkas kosong.</div>'; return; }
      var header = aoa[0], peta = petakan(header);
      if (peta.nama == null) {
        kotak.innerHTML = '<div class="alert alert-err">Kolom <b>Nama</b> tidak ditemukan. Kolom terbaca: ' +
          header.map(function (h) { return '<i>' + String(h) + '</i>'; }).join(', ') + '</div>';
        return;
      }
      var baris = [];
      for (var i = 1; i < aoa.length; i++) {
        var r = aoa[i];
        if (!r || !String(r[peta.nama] || '').trim()) { continue; }
        var o = {};
        kolom.forEach(function (k) { o[k[0]] = peta[k[0]] != null ? String(r[peta[k[0]]] == null ? '' : r[peta[k[0]]]).trim() : ''; });
        baris.push(o);
      }
      if (!baris.length) { kotak.innerHTML = '<div class="alert alert-err">Tidak ada baris berisi nama.</div>'; return; }
      payload.value = JSON.stringify({ baris: baris });
      tombol.disabled = false;
      var html = '<div class="tabel-ringkas"><span class="chip hijau">' + baris.length + ' baris siap diimpor</span>' +
        '<span class="chip">Kolom terpetakan: ' + Object.keys(peta).length + '</span></div>' +
        '<div class="tabel-bungkus" style="max-height:340px;overflow:auto"><table class="tbl"><thead><tr>' +
        '<th>No</th><th>Nama</th><th>NIS</th><th>NISN</th><th>JK</th><th>Kelas</th><th>Tempat, Tgl Lahir</th><th>Wali</th></tr></thead><tbody>';
      baris.slice(0, 40).forEach(function (b, i) {
        html += '<tr><td>' + (i + 1) + '</td><td>' + b.nama + '</td><td>' + (b.nis || '-') + '</td><td>' + (b.nisn || '-') +
          '</td><td>' + (b.jenis_kelamin || '-') + '</td><td>' + (b.kelas || '-') + '</td><td>' + (b.tempat_lahir || '-') + ', ' +
          (b.tanggal_lahir || '-') + '</td><td>' + (b.nama_wali || b.nama_ayah || '-') + '</td></tr>';
      });
      html += '</tbody></table></div>';
      if (baris.length > 40) { html += '<p class="kecil mut mt8">Pratinjau 40 baris pertama dari ' + baris.length + ' baris.</p>'; }
      kotak.innerHTML = html;
    };
    reader.readAsArrayBuffer(f);
  });
  var contoh = document.getElementById('unduhContoh');
  if (contoh) {
    contoh.addEventListener('click', function () {
      var isi = 'NIS,NISN,Nama Lengkap,Jenis Kelamin,Kelas,Tempat Lahir,Tanggal Lahir,Nama Ayah,Nama Ibu,Wali,Telepon,Alamat,Status\n' +
        '260001,0012345678,Contoh Siswa,L,X,Mopuya,2010-06-11,Nama Ayah,Nama Ibu,Nama Wali,081200000000,Dusun Contoh,aktif\n';
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([isi], { type: 'text/csv;charset=utf-8' }));
      a.download = 'contoh-impor-siswa.csv';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
  }
})();
</script>
<?php page_bottom(); ?>
