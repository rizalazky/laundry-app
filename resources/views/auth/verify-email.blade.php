<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Email — {{ config('app.name', 'Laundry App') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-xl items-center justify-center px-4 py-10">
        <div class="w-full rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-10">
            <p class="text-sm font-semibold uppercase tracking-wider text-amber-700">Satu langkah lagi</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight">Verifikasi alamat email Anda</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                Kami sudah mengirim tautan verifikasi ke <strong>{{ auth()->user()->email }}</strong>.
                Verifikasi email untuk mengaktifkan masa trial dan membuka akses aplikasi.
            </p>

            @if (session('status') === 'verification-link-sent')
                <p class="mt-5 rounded-lg bg-green-50 p-3 text-sm text-green-700">
                    Tautan verifikasi baru berhasil dikirim.
                </p>
            @endif

            @if (session('status') && session('status') !== 'verification-link-sent')
                <p class="mt-5 rounded-lg bg-green-50 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
                @csrf
                <button type="submit" class="w-full rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-slate-950 shadow-sm transition hover:bg-amber-400">
                    Kirim ulang email verifikasi
                </button>
            </form>
        </div>
    </main>
</body>
</html>
