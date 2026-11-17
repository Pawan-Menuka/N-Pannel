<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\RequiredAppAuthentication;
use App\Http\Controllers\AdminCreditNoteDocumentController;
use App\Http\Controllers\AdminInvoiceDocumentController;
use App\Http\Controllers\AdminQuoteDocumentController;
use App\Http\Controllers\AdminReceiptDocumentController;
use App\Http\Middleware\RequireMultiFactorAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authenticatedRoutes(function (): void {
                Route::get('credit-notes/{creditNote}/print', AdminCreditNoteDocumentController::class)->name('credit-notes.print');
                Route::get('invoices/{invoice}/print', AdminInvoiceDocumentController::class)->name('invoices.print');
                Route::get('payments/{payment}/receipt', AdminReceiptDocumentController::class)->name('payments.receipt');
                Route::get('orders/{order}/quotes/{revision}/print', AdminQuoteDocumentController::class)
                    ->whereNumber('revision')->name('quotes.print');
            })
            ->brandName('N-Pannel')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class)
            ->multiFactorAuthentication([
                RequiredAppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->navigationGroups(['Sales', 'Inventory', 'Administration', 'Settings'])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireMultiFactorAuthentication::class,
            ], isPersistent: true);
    }
}
