# PRD — Aplikasi Manajemen Proyek Internal

**Versi:** 2.0 (MVP)
**Tanggal:** 21 Agustus 2026
**Status:** Draft untuk disetujui
**Perubahan dari v1.0:** lihat Bagian 13

---

## 1. Ringkasan

Aplikasi manajemen proyek berbasis web untuk perusahaan dengan struktur organisasi berjenjang. Menggantikan pengelolaan berbasis spreadsheet (satu sheet per programmer) dengan sistem terpusat yang tetap mempertahankan cara kerja yang sudah dikenal tim: hierarki task bertingkat, progress persen, dan penjadwalan berbasis minggu.

**Pembeda utama:**
1. Hierarki organisasi berkedalaman bebas (Company → Divisi → Sub Divisi → ...) yang dipakai untuk menentukan akses, bukan sekadar label.
2. Monitoring per orang dan per divisi lintas project — kemampuan yang hilang saat memakai spreadsheet terpisah.

---

## 2. Tujuan & Metrik Keberhasilan

### Tujuan
1. Menggantikan spreadsheet per-programmer dengan satu sumber data terpusat.
2. Memberi visibilitas progres per divisi, sub-divisi, dan per orang tanpa rekap manual.
3. Mempertahankan cara kerja yang sudah dikenal tim agar adopsi tidak terhambat.
4. Rilis MVP yang layak pakai dalam 8 minggu.

### Metrik
| Metrik | Target |
|---|---|
| Tim yang berhenti memakai spreadsheet dalam 1 bulan pasca-rilis | 100% |
| User aktif mingguan (WAU) | ≥ 60% dari user terdaftar |
| Task dibuat per company per minggu | ≥ 20 |
| Waktu load halaman board (p95) | < 1,5 detik |
| Waktu load halaman monitoring per orang (p95) | < 2 detik |

### Non-Goals (di luar scope MVP)
- Aplikasi mobile native
- Integrasi Git / CI-CD
- Time tracking & timesheet
- Roadmap kuartalan level portfolio (lihat Backlog)
- Billing & subscription
- Public API
- Multi-bahasa (Indonesia saja)

---

## 3. Persona Pengguna

| Persona | Kebutuhan Utama |
|---|---|
| **Super Admin** (operator platform) | Membuat company baru, menunjuk Owner, menonaktifkan company |
| **Owner / Admin Company** | Menyusun struktur divisi, mengundang anggota, mengatur role & cakupan akses |
| **Kepala Divisi / Sub Divisi** | Memantau progres seluruh unit di bawahnya, per sub-divisi dan per orang |
| **Manager / Lead** | Membuat project, membagi task, memantau beban kerja tim |
| **Programmer / Member** | Melihat task miliknya, update progress, berkomentar |

---

## 4. Keputusan Teknis

| Aspek | Keputusan |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Frontend | Inertia.js 3 + React |
| Auth | Laravel Fortify |
| Routing FE | Laravel Wayfinder |
| Database | PostgreSQL |
| Tenancy | Multi-tenant, single database, isolasi via `workspace_id` |
| Notifikasi | In-app, polling 45 detik (bukan real-time) |
| Email | SMTP (sudah tersedia) — undangan & reset password |
| Bahasa UI | Bahasa Indonesia |
| Queue | Database driver |
| Testing | Terbatas pada isolasi tenant & otorisasi (lihat R-1) |
| Skala target | 50–500 user, beberapa company, tahun pertama |

---

## 5. Model Data

### 5.1 Diagram Relasi

```
workspaces (company)
  ├── org_units (self-reference, berjenjang)
  │     └── projects
  │           └── tasks (self-reference, maks. 4 level)
  │                 └── comments
  ├── positions (jabatan)
  └── workspace_members
        ├── position_id  → positions
        └── scope_org_unit_id → org_units

users ──< workspace_members >── workspaces
users ──< project_members >── projects
users ──< notifications
```

### 5.2 Tabel Utama

**`workspaces`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| name | string | Nama company |
| slug | string unique | Untuk URL |
| is_active | boolean | Dinonaktifkan oleh Super Admin |
| created_at, updated_at | timestamp | |

**`org_units`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| workspace_id | bigint FK | |
| parent_id | bigint FK nullable | Self-reference |
| name | string | |
| type | string | 'division', 'sub_division', dll. |
| path | string | Materialized path, mis. `/1/5/12/` |
| depth | smallint | 0 = root, maks. 5 |
| created_at, updated_at | timestamp | |

