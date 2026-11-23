<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laundry App') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-5xl flex-col justify-center px-6 py-16">
        <div class="max-w-2xl">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Smartlink Owner Platform</p>
            <h1 class="mt-4 text-4xl font-bold tracking-tight sm:text-5xl">Kelola bisnis laundry dengan lebih mudah.</h1>
            <p class="mt-5 max-w-xl text-lg leading-8 text-slate-600">
                Satu platform untuk mengelola outlet, pelanggan, transaksi, produksi, inventori, karyawan, dan keuangan.
            </p>

            @if (session('status'))
                <div class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-amber-500 px-6 py-3 text-sm font-semibold text-slate-950 shadow-sm transition hover:bg-amber-400">
                    Daftar pemilik laundry
                </a>
                <a href="{{ route('filament.app.auth.login') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                    Masuk ke dashboard
                </a>
            </div>
        </div>
    </main>
</body>
</html>
