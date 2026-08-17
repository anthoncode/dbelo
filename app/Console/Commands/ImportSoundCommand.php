<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSoundUpload;
use App\Models\Category;
use App\Models\License;
use App\Models\User;
use App\Services\AudioProcessor;
use App\Services\SoundImporter;
use Illuminate\Console\Command;

/**
 * Bulk import from the terminal. Shares SoundImporter with the web
 * uploader, so both produce identical records.
 *
 *   php artisan sounds:import ~/Desktop/rain.wav --sync
 *   php artisan sounds:import ~/Desktop/sfx --category=nature
 */
class ImportSoundCommand extends Command
{
    protected $signature = 'sounds:import
                            {path : Audio file or folder to import}
                            {--title= : Title (defaults to the file name)}
                            {--category= : Category slug}
                            {--license=dbelo-standard : License slug}
                            {--user= : Uploader email (defaults to the first admin)}
                            {--sync : Process immediately instead of queueing}';

    protected $description = 'Import audio files into the sound bank';

    public function handle(AudioProcessor $audio, SoundImporter $importer): int
    {
        if (! $audio->isAvailable()) {
            $this->error('ffmpeg is not installed. Run: brew install ffmpeg');

            return self::FAILURE;
        }

        $path = str_replace('~', $_SERVER['HOME'] ?? '', $this->argument('path'));

        $files = is_dir($path)
            ? collect(glob(rtrim($path, '/').'/*.{wav,WAV,mp3,MP3,aiff,AIFF,flac,FLAC,ogg,OGG}', GLOB_BRACE))
            : collect([$path]);

        $files = $files->filter(fn ($f) => is_file($f))->values();

        if ($files->isEmpty()) {
            $this->error("No audio files found at: {$path}");

            return self::FAILURE;
        }

        $user = $this->option('user')
            ? User::where('email', $this->option('user'))->firstOrFail()
            : User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $license = License::where('slug', $this->option('license'))->first();

        $category = $this->option('category')
            ? Category::where('slug', $this->option('category'))->first()
            : null;

        if ($this->option('category') && ! $category) {
            $this->error("Category not found: {$this->option('category')}");

            return self::FAILURE;
        }

        $this->info("Importing {$files->count()} file(s)...");

        $single = $files->count() === 1;

        foreach ($files as $file) {
            $attributes = $single && $this->option('title')
                ? ['title' => $this->option('title')]
                : [];

            $sound = $importer->import($file, basename($file), $user, $category, $license, $attributes);

            if ($this->option('sync')) {
                ProcessSoundUpload::dispatchSync($sound);
                $sound->refresh();
                $this->line("  <info>✓</info> {$sound->title} — {$sound->durationForHumans()}, status: {$sound->status}");
            } else {
                ProcessSoundUpload::dispatch($sound);
                $this->line("  <info>+</info> {$sound->title} — queued");
            }
        }

        $this->newLine();

        $this->info($this->option('sync')
            ? 'Done.'
            : 'Queued. Run "php artisan queue:work" to process them.');

        return self::SUCCESS;
    }
}