Index: `(workspace_id, parent_id)`, `(workspace_id, path)`

**`positions`** — BARU
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| workspace_id | bigint FK | |
| name | string | "Kepala Divisi", "Manager", "Programmer" |
| level | smallint | 1 = tertinggi. Untuk urutan tampilan & aturan masa depan |
| created_at, updated_at | timestamp | |

**`workspace_members`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| workspace_id | bigint FK | |
| user_id | bigint FK | |
| role | enum | `owner`, `admin`, `member` — hak akses sistem |
| position_id | bigint FK nullable | **BARU** — jabatan, terpisah dari role |
| org_unit_id | bigint FK nullable | Unit tempat user ditugaskan |
| scope_type | enum | **BARU** — `project_only` (default) / `unit_subtree` |
| scope_org_unit_id | bigint FK nullable | **BARU** — akar cakupan pemantauan |
| manager_id | bigint FK nullable | **BARU** — atasan langsung (belum dipakai di MVP) |
| joined_at | timestamp | |

**`projects`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| workspace_id | bigint FK | |
| org_unit_id | bigint FK | Boleh menempel di unit level mana pun |
| name | string | |
| description | text nullable | |
| status | enum | `active`, `completed`, `archived` |
| created_by | bigint FK | |
| created_at, updated_at | timestamp | |

**`tasks`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| workspace_id | bigint FK | Denormalisasi untuk scoping cepat |
| project_id | bigint FK | |
| parent_task_id | bigint FK nullable | Self-reference, **maks. 4 level** |
| path | string | **BARU** — materialized path, mis. `/12/45/78/` |
| depth | smallint | **BARU** — 0 = task akar |
| wbs_number | string | **BARU** — dihitung, mis. `1.1.1` |
| title | string | |
| description | text nullable | |
| assignee_id | bigint FK nullable | |
| status | enum | `todo`, `in_progress`, `done` |
| progress | smallint | **BARU** — 0–100, default 0 |
| priority | enum | `low`, `medium`, `high`, `urgent` |
| start_date | date nullable | Disimpan sebagai tanggal asli |
| due_date | date nullable | Disimpan sebagai tanggal asli |
| position | integer | Urutan di antara saudara sekandung |
| created_by | bigint FK | |
| created_at, updated_at | timestamp | |

Index: `(project_id, status, position)`, `(assignee_id, due_date)`, `(assignee_id, status)`, `(parent_task_id)`, `(project_id, path)`

**`comments`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| task_id | bigint FK | |
| user_id | bigint FK | |
| body | text | Mention disimpan sebagai `@[user:42]` |
| created_at, updated_at | timestamp | |

**`notifications`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK | Penerima |
| workspace_id | bigint FK | |
| type | string | `task_assigned`, `mentioned`, `comment_added`, `due_soon` |
| entity_type | string | `task`, `comment` |
| entity_id | bigint | |
| actor_id | bigint FK nullable | |
| is_read | boolean | |
| created_at | timestamp | |

Index: `(user_id, is_read, created_at)`

**`invitations`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| workspace_id | bigint FK | |
| email | string | |
| role | enum | |
| token | string unique | |
| expires_at | timestamp | Default 7 hari |
| accepted_at | timestamp nullable | |

### 5.3 Catatan Perancangan

1. **Semua tabel tenant-scoped punya `workspace_id`**, memungkinkan global scope sederhana dan query cepat.
2. **Materialized path pada `org_units` dan `tasks`** membuat query keturunan cukup `WHERE path LIKE '/12/%'` tanpa recursive CTE. Saat parent dipindah, `path` seluruh keturunan di-update dalam transaction.
3. **Batas 4 level task divalidasi di aplikasi, bukan skema.** Melonggarkannya nanti cukup mengubah satu konstanta.
4. **Tanggal disimpan sebagai `date` asli, bukan nomor minggu.** Format mingguan hanya lapisan presentasi — lihat 6.6.
5. **`positions` terpisah dari `role`.** Jabatan mencerminkan organisasi; role mencerminkan hak akses sistem. Keduanya berubah karena sebab berbeda.

---

## 6. Kebutuhan Fungsional

### 6.1 Autentikasi (Fortify)

