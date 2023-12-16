<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[BuildExtension(name: 'File Restrictions Map File Generator')]
class FileRestrictionsMapFileGeneratorExtension
{
    #[BuildHook(BuildHooks::AFTER_PURGE_SATIS_REPOSITORY)]
    public function hook(BuildStateInterface $buildState): void
    {
        $this->prepareFileRestrictionsMapFile($buildState->getConfigFilePath(), $buildState->getTempPrefix());
    }

    /**
     * Prepare file restrictions map file
     */
    public function prepareFileRestrictionsMapFile(mixed $config_file, int $temp_subdirectory): void
    {
        $homepage = json_decode(file_get_contents($config_file), true)['homepage'];
        $packages = json_decode(Storage::disk('temp')->get(str('packages.json')->start('/')->start($temp_subdirectory)), true);
        $packages = collect($packages['available-packages'])
            ->map(fn($package) => [
                str($packages['metadata-url'])->replace('%package%', $package),
                str($packages['metadata-url'])->replace('%package%', $package . '~dev'),
            ])
            ->flatten()
            ->map(fn($path) => json_decode(Storage::disk('temp')->get($path->start('/')->start($temp_subdirectory)), true))
            ->pluck('packages')
            ->map(function ($packages) {
                return collect($packages)
                    ->map(function ($versions, $package) {
                        return collect($versions)
                            ->filter(fn($version) => isset($version['dist']['url']))
                            ->map(fn($version) => [
                                'package' => $package,
                                'url' => $version['dist']['url'],
                                'version' => $version['version_normalized'],
                            ]);
                    })
                    ->flatten(1);
            })
            ->flatten(1)
            ->map(function ($package) use ($homepage) {
                $package['url'] = str($package['url'])->replace($homepage, '')->ltrim('/')->toString();

                return $package;
            })
            ->map(function ($package) use ($homepage) {
                $package['tags'][] = "{$package['package']}:{$package['version']}";

                if (preg_match('/^(\\d)\\.(\\d)\\.(\\d)\\.(\\d)$/u', $package['version'], $matches)) {
                    $package['tags'][] = "{$package['package']}:{$matches[1]}.{$matches[2]}.{$matches[3]}.x";
                    $package['tags'][] = "{$package['package']}:{$matches[1]}.{$matches[2]}.x";
                    $package['tags'][] = "{$package['package']}:{$matches[1]}.x";
                }

                return $package;
            })
            ->groupBy('url')
            ->map(function (Collection $packages) {
                return $packages->pluck('tags')->flatten();
            });
        Storage::disk('temp')->put(str('file_restrictions.json')->start('/')->start($temp_subdirectory), json_encode($packages->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
