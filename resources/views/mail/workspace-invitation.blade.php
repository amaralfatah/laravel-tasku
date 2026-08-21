<x-mail::message>
# Undangan bergabung ke {{ $workspace->name }}

Anda diundang bergabung ke workspace **{{ $workspace->name }}** sebagai **{{ $roleLabel }}**.

Klik tombol di bawah untuk menerima undangan dan menyiapkan akun Anda.

<x-mail::button :url="$acceptUrl">
Terima undangan
</x-mail::button>

Undangan ini berlaku sampai **{{ $expiresAt }} WIB**.

Jika tombol tidak berfungsi, salin tautan berikut ke browser Anda:

{{ $acceptUrl }}

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