| ID | Kebutuhan | Prioritas |
|---|---|---|
| AUTH-1 | Login dengan email + password | Must |
| AUTH-2 | Logout | Must |
| AUTH-3 | Reset password via email | Must |
| AUTH-4 | Ubah profil (nama, avatar, password) | Must |
| AUTH-5 | Registrasi publik **dinonaktifkan** — akun hanya lahir dari undangan | Must |
| AUTH-6 | Session timeout setelah 8 jam tidak aktif | Should |

### 6.2 Super Admin

| ID | Kebutuhan | Prioritas |
|---|---|---|
| SA-1 | Melihat daftar semua workspace | Must |
| SA-2 | Membuat workspace baru + menunjuk Owner via undangan email | Must |
| SA-3 | Menonaktifkan/mengaktifkan workspace | Must |
| SA-4 | Super Admin **tidak** dapat melihat isi project/task workspace | Must |

Ditandai kolom `is_super_admin` pada `users`, diakses lewat route terpisah (`/admin`).

### 6.3 Workspace, Org Unit & Jabatan

| ID | Kebutuhan | Prioritas |
|---|---|---|
| ORG-1 | CRUD org unit | Must |
| ORG-2 | Org unit bersarang hingga kedalaman 5 | Must |
| ORG-3 | Tampilan tree yang bisa expand/collapse | Must |
| ORG-4 | Org unit tidak bisa dihapus jika masih punya anak atau project | Must |
| ORG-5 | Memindahkan org unit ke parent lain | Should |
| ORG-6 | Mengundang user via email | Must |
| ORG-7 | Undangan berlaku 7 hari, bisa dikirim ulang & dibatalkan | Must |
| ORG-8 | Mengubah role dan menugaskan user ke org unit | Must |
| ORG-9 | Mengeluarkan anggota dari workspace | Must |
| **ORG-10** | **CRUD jabatan (positions) per workspace** | **Must** |
| **ORG-11** | **Menetapkan jabatan pada anggota; jabatan tampil di profil & daftar anggota** | **Must** |
| **ORG-12** | **Menetapkan cakupan pemantauan (`scope_type` + `scope_org_unit_id`) pada anggota** | **Must** |

### 6.4 Project

| ID | Kebutuhan | Prioritas |
|---|---|---|
| PRJ-1 | Membuat project dan menempelkannya ke satu org unit | Must |
| PRJ-2 | Mengubah nama, deskripsi, org unit | Must |
| PRJ-3 | Menambah/mengeluarkan anggota project | Must |
| PRJ-4 | Mengubah status project | Should |
| PRJ-5 | Daftar project, difilter per org unit | Must |
| PRJ-6 | Menghapus project (soft delete) | Should |

### 6.5 Task

| ID | Kebutuhan | Prioritas |
|---|---|---|
| TSK-1 | Membuat task dengan judul (wajib), field lain opsional | Must |
| TSK-2 | Mengubah semua atribut task | Must |
| TSK-3 | Menghapus task (soft delete); seluruh keturunan ikut terhapus | Must |
| TSK-4 | Menugaskan task ke satu anggota project | Must |
| TSK-5 | Mengubah status task | Must |
| TSK-6 | Priority: low / medium / high / urgent | Must |
| TSK-7 | Start date & due date | Must |
| TSK-8 | Validasi: `due_date` ≥ `start_date` | Must |
| **TSK-9** | **Task bersarang hingga 4 level** | **Must** |
| **TSK-10** | **Validasi: menolak penambahan anak jika `depth` sudah 3** | **Must** |
| **TSK-11** | **Validasi: task tidak boleh menjadi keturunan dirinya sendiri** | **Must** |
| **TSK-12** | **Nomor WBS otomatis (1, 1.1, 1.1.1) dihitung dari posisi dalam hierarki** | **Must** |
| **TSK-13** | **Nomor WBS dihitung ulang saat task dipindah atau diurutkan ulang** | **Must** |
| **TSK-14** | **Progress 0–100%, diisi manual** | **Must** |
| **TSK-15** | **Status → Done mengubah progress jadi 100%; status → To Do mengubah jadi 0%** | **Must** |
| **TSK-16** | **Progress ≥ 1% dan < 100% tidak boleh saat status To Do** | **Should** |
| **TSK-17** | **Task yang punya anak menampilkan progress rollup (rata-rata anak) di samping progress manualnya** | **Should** |
| TSK-18 | Task punya assignee dan tanggal sendiri di level mana pun | Must |

