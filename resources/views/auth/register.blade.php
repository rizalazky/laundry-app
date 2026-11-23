<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Pemilik Laundry — {{ config('app.name', 'Laundry App') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center justify-center px-4 py-10">
        <div class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <a href="{{ route('home') }}" class="text-sm font-semibold text-amber-700">← Laundry App</a>

            <div class="mt-6">
                <p class="text-sm font-semibold uppercase tracking-wider text-amber-700">Mulai kelola bisnis Anda</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight">Daftar pemilik laundry</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Buat akun usaha. Kami akan menyiapkan tenant dan outlet pertama secara otomatis.
                </p>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">Periksa kembali data pendaftaran:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="mt-8 space-y-6">
                @csrf

                <section class="space-y-4">
                    <h2 class="border-b border-slate-100 pb-2 text-sm font-semibold text-slate-900">Informasi pemilik</h2>
                    <div>
                        <label for="owner_name" class="mb-1.5 block text-sm font-medium">Nama lengkap</label>
                        <input id="owner_name" name="owner_name" type="text" value="{{ old('owner_name') }}" required autocomplete="name"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="email" class="mb-1.5 block text-sm font-medium">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                        </div>
                        <div>
                            <label for="phone" class="mb-1.5 block text-sm font-medium">No. WhatsApp (opsional)</label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <h2 class="border-b border-slate-100 pb-2 text-sm font-semibold text-slate-900">Informasi usaha</h2>
                    <div>
                        <label for="business_name" class="mb-1.5 block text-sm font-medium">Nama usaha / laundry</label>
                        <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="branch_name" class="mb-1.5 block text-sm font-medium">Nama outlet pertama (opsional)</label>
                        <input id="branch_name" name="branch_name" type="text" value="{{ old('branch_name', 'Outlet Utama') }}"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="address" class="mb-1.5 block text-sm font-medium">Alamat outlet (opsional)</label>
                        <textarea id="address" name="address" rows="2"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">{{ old('address') }}</textarea>
                    </div>
                </section>

                <section class="space-y-4">
                    <h2 class="border-b border-slate-100 pb-2 text-sm font-semibold text-slate-900">Keamanan akun</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="password" class="mb-1.5 block text-sm font-medium">Password</label>
                            <input id="password" name="password" type="password" required autocomplete="new-password"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                            <p class="mt-1 text-xs text-slate-500">Minimal 8 karakter.</p>
                        </div>
                        <div>
                            <label for="password_confirmation" class="mb-1.5 block text-sm font-medium">Konfirmasi password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                        </div>
                    </div>
                </section>

                <button type="submit" class="w-full rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-slate-950 shadow-sm transition hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                    Buat akun dan outlet pertama
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Sudah punya akun?
                <a href="{{ route('filament.app.auth.login') }}" class="font-semibold text-amber-700 hover:text-amber-800">Masuk di sini</a>
            </p>
        </div>
    </main>
</body>
</html>
