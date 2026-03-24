<?php

use Illuminate\Support\Facades\File;

it('removes old telegram job directories and keeps fresh directories', function (): void {
    $basePath = storage_path('app/private/telegram/jobs');
    $oldPath = $basePath.'/old-job';
    $newPath = $basePath.'/new-job';

    File::deleteDirectory($basePath);
    File::ensureDirectoryExists($oldPath);
    File::ensureDirectoryExists($newPath);

    File::put($oldPath.'/sample.txt', 'old');
    File::put($newPath.'/sample.txt', 'new');

    touch($oldPath, now()->subHours(100)->getTimestamp());
    touch($newPath, now()->getTimestamp());

    $this->artisan('telegram:cleanup --hours=72')
        ->expectsOutputToContain('Cleanup completed')
        ->assertSuccessful();

    expect(File::isDirectory($oldPath))->toBeFalse();
    expect(File::isDirectory($newPath))->toBeTrue();
});