**Catatan TSK-17:** progress rollup ditampilkan sebagai informasi, tidak menimpa nilai manual. Ini menghindari kebingungan saat parent dan anak tidak sinkron, sekaligus memberi sinyal bila estimasi parent terlalu optimistis.

### 6.6 Penanggalan & Format Minggu — BARU

| ID | Kebutuhan | Prioritas |
|---|---|---|
| DATE-1 | Tanggal disimpan sebagai `date` asli di database | Must |
| DATE-2 | Tampilan default format minggu: `W1 07-25` (minggu ke-1, Juli 2025) | Must |
| DATE-3 | Nomor minggu dalam bulan dihitung: `ceil(tanggal / 7)`, hasil 1–5 | Must |
| DATE-4 | Input memakai week picker; start → Senin, end → Jumat pada minggu terpilih | Must |
| DATE-5 | Tersedia opsi beralih ke date picker biasa untuk presisi harian | Should |
| DATE-6 | Zona waktu tampilan Asia/Jakarta | Must |

### 6.7 Board (Kanban)

| ID | Kebutuhan | Prioritas |
|---|---|---|
| BRD-1 | Tiga kolom tetap: To Do, In Progress, Done | Must |
| BRD-2 | Drag & drop antar kolom → mengubah status | Must |
| BRD-3 | Drag & drop dalam kolom → mengubah `position` | Must |
| BRD-4 | Hanya task level akar (`depth = 0`) tampil sebagai kartu | Must |
| BRD-5 | Kartu menampilkan: WBS, judul, avatar assignee, priority, due date, progress bar, jumlah anak selesai | Must |
| BRD-6 | Klik kartu membuka panel detail task | Must |
| BRD-7 | Due date terlewat ditandai merah | Should |

### 6.8 List View

| ID | Kebutuhan | Prioritas |
|---|---|---|
| LST-1 | Tabel dengan kolom: WBS, judul, progress, assignee, status, priority, start, end | Must |
| LST-2 | Baris dapat di-expand hingga 4 level, dengan indentasi | Must |
| LST-3 | Sort berdasarkan due date, priority, atau tanggal dibuat | Must |
| LST-4 | Edit inline untuk status, progress, dan assignee | Should |

### 6.9 Timeline (Gantt)

| ID | Kebutuhan | Prioritas |
|---|---|---|
| TML-1 | Bar per task berdasarkan `start_date` s.d. `due_date` | Must |
| TML-2 | Kolom kiri (judul task) sticky saat scroll horizontal | Must |
| TML-3 | Header dua baris: bulan/tahun di atas, nomor minggu di bawah | Must |
| TML-4 | Zoom: minggu (default), bulan, kuartal | Must |
| TML-5 | Baris mengikuti hierarki task, bisa expand/collapse | Must |
| TML-6 | Bar parent dihitung dari rentang seluruh keturunannya | Must |
| TML-7 | Isian bar merefleksikan `progress` (bagian terisi vs kosong) | Should |
| TML-8 | Garis penanda hari ini | Must |
| TML-9 | Task tanpa tanggal dikelompokkan terpisah ("Belum dijadwalkan") | Must |
| TML-10 | Klik bar membuka detail task | Must |
| TML-11 | Drag/resize bar **tidak** termasuk MVP | — |

### 6.10 Monitoring per Orang — BARU

Halaman ini adalah pengganti langsung spreadsheet per-programmer.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| MON-1 | Daftar anggota dalam cakupan akses user, dengan ringkasan: aktif, terlambat, selesai 30 hari terakhir | Must |
| MON-2 | Klik anggota → seluruh task miliknya **lintas project** | Must |
| MON-3 | Tampilan task dalam bentuk hierarki + bar timeline mingguan (menyerupai layout spreadsheet lama) | Must |
| MON-4 | Kolom: WBS, judul, project, progress, start, end | Must |
| MON-5 | Filter rentang tanggal | Should |
| MON-6 | Setiap user selalu dapat melihat halaman ini untuk dirinya sendiri | Must |
| MON-7 | Halaman "Task Saya" sebagai halaman awal setelah login | Should |

### 6.11 Monitoring per Divisi — BARU

