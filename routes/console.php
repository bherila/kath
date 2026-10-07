<?php

use App\Services\Wedding\HlsService;
use App\Services\Wedding\PhotoClusterService;
use App\Services\Wedding\WeddingUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('wedding:prune-uploads', function (WeddingUploadService $uploads) {
    $result = $uploads->prunePending();
    $this->info("Discarded {$result['discarded']} abandoned upload(s); {$result['retained']} kept for retry.");
})->purpose('Delete wedding uploads that were never completed, and retry failed object deletes');

Schedule::command('wedding:prune-uploads')->hourly()->withoutOverlapping();

Artisan::command('wedding:resolve-videos', function (HlsService $hls) {
    $this->info('Resolved '.$hls->resolvePendingVideos().' newly transcoded video(s).');
})->purpose('Pick up finished HLS transcodes for guest videos and hide duplicate videos');

Schedule::command('wedding:resolve-videos')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('wedding:recluster-photos', function (PhotoClusterService $clusters) {
    $this->info($clusters->rebuild().' photo(s) shown under a better copy.');
})->purpose('Rebuild near-identical photo clusters from stored hashes (e.g. after changing the match distance)');
