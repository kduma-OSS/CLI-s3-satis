<?php

namespace App\Commands;

use Composer\Satis\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;

use Symfony\Component\Console\Input\ArrayInput;

class BuildCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build
        {config-file? : The path to the satis config file.}
        {--repository-url=* : Only update the repository at given URL(s).}
        {--fresh : Force a rebuild of all packages.}
    ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates satis repository on S3 bucket';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $config_file = str($this->argument('config-file') ?: str(getcwd())->finish(DIRECTORY_SEPARATOR)->append('satis.json'));

        if(!file_exists($config_file)) {
            $this->error("Config file {$config_file} does not exist");
            exit(1);
        }

        $temp_subdirectory = crc32($config_file);


        // Clear the temp directory
        Storage::disk('temp')->deleteDirectory($temp_subdirectory);

        $placeholders = collect();

        $all_s3_files = collect(Storage::disk('s3')->allFiles())
            ->map(fn($file) => str($file));

        if (!$this->option('fresh')) {
            // Download (or make placeholders) the files from S3
            $all_s3_files->each(function (Stringable $s3_path) use ($temp_subdirectory, $placeholders) {
                    $temp_path = $s3_path->start('/')->start($temp_subdirectory);

                    if ($s3_path->afterLast('.') == 'tar' || $s3_path->afterLast('.') == 'zip') {
                        $placeholders->push($temp_path->toString());

                        $this->info("Creating placeholder {$s3_path} in temp directory");
                        Storage::disk('temp')->put($temp_path, '');
                    } else {
                        $this->info("Downloading {$s3_path} from S3");
                        Storage::disk('temp')->writeStream($temp_path, Storage::disk('s3')->readStream($s3_path));
                    }
                });
        }

        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        // Generate satis repository
        $application->run(
            new ArrayInput(
                [
                    'command' => 'build',
                    'file' => (string) $config_file,
                    'output-dir' => (string) config('filesystems.disks.temp.root')->append($temp_subdirectory)
                ] + ($this->option('repository-url') ? ['--repository-url' => $this->option('repository-url')] : [])
            )
        );

        // Purge the non-needed files
        $application->run(new ArrayInput([
            'command' => 'purge',
            'file' => (string) $config_file,
            'output-dir' => (string) config('filesystems.disks.temp.root')->append($temp_subdirectory)
        ]));


        // Upload the generated files to S3
        collect(Storage::disk('temp')->allFiles($temp_subdirectory))
            ->map(fn($file) => str($file))
            ->tap(function (Collection $files) use ($temp_subdirectory, $placeholders) {
                [$placeholder_files, $normal_files] = $files->partition(fn(Stringable $file) => $placeholders->contains($file->toString()));

                $placeholder_files->each(function (Stringable $temp_path) use ($temp_subdirectory) {
                    $s3_path = $temp_path->after($temp_subdirectory)->ltrim('/');

                    if(Storage::disk('temp')->size($temp_path) > 0) {
                        $this->info("Uploading {$s3_path} to S3");
                        Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                    } else {
                        $this->info("Skipping {$s3_path} because it is a placeholder");
                    }
                });

                $normal_files->each(function (Stringable $temp_path) use ($temp_subdirectory) {
                    $s3_path = $temp_path->after($temp_subdirectory)->ltrim('/');

                    $this->info("Uploading {$s3_path} to S3");
                    Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                });
            })
        ;

        $all_local_files = collect(Storage::disk('temp')->allFiles($temp_subdirectory))
            ->map(fn($file) => str($file))
            ->map(fn(Stringable $temp_path) => $temp_path->after($temp_subdirectory)->ltrim('/'));

        // Delete the files from S3 that are missing from the temp directory
        $all_s3_files->diff($all_local_files)
            ->each(function (Stringable $s3_path) {
                $this->info("Deleting {$s3_path} because it is missing");
                Storage::disk('s3')->delete($s3_path);
            });

        // Delete the generated files from the local filesystem
        Storage::disk('temp')->deleteDirectory($temp_subdirectory);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
