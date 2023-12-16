<?php

namespace App\Commands;

use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildState;
use App\Extensions\Internals\ExtensionRunner;
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
    public function handle(ExtensionRunner $extensionRunner): void
    {
        $config_file = str($this->argument('config-file') ?: str(getcwd())->finish(DIRECTORY_SEPARATOR)->append('satis.json'));

        if(!file_exists($config_file)) {
            $this->error("Config file {$config_file} does not exist");
            exit(1);
        }


        $buildConfig = new BuildState(
            temp_prefix: crc32($config_file),
            config_file_path: $config_file,
            repository_urls: $this->option('repository-url'),
            force_fresh_downloads: $this->option('fresh')
        );


        $extensionRunner->execute($this, BuildHooks::BEFORE_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig);
        $this->clearTempDirectory(
            prefix: $buildConfig->getTempPrefix()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_DOWNLOAD_FROM_S3, $buildConfig);
        if(!$buildConfig->isForceFreshDownloads()) {
            $buildConfig->setPlaceholders(
                placeholders: $this->downloadFromS3(prefix: $buildConfig->getTempPrefix())
            );
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_DOWNLOAD_FROM_S3, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY, $buildConfig);
        $this->buildSatisRepository(
            config_file: $buildConfig->getConfigFilePath(),
            prefix: $buildConfig->getTempPrefix(),
            repository_url: $buildConfig->getRepositoryUrls()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_BUILD_SATIS_REPOSITORY, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_PURGE_SATIS_REPOSITORY, $buildConfig);
        $this->purgeSatisRepository(
            config_file: $buildConfig->getConfigFilePath(),
            prefix: $buildConfig->getTempPrefix()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_PURGE_SATIS_REPOSITORY, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_UPLOAD_TO_S3, $buildConfig);
        $this->uploadToS3(
            prefix: $buildConfig->getTempPrefix(),
            placeholders: $buildConfig->getPlaceholders()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_UPLOAD_TO_S3, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_REMOVE_MISSING_FILES_FROM_S3, $buildConfig);
        $this->removeMissingFilesFromS3(
            prefix: $buildConfig->getTempPrefix()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_REMOVE_MISSING_FILES_FROM_S3, $buildConfig);


        $extensionRunner->execute($this, BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY, $buildConfig);
        $this->clearTempDirectory(
            prefix: $buildConfig->getTempPrefix()
        );
        $extensionRunner->execute($this, BuildHooks::AFTER_FINAL_CLEAR_TEMP_DIRECTORY, $buildConfig);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * Clear the temp directory / delete the generated files from the local filesystem
     */
    public function clearTempDirectory(string $prefix): void
    {
        Storage::disk('temp')->deleteDirectory($prefix);
    }

    /**
     * Download (or make placeholders) the files from S3
     */
    public function downloadFromS3(string $prefix): Collection
    {
        $placeholders = collect();

        collect(Storage::disk('s3')->allFiles())
            ->map(fn($file) => str($file))->each(function (Stringable $s3_path) use ($prefix, $placeholders) {
                $temp_path = $s3_path->start('/')->start($prefix);

                if ($s3_path->afterLast('.') == 'tar' || $s3_path->afterLast('.') == 'zip') {
                    $placeholders->push($temp_path->toString());

                    $this->line("Creating placeholder {$s3_path} in temp directory");
                    Storage::disk('temp')->put($temp_path, '');
                } else {
                    $this->line("Downloading {$s3_path} from S3");
                    Storage::disk('temp')->writeStream($temp_path, Storage::disk('s3')->readStream($s3_path));
                }
            });

        return $placeholders;
    }

    /**
     * Generate satis repository
     */
    public function buildSatisRepository(string $config_file, string $prefix, array $repository_url = null): void
    {
        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(
            new ArrayInput(
                [
                    'command' => 'build',
                    'file' => $config_file,
                    'output-dir' => (string)config('filesystems.disks.temp.root')->append($prefix)
                ] + ($repository_url ? ['--repository-url' => $repository_url] : [])
            )
        );
    }

    /**
     * Purge the non-needed files
     */
    public function purgeSatisRepository(string $config_file, string $prefix): void
    {
        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(new ArrayInput([
            'command' => 'purge',
            'file' => $config_file,
            'output-dir' => (string) config('filesystems.disks.temp.root')->append($prefix)
        ]));
    }

    /**
     * Delete the files from S3 that are missing from the temp directory
     */
    public function removeMissingFilesFromS3(int $prefix): void
    {
        $all_local_files = collect(Storage::disk('temp')->allFiles($prefix))
            ->map(fn($file) => str($file))
            ->map(fn(Stringable $temp_path) => $temp_path->after($prefix)->ltrim('/'));

        collect(Storage::disk('s3')->allFiles())
            ->map(fn($file) => str($file))
            ->diff($all_local_files)
            ->each(function (Stringable $s3_path) {
                $this->line("Deleting {$s3_path} because it is missing");
                Storage::disk('s3')->delete($s3_path);
            });
    }

    /**
     * Upload the generated files to S3
     */
    public function uploadToS3(int $prefix, Collection $placeholders): void
    {
        collect(Storage::disk('temp')->allFiles($prefix))
            ->map(fn($file) => str($file))
            ->tap(function (Collection $files) use ($prefix, $placeholders) {
                [$placeholder_files, $normal_files] = $files->partition(fn(Stringable $file) => $placeholders->contains($file->toString()));

                $placeholder_files->each(function (Stringable $temp_path) use ($prefix) {
                    $s3_path = $temp_path->after($prefix)->ltrim('/');

                    if (Storage::disk('temp')->size($temp_path) > 0) {
                        $this->line("Uploading {$s3_path} to S3");
                        Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                    } else {
                        $this->line("Skipping {$s3_path} because it is a placeholder");
                    }
                });

                $normal_files->each(function (Stringable $temp_path) use ($prefix) {
                    $s3_path = $temp_path->after($prefix)->ltrim('/');

                    $this->line("Uploading {$s3_path} to S3");
                    Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                });
            });
    }
}