| ID | Kebutuhan | Prioritas |
|---|---|---|
| DIV-1 | Tabel ringkasan per sub-divisi: jumlah project, task, selesai, berjalan, terlambat | Must |
| DIV-2 | Drill-down: klik sub-divisi → ringkasan unit di bawahnya, mengikuti `path` | Must |
| DIV-3 | Rata-rata progress per unit | Should |
| DIV-4 | Definisi terlambat: `due_date < hari ini AND status != done` | Must |
| DIV-5 | Task tanpa `due_date` tidak dihitung sebagai terlambat, tapi ditampilkan sebagai "tanpa jadwal" | Must |
| DIV-6 | Hanya dapat diakses user dengan `scope_type = 'unit_subtree'` atau role admin/owner | Must |

### 6.12 Filter & Pencarian

| ID | Kebutuhan | Prioritas |
|---|---|---|
| FLT-1 | Filter berdasarkan assignee | Must |
| FLT-2 | Filter berdasarkan status | Must |
| FLT-3 | Filter berdasarkan priority | Should |
| FLT-4 | Filter tersimpan di URL query string | Should |

### 6.13 Komentar & Mention

| ID | Kebutuhan | Prioritas |
|---|---|---|
| CMT-1 | Menulis komentar pada task | Must |
| CMT-2 | Mengubah/menghapus komentar sendiri | Should |
| CMT-3 | Mengetik `@` memunculkan autocomplete anggota project | Must |
| CMT-4 | Mention disimpan `@[user:ID]`, dirender sebagai nama saat ini | Must |
| CMT-5 | Urut kronologis, terbaru di bawah | Must |

### 6.14 Notifikasi

| ID | Kebutuhan | Prioritas |
|---|---|---|
| NTF-1 | Ikon bell dengan badge jumlah belum dibaca | Must |
| NTF-2 | Dropdown 20 notifikasi terbaru | Must |
| NTF-3 | Klik → tandai dibaca + arahkan ke task terkait | Must |
| NTF-4 | Tombol "Tandai semua dibaca" | Must |
| NTF-5 | Polling tiap 45 detik | Must |
| NTF-6 | Notifikasi > 30 hari dibersihkan lewat scheduled job | Should |

**Pemicu notifikasi:**

| Tipe | Kapan | Penerima |
|---|---|---|
| `task_assigned` | User ditugaskan ke task | Assignee (kecuali dirinya sendiri) |
| `mentioned` | User di-mention di komentar | User yang di-mention |
| `comment_added` | Komentar baru di task | Assignee task |
| `due_soon` | Task jatuh tempo hari ini atau terlewat | Assignee |

`due_soon` dijalankan scheduled command harian, dengan pengaman anti-duplikat per task per hari.

---

## 7. Model Otorisasi

### 7.1 Role dan Hak Akses

| Aksi | Owner | Admin | Member |
|---|:---:|:---:|:---:|
| Kelola org unit & jabatan | ✅ | ✅ | ❌ |
| Undang / keluarkan anggota | ✅ | ✅ | ❌ |
| Ubah role anggota | ✅ | ❌ | ❌ |
| Tetapkan cakupan pemantauan | ✅ | ✅ | ❌ |
| Buat project | ✅ | ✅ | ❌ |
| Ubah / hapus project | ✅ | ✅ | ❌ |
| Kelola anggota project | ✅ | ✅ | ❌ |
| Buat / ubah task | ✅ | ✅ | ✅ |
| Hapus task | ✅ | ✅ | hanya buatan sendiri |
| Komentar | ✅ | ✅ | ✅ |
| Monitoring per divisi | ✅ | ✅ | jika `unit_subtree` |
| Monitoring per orang | ✅ | ✅ | hanya diri sendiri, atau sesuai cakupan |

### 7.2 Aturan Visibilitas

1. Member melihat project di mana ia terdaftar sebagai anggota project.
2. **Member dengan `scope_type = 'unit_subtree'` juga melihat semua project pada `scope_org_unit_id` dan seluruh keturunannya — bersifat read-only.** Untuk hak edit, ia tetap harus didaftarkan sebagai anggota project.
3. Admin dan Owner melihat seluruh project dalam workspace.
4. Semua query di-scope otomatis ke `workspace_id` sesi aktif melalui global scope.
5. Setiap request memvalidasi resource milik workspace aktif — mencegah kebocoran antar-tenant lewat manipulasi ID di URL.
6. Owner tidak dapat dihapus atau diturunkan role-nya jika ia satu-satunya Owner.

