<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            /*
             * Log Viewer bukan halaman Filament — rutenya milik
             * opcodesio/log-viewer dan berdiri di luar panel. Yang ada di sini
             * cuma tautannya, supaya menunya tetap ketemu dari tempat yang
             * sama dengan Backup dan Octane.
             *
             * `visible()` di sini murni kosmetik: yang benar-benar menjaga
             * halamannya adalah gate `viewLogViewer` di AppServiceProvider.
             * Menyembunyikan tautan tanpa gate itu tidak menutup apa pun —
             * URL-nya bisa diketik langsung.
             */
            ->navigationItems([
                NavigationItem::make('log-viewer')
                    ->label(__('Log Viewer'))
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->url(fn (): string => url((string) config('log-viewer.route_path')), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => (bool) config('log-viewer.enabled')
                        && (bool) auth()->user()?->can('View:LogViewer'))
                    ->sort(87),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
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
                /*
                 * Pencatat kunjungan milik binafy/laravel-user-monitoring.
                 *
                 * Dipasang di panel, BUKAN di grup `web` global. Grup global
                 * akan ikut mencatat halaman welcome dan tiap rute publik yang
                 * lahir nanti; yang benar-benar layak dipantau di aplikasi ini
                 * cuma panel adminnya.
                 *
                 * Paketnya sendiri hanya memasang middleware ini pada rute
                 * bawaannya — yang di repo ini dikosongkan — jadi tanpa baris
                 * ini tidak ada satu pun kunjungan yang tercatat.
                 */
                VisitMonitoringMiddleware::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            /*
             * Shield membawa RoleResource-nya sendiri beserta editor izin
             * bertab. Ia menggantikan resource Role dan Izin buatan sendiri
             * yang dulu ada di app/Filament/Resources.
             */
            ->plugin(FilamentShieldPlugin::make());
    }
}
