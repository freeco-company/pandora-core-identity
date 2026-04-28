<?php

namespace App\Providers;

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
    }
}
