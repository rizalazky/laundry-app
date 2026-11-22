<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\GeneralSettings;
use App\Filament\App\Pages\Tenancy\EditBranchProfile;
use App\Filament\App\Pages\Tenancy\RegisterBranch;
use App\Filament\Auth\EditUserProfile;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Models\Branch;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->brandName('Smartlink Owner')
            ->tenant(Branch::class, slugAttribute: 'code')
            ->tenantRegistration(RegisterBranch::class)
            ->tenantProfile(EditBranchProfile::class)
            ->profile(EditUserProfile::class)
            ->tenantMenuItems([
                'profile' => fn (\Filament\Actions\Action $action): \Filament\Actions\Action => $action
                    ->label('Pengaturan Cabang')
                    ->icon('heroicon-m-building-office-2'),
                'register' => fn (\Filament\Actions\Action $action): \Filament\Actions\Action => $action
                    ->label('Tambah Cabang')
                    ->icon('heroicon-m-plus'),
            ])
            ->searchableTenantMenu()
            ->tenantMiddleware([
                EnsureActiveSubscription::class,
            ], isPersistent: true)
            ->navigationGroups([
                'Operasional',
                'Pelanggan & CRM',
                'Karyawan & SDM',
                'Inventori & Distribusi',
                'Keuangan & Laporan',
                'Pengaturan',
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
                GeneralSettings::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->login()
            ->authMiddleware([
                Authenticate::class,
            ])
            ->sidebarWidth('17rem');
    }
}
