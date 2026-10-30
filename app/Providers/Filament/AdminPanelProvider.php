<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->login(\App\Filament\Auth\Login::class)
            ->profile()
            ->brandName(config('app.name', 'iZone'))
            ->brandLogo(asset('images/logo.svg'))
            ->darkModeBrandLogo(asset('images/logo-dark.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/favicon.svg'))
            ->font('Inter')
            ->darkMode(false)
            ->topbar(false)
            ->sidebarWidth('17rem')
            ->maxContentWidth('full')
            ->navigationGroups(['Penjualan', 'Barang', 'Keuangan', 'Karyawan', 'Data master', 'Pengaturan', 'Bantuan'])
            ->renderHook(\Filament\View\PanelsRenderHook::PAGE_START, function () {
                $topic = \App\Filament\Pages\Help::TOPIC_BY_PAGE[basename(request()->path())] ?? null;

                return $topic ? '<a href="'.e(\App\Filament\Pages\Help::getUrl().'#'.$topic).'" class="iz-help-link">Bingung? Lihat cara memakai halaman ini</a>' : '';
            })
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_END, fn () => '<link rel="stylesheet" href="'.asset('css/izone.css').'?v='.@filemtime(public_path('css/izone.css')).'">')
            ->colors([
                'primary' => '#0B63F6',
                'success' => '#0E7A4F',
                'warning' => '#B45309',
                'danger' => '#B42318',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                \App\Filament\Pages\Home::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
