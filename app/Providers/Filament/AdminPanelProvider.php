<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Widgets\StatsOverview;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Support\Branding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Whether the notifications table exists yet — guards the in-panel bell so a
     * not-yet-migrated database (new code deployed before `migrate` ran) can't
     * 500 every page. Any DB hiccup is treated as "not ready" (bell off, panel up).
     */
    protected function notificationsTableReady(): bool
    {
        // The panel is built while EVERY request boots, including the health
        // probe — which must not touch the database before it has decided
        // whether the database is answering at all. Nothing about /health
        // renders a panel, so the question is simply not asked there.
        if (HealthController::isProbe()) {
            return false;
        }

        try {
            return Schema::hasTable('notifications');
        } catch (\Throwable) {
            return false;
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class)
            ->brandName('מולטי דיגיטל')
            // Show the uploaded business logo as the panel brand when one is set.
            ->brandLogo(fn (): ?string => Branding::logoUrl())
            ->brandLogoHeight('2rem')
            // Use the same business logo as the browser-tab favicon when set.
            ->favicon(fn (): ?string => Branding::logoUrl())
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->font('Rubik')
            // In-panel notification bell (new task assigned, a site incident,
            // a customer reply…) — always visible in the panel, independent of
            // WhatsApp/email config. The bell queries the notifications table on
            // EVERY page, so only enable it once that table exists — otherwise a
            // deploy that ships new code before its migrations run would 500 every
            // panel page. Polling makes new alerts pop live (a "push"), so a
            // customer reply reaches the team without reloading the page.
            ->when($this->notificationsTableReady(), fn (Panel $panel): Panel => $panel
                ->databaseNotifications()
                ->databaseNotificationsPolling('30s'))
            // Browser (Web Push) notifications — a real desktop "pop" even when
            // this tab is in the background. The view is a no-op unless VAPID keys
            // are configured, so an install without keys is unaffected.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('webpush.register')->render())
            // And an offer, at the top of the page, for a member who has not
            // turned them on in THIS browser. Push is per-browser, so somebody
            // notified on their desktop is still un-notified on the laptop they
            // are reading this on — and the feature is worth nothing to a person
            // who never knew it was there. Dismissable, and remembered.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => view('webpush.offer')->render())
            // הכתבה קולית בכל שדה טקסט בפאנל — מאזין אחד על הדף, ולא רכיב לכל
            // טופס: בפאנל עשרות טפסים, חלקם נפתחים בחלוניות, וגישה שדורשת לגעת
            // בכל אחד מהם בנפרד נשארת חלקית ביום שאחרי.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('voice.dictation')->render())
            // כפתור הדגשה בעורכי הטקסט. לקוחות מסמנים לנו את המשפט החשוב, ומאז
            // שההדגשה שלהם שורדת את הסינון היה מוזר שאי אפשר להשיב באותה שפה.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('editor.highlight')->render())
            // "התחברות עם גוגל" above the password form — it is the way the
            // team actually signs in, and the password stays below for whoever
            // still needs it. The view renders nothing when Google is not
            // configured, so an install that never set it up is unaffected.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => view('auth.google-button')->render())
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(['תמיכה', 'כספים', 'ניהול'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureTwoFactorConfirmed::class,
            ]);
    }
}
