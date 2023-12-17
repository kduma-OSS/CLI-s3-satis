<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildState;
use App\Extensions\Internals\PluginConfig;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[BuildExtension(name: 'Cache', key: 'cache')]
class CacheExtension
{
    #[BuildHook(BuildHooks::BEFORE_DOWNLOAD_FROM_S3)]
    public function loadFromCache(PluginConfig $config, BuildState $buildState, Command $command): bool {
        if($buildState->isForceFreshDownloads()) {
            return true;
        }

        $placeholders = $buildState->getPlaceholders();
        $crc = $buildState->getCrc();

        $path = str($this->getCachePath($config))->finish(DIRECTORY_SEPARATOR);
        $filesystem = Storage::createLocalDriver(['root' => $path]);

        if(!$filesystem->exists('packages.json')) {
            return true;
        }

        $work_path = config('filesystems.disks.temp.root')->append($buildState->getTempPrefix())->append(DIRECTORY_SEPARATOR);

        collect($filesystem->allFiles())
            ->map(function (string $file) use ($buildState, $work_path, $path) {
                return [
                    'file' => str($file),
                    'path' => $work_path->append($file),
                    'fs_path' => str($file)->start('/')->start($buildState->getTempPrefix()),
                    'cache_path' => $path->append($file),
                ];
            })
            ->each(function (array $file) use ($command, $crc, $placeholders) {
                if (filesize($file['cache_path']) === 0) {
                    $placeholders->push($file['fs_path']->toString());
                } else {
                    $crc[$file['fs_path']->toString()] = crc32(file_get_contents($file['cache_path']));
                }

                $command->line("Moving {$file['file']} from cache.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                $directory = dirname($file['path']);
                if(!is_dir($directory) && !mkdir($directory, recursive: true)) {
                    throw new \Exception("Unable to create directory {$directory}.");
                }

                rename($file['cache_path'], $file['path']);
            });

        $buildState->setPlaceholders($placeholders);
        $buildState->setCrc($crc);

        return false;
    }


    #[BuildHook(BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY)]
    public function saveToCache(PluginConfig $config, BuildState $buildState, Command $command): void {
        $path = str($this->getCachePath($config))->finish(DIRECTORY_SEPARATOR);
        $filesystem = Storage::createLocalDriver(['root' => $path]);

        $work_path = config('filesystems.disks.temp.root');

        collect(Storage::disk('temp')->allFiles($buildState->getTempPrefix()))
            ->map(function (string $file) use ($buildState, $work_path, $path) {
                $work_name = str($file)->after($buildState->getTempPrefix())->after(DIRECTORY_SEPARATOR);
                return [
                    'file' => $work_name,
                    'path' => $work_path->append($file),
                    'cache_path' => $path->append($work_name),
                ];
            })
            ->each(function (array $file) use ($command) {
                $directory = dirname($file['cache_path']);
                if(!is_dir($directory) && !mkdir($directory, recursive: true)) {
                    throw new \Exception("Unable to create directory {$directory}.");
                }

                if(str($file['file'])->endsWith(['.zip', '.tar'])) {
                    $command->line("Skipping caching {$file['file']} - placeholder was created instead.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                    touch($file['cache_path']);
                    return;
                }

                $command->line("Moving {$file['file']} to cache.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                rename($file['path'], $file['cache_path']);
            });
    }



    protected function getCachePath(PluginConfig $config): false|string
    {
        if($config->has('path')) {
            $path = $config->get('path', '');
        } else {
            $path = 'temp';
        }

        if($path === '') {
            return false;
        }

        if($path === 'temp') {
            $path = config('filesystems.disks.temp.root')->append('cache')->finish(DIRECTORY_SEPARATOR);
        }

        if(!is_dir($path) && file_exists($path)) {
            return false;
        }

        if(!file_exists($path) && !mkdir($path, recursive: true)) {
            return false;
        }

        return realpath($path);
    }
}
