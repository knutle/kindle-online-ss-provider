<?php

namespace App\Services;

use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Enums\Polling;

class DashboardImageRenderer
{
    public function render(string $url, int $width, int $height): string
    {
        set_time_limit(75);

        return Browsershot::url($url)
            ->setNodeModulePath(base_path('node_modules'))
            ->windowSize($width, $height)
            ->deviceScaleFactor(1)
            ->waitForFunction(
                'document.querySelector("main[data-screenshot-ready=\'true\']") !== null',
                Polling::Mutation,
                50_000,
            )
            ->timeout(65)
            ->select('.kindle-dashboard')
            ->screenshot();
    }
}
