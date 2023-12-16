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
use Symfony\Component\Console\Output\OutputInterface;

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
        {--set-extension=* : Configures the build to use the given extension(s).}
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

        if (! file_exists($config_file)) {
            $this->error("Config file {$config_file} does not exist");
            exit(1);
        }

        $buildConfig = new BuildState(
            temp_prefix: crc32($config_file),
            config_file_path: $config_file,
            repository_urls: $this->option('repository-url'),
            force_fresh_downloads: $this->option('fresh')
        );

        $extensionRunner->enableExtensionFromBuildState($this, $buildConfig);

        if($this->option('set-extension')) {
            $runtime_extensions = collect($this->option('set-extension'));

            if ($runtime_extensions->filter(fn($name) => !$name)->count()) {
                $this->error('Extension names cannot be empty');
                exit(1);
            }

            $extensionRunner->enableExtensionFromRunOptions($this, $runtime_extensions);
        }

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Clearing temp directory');
            $this->clearTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping clearing temp directory');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_CREATE_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Creating temp directory');
            $this->createTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping creating temp directory');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_CREATE_TEMP_DIRECTORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_DOWNLOAD_FROM_S3, $buildConfig)) {
            if (! $buildConfig->isForceFreshDownloads()) {
                $this->info('Downloading from S3');
                [$placeholders, $crc] = $this->downloadFromS3(prefix: $buildConfig->getTempPrefix());
                $buildConfig->setPlaceholders(
                    placeholders: $placeholders
                );
                $buildConfig->setCrc(
                    crc: $crc
                );
            } else {
                $this->info('Skipping downloading from S3 because --fresh was passed');
            }
        } else {
            $this->info('Skipping downloading from S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_DOWNLOAD_FROM_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY, $buildConfig)) {
            $this->info('Building satis repository');
            $this->buildSatisRepository(
                config_file: $buildConfig->getConfigFilePath(),
                prefix: $buildConfig->getTempPrefix(),
                repository_url: $buildConfig->getRepositoryUrls()
            );
        } else {
            $this->info('Skipping building satis repository');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_BUILD_SATIS_REPOSITORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_UPLOAD_TO_S3, $buildConfig)) {
            $this->info('Uploading to S3');
            $this->uploadToS3(
                prefix: $buildConfig->getTempPrefix(),
                placeholders: $buildConfig->getPlaceholders(),
                crc: $buildConfig->getCrc()
            );
        } else {
            $this->info('Skipping uploading to S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_UPLOAD_TO_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_REMOVE_MISSING_FILES_FROM_S3, $buildConfig)) {
            $this->info('Removing missing files from S3');
            $this->removeMissingFilesFromS3(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping removing missing files from S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_REMOVE_MISSING_FILES_FROM_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Clearing temp directory');
            $this->clearTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping clearing temp directory');
        }
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
     * Create the temp directory
     */
    protected function createTempDirectory(string $prefix): void
    {
        Storage::disk('temp')->makeDirectory($prefix);
    }

    /**
     * Download (or make placeholders) the files from S3
     */
    protected function downloadFromS3(string $prefix): array
    {
        $placeholders = collect();
        $crc = collect();

        collect(Storage::disk('s3')->allFiles())
            ->map(fn ($file) => str($file))->each(function (Stringable $s3_path) use ($prefix, $placeholders, $crc) {
                $temp_path = $s3_path->start('/')->start($prefix);

                if ($s3_path->afterLast('.') == 'tar' || $s3_path->afterLast('.') == 'zip') {
                    $placeholders->push($temp_path->toString());

                    $this->line("Creating placeholder {$s3_path} in temp directory", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                    Storage::disk('temp')->put($temp_path, '');
                } else {
                    $this->line("Downloading {$s3_path} from S3", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                    Storage::disk('temp')->writeStream($temp_path, Storage::disk('s3')->readStream($s3_path));
                    $crc[$temp_path->toString()] = crc32(Storage::disk('temp')->get($temp_path));
                }
            });

        return [$placeholders, $crc];
    }

    /**
     * Generate satis repository
     */
    protected function buildSatisRepository(string $config_file, string $prefix, ?array $repository_url = null): void
    {
        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(
            new ArrayInput(
                [
                    'command' => 'build',
                    'file' => $config_file,
                    'output-dir' => (string) config('filesystems.disks.temp.root')->append($prefix),
                ] + ($repository_url ? ['--repository-url' => $repository_url] : [])
            )
        );
    }

    /**
     * Delete the files from S3 that are missing from the temp directory
     */
    protected function removeMissingFilesFromS3(int $prefix): void
    {
        $all_local_files = collect(Storage::disk('temp')->allFiles($prefix))
            ->map(fn ($file) => str($file))
            ->map(fn (Stringable $temp_path) => $temp_path->after($prefix)->ltrim('/'));

        collect(Storage::disk('s3')->allFiles())
            ->map(fn ($file) => str($file))
            ->diff($all_local_files)
            ->each(function (Stringable $s3_path) {
                $this->line("Deleting {$s3_path} because it is missing", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                Storage::disk('s3')->delete($s3_path);
            });
    }

    /**
     * Upload the generated files to S3
     */
    protected function uploadToS3(int $prefix, Collection $placeholders, Collection $crc): void
    {
        collect(Storage::disk('temp')->allFiles($prefix))
            ->map(fn ($file) => str($file))
            ->tap(function (Collection $files) use ($prefix, $placeholders, $crc) {
                [$placeholder_files, $normal_files] = $files->partition(fn (Stringable $file) => $placeholders->contains($file->toString()));

                $placeholder_files->each(function (Stringable $temp_path) use ($prefix) {
                    $s3_path = $temp_path->after($prefix)->ltrim('/');

                    if (Storage::disk('temp')->size($temp_path) > 0) {
                        $this->line("Uploading {$s3_path} to S3", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                        Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                    } else {
                        $this->line("Skipping {$s3_path} because it is a placeholder", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                    }
                });

                $normal_files->each(function (Stringable $temp_path) use ($crc, $prefix) {
                    $s3_path = $temp_path->after($prefix)->ltrim('/');

                    if($crc->has($temp_path->toString())) {
                        $this->line("Checking {$s3_path} for changes", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                        $local_crc = crc32(Storage::disk('temp')->get($temp_path));
                        if ($crc[$temp_path->toString()] == $local_crc) {
                            $this->line("Skipping {$s3_path} because it has not changed", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                            return;
                        }
                    }

                    $this->line("Uploading {$s3_path} to S3", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                    Storage::disk('s3')->writeStream($s3_path, Storage::disk('temp')->readStream($temp_path));
                });
            });
    }
}
