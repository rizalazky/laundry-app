<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterOwnerController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
    ) {}

    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->subscriptions->registrationEnabled()) {
            return redirect()
                ->route('register')
                ->withErrors(['registration' => 'Pendaftaran akun baru sedang ditutup.']);
        }

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

        $owner = DB::transaction(function () use ($validated): User {
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
                'email_verification_required' => true,
            ]);

            $owner->branches()->attach($branch);

            return $owner;
        });

        Auth::login($owner);
        $request->session()->regenerate();
        $owner->sendEmailVerificationNotification();

        return redirect('/email/verify')
            ->with('status', 'Pendaftaran berhasil. Silakan verifikasi email Anda untuk memulai masa trial dan mengakses aplikasi.');
    }
}
