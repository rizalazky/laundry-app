<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterOwnerController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('tenants', 'owner_email'),
            ],
            'phone' => ['nullable', 'string', 'max:25'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        DB::transaction(function () use ($validated): void {
            $baseSlug = Str::slug($validated['business_name']) ?: 'laundry';
            $tenant = Tenant::query()->create([
                'name' => $validated['business_name'],
                'slug' => $baseSlug.'-'.Str::lower(Str::random(8)),
                'owner_email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
            ]);

            $branch = $tenant->branches()->create([
                'code' => 'MAIN',
                'name' => filled($validated['branch_name'] ?? null)
                    ? $validated['branch_name']
                    : 'Outlet Utama',
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'is_workshop' => false,
                'is_active' => true,
            ]);

            $owner = User::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $validated['owner_name'],
                'email' => $validated['email'],
                'phone_whatsapp' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'role' => 'tenant_owner',
                'is_active' => true,
            ]);

            $owner->branches()->attach($branch);
        });

        return redirect()
            ->route('home')
            ->with('status', 'Pendaftaran berhasil. Silakan masuk menggunakan email dan password Anda.');
    }
}
