<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        foreach([\App\Integrations\StudentInformation::class,\App\Integrations\InstitutionalIdentity::class,\App\Integrations\MessageDelivery::class,\App\Integrations\OfficialDocuments::class] as $contract) $this->app->bind($contract,\App\Integrations\UnavailableIntegration::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::define('record-view', fn($u,$r)=>app(\App\Domain\ScopeAccess::class)->can($u,'view',$r));
        \Illuminate\Support\Facades\Gate::define('record-write', fn($u,$r)=>app(\App\Domain\ScopeAccess::class)->can($u,'write',$r));
    }
}
