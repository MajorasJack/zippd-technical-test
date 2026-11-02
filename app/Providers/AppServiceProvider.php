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
     * The alert carries the job's UUID, which is the ID `queue:failed` and `queue:retry` use.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::channel('alerts')->critical(
                sprintf('Job %s failed for good', $event->job->resolveName()),
                [
                    'job_uuid' => $event->job->uuid(),
                    'exception' => $event->exception->getMessage(),
                ],
            );
        });
    }
}
