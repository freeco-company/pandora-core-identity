<?php

namespace App\Providers;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use App\Observers\GroupUserIdentityObserver;
use App\Observers\GroupUserObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Line\LineExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register SocialiteProviders community drivers (LINE, Apple)
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            (new LineExtendSocialite)->handle($event);
            (new AppleExtendSocialite)->handle($event);
        });

        // ADR-007 Phase 1: identity 變動 → outbox publisher
        GroupUser::observe(GroupUserObserver::class);
        GroupUserIdentity::observe(GroupUserIdentityObserver::class);
    }
}