**Contoh penerapan aturan 2:** Kepala Divisi Engineering diberi `scope_type = 'unit_subtree'` dengan `scope_org_unit_id` = unit Engineering. Ia otomatis melihat project di Backend, Frontend, dan QA tanpa perlu didaftarkan satu per satu — termasuk project yang dibuat setelahnya.

### 7.3 Implementasi

- **Policy** per model: `ProjectPolicy`, `TaskPolicy`, `OrgUnitPolicy`, `CommentPolicy`, `MonitoringPolicy`.
- **Middleware** `EnsureWorkspaceAccess` — menetapkan workspace aktif dari session, memvalidasi keanggotaan.
- **Global scope** `WorkspaceScope` pada semua model tenant-scoped.
- **Query scope** `visibleTo(User $user)` pada model Project, menggabungkan keanggotaan project dan cakupan subtree dalam satu query.

---

## 8. Kebutuhan Non-Fungsional

| Kategori | Kebutuhan |
|---|---|
| **Performa** | Board dengan 200 task load < 1,5 detik (p95) |
| **Performa** | Monitoring per orang dengan 300 task load < 2 detik (p95) |
| **Performa** | Semua query task menghindari N+1 (eager loading wajib) |
| **Performa** | Agregasi monitoring memakai query agregat SQL, bukan perhitungan di PHP |
| **Keamanan** | Isolasi tenant diverifikasi di setiap request |
| **Keamanan** | CSRF, XSS escaping, rate limit pada login & undangan |
| **Keamanan** | Password di-hash bcrypt |
| **Keamanan** | Sanitasi input komentar |
| **Kompatibilitas** | Chrome, Firefox, Edge, Safari versi terkini |
| **Responsif** | Layak pakai di layar ≥ 768px; timeline & board scroll horizontal di mobile |
| **Aksesibilitas** | Drag & drop punya alternatif dropdown |
| **Bahasa** | Seluruh UI Bahasa Indonesia; format tanggal `d M Y` atau `W# MM-YY` |
| **Zona waktu** | Disimpan UTC, ditampilkan Asia/Jakarta |
| **Backup** | Backup database harian |

---

## 9. Rencana Rilis (8 Minggu)

### Fase 1 — Fondasi (Minggu 1–2)
- Setup project, PostgreSQL, layout dasar
- Migrasi & model seluruh tabel
- Auth (Fortify), profil user
- Multi-tenancy: middleware, global scope, workspace switcher
- Super Admin: CRUD workspace
- **Pest test: isolasi tenant & policy dasar**

### Fase 2 — Organisasi (Minggu 3)
- CRUD org unit + tampilan tree
- CRUD jabatan
- Sistem undangan via email
- Manajemen anggota, role, jabatan, cakupan pemantauan
- **Pest test: aturan visibilitas termasuk `unit_subtree`**

### Fase 3 — Inti (Minggu 4–5)
- CRUD project
- CRUD task bersarang 4 level + WBS otomatis
- Progress persen + aturan sinkronisasi status
- Board dengan drag & drop
- List view dengan expand hierarki
- Panel detail task

### Fase 4 — Monitoring & Timeline (Minggu 6–7)
- Halaman monitoring per orang (prioritas tertinggi di fase ini)
- Halaman monitoring per divisi + drill-down
- Timeline mingguan dengan header dua baris
- Komentar + mention
- Notifikasi in-app + polling
- Filter & sort

### Fase 5 — Pemantapan (Minggu 8)
- Perbaikan bug
- Optimasi query & index
- Seeder data contoh
- Migrasi data dari spreadsheet (skrip sekali pakai)
- Deployment & dokumentasi singkat

**Urutan pemangkasan bila waktu meleset:** Timeline (TML) dipangkas lebih dulu, karena monitoring per orang sudah menyediakan tampilan berbasis waktu. Berikutnya adalah monitoring per divisi.

---

## 10. Backlog Pasca-MVP

1. Roadmap kuartalan level portfolio (bar per project, zoom kuartal)
2. Pencarian task lintas project
3. Timeline interaktif (drag & resize)
4. Label / tag
5. Attachment file
6. Activity log per task
7. Task dependency + garis di timeline
8. Milestone
9. Notifikasi email + preferensi per user
10. Progress otomatis dihitung dari subtask (menggantikan input manual)
11. Bulk action
12. Role Viewer (read-only)
13. Custom workflow status
14. Hierarki pelaporan berbasis `manager_id` (atasan otomatis melihat bawahan)
15. Ekspor ke Excel
16. Real-time via Laravel Reverb
17. Template project

