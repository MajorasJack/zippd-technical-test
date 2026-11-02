<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Every job that fails for good raises one critical alert, so no job needs its own alerting.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::channel('alerts')->critical(sprintf('Job %s failed for good', $event->job->resolveName()), [
                'job_id' => $event->job->getJobId(),
                'exception' => $event->exception->getMessage(),
            ]);
        });
    }
}
