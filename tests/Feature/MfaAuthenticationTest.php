<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MfaAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_support_totp_authentication_and_recovery_codes(): void
    {
        $user = new User;

        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_secret'));
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_recovery_codes'));
    }

    public function test_mfa_secrets_and_recovery_codes_are_not_mass_assignable_or_serialized(): void
    {
        $user = new User;

        $this->assertNotContains('app_authentication_secret', $user->getFillable());
        $this->assertNotContains('app_authentication_recovery_codes', $user->getFillable());
        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
    }
}