---

## 11. Risiko & Mitigasi

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| **R-1** | **Tanpa automated test menyeluruh, bug otorisasi multi-tenant bisa membocorkan data antar-company** | Tinggi | Tulis ~20 Pest test khusus isolasi tenant, policy, dan aturan `unit_subtree`. Estimasi < 1 hari, menutup risiko terbesar. Sisanya manual. |
| **R-2** | **Progress persen manual cenderung tidak akurat** — user menaruh 70–80% lalu mandek | Sedang | Tampilkan progress rollup dari anak sebagai pembanding (TSK-17). Pertimbangkan progress otomatis pasca-MVP. |
| **R-3** | **Rekalkulasi WBS mahal pada project besar** | Sedang | Batasi rekalkulasi hanya pada cabang yang terdampak, bukan seluruh project. Jalankan dalam transaction. |
| R-4 | Update `path` saat memindahkan org unit atau task gagal separuh jalan | Tinggi | Transaction; sediakan artisan command untuk rebuild `path` |
| R-5 | Query monitoring lambat saat data menumpuk | Sedang | Index `(assignee_id, status)` dan `(project_id, path)`; cache agregasi 10 menit bila perlu |
| R-6 | Scope 8 minggu terlalu ketat setelah penambahan monitoring | Tinggi | Urutan pemangkasan sudah ditetapkan di Bagian 9 |
| R-7 | Hierarki 4 level membuat UI padat di layar kecil | Rendah | Indentasi progresif mengecil; sediakan tombol collapse-all |
| R-8 | Undangan email masuk spam | Rendah | Konfigurasi SPF/DKIM; sediakan tombol salin link undangan |
| R-9 | Tim kembali ke spreadsheet karena migrasi data terasa berat | Sedang | Sediakan skrip import dari format spreadsheet lama di Fase 5 |

---

## 12. Pertanyaan Terbuka

1. Avatar diunggah ke storage lokal atau S3-compatible?
2. Saat anggota dikeluarkan dari project, task yang di-assign padanya dikosongkan atau dibiarkan?
3. Apakah perlu ekspor monitoring ke Excel untuk pelaporan ke atasan? *(Sering diminta di lingkungan yang terbiasa spreadsheet — saat ini ada di backlog nomor 15.)*
4. Berapa banyak data spreadsheet lama yang perlu dimigrasi — semua histori atau hanya yang berjalan?
5. Apakah `manager_id` perlu diisi sejak awal meski belum dipakai, agar data siap saat fiturnya dibangun?

---

## 13. Perubahan dari v1.0

| Area | Perubahan |
|---|---|
| Task | Kedalaman 2 → **4 level**, dengan `path`, `depth`, dan penomoran WBS otomatis |
| Task | **Tambah `progress`** 0–100% beserta aturan sinkronisasi dengan status |
| Penanggalan | **Bagian baru (6.6)** — tanggal disimpan asli, ditampilkan format minggu `W1 07-25` |
| Timeline | zoom minggu/bulan/kuartal, header dua baris, kolom sticky, bar merefleksikan progress |
| Monitoring | **Dua bagian baru (6.10, 6.11)** — per orang dan per divisi |
| Otorisasi | **Tambah `scope_type` dan `scope_org_unit_id`** — Kepala Divisi dapat memantau seluruh subtree tanpa didaftarkan manual |
| Organisasi | **Tambah tabel `positions`** — jabatan terpisah dari role |
| Organisasi | Tambah `manager_id` di `workspace_members` (disiapkan, belum dipakai) |
| Testing | Diubah dari "skip" menjadi "terbatas pada isolasi tenant & otorisasi" |
| Rilis | Fase 4 diprioritaskan ulang: monitoring di atas timeline |

---

## Lampiran — Ringkasan Scope MVP

**Masuk:** Auth · Super Admin · Org unit berjenjang · Jabatan · Cakupan pemantauan · Undangan · Role · Project · Task 4 level · WBS otomatis · Progress % · Board · List · Timeline mingguan · Monitoring per orang · Monitoring per divisi · Filter · Komentar · Mention · Notifikasi in-app

**Tidak masuk:** Roadmap kuartalan · Dependency · Milestone · Label · Attachment · Pencarian · Activity log · Bulk action · Notifikasi email · Real-time · Timeline interaktif · Ekspor Excel · Mobile app · API publik · Time tracking · Billing