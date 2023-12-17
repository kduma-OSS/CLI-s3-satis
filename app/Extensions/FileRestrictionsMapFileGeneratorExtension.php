<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[BuildExtension(name: 'File Restrictions Map File Generator', key: 'file-restrictions-map-generator')]
class FileRestrictionsMapFileGeneratorExtension
{
    /**
     * Prepare file restrictions map file
     */
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(BuildStateInterface $buildState): void
    {
        $url_host = $buildState->getConfig()
            ->get('archive', collect())
            ->get('prefix-url', $buildState->getConfig()->get('homepage'));

        $packages = json_decode(Storage::disk('temp')->get(str('packages.json')->start('/')->start($buildState->getTempPrefix())), true);
        $packages = collect($packages['available-packages'])
            ->map(fn ($package) => [
                str($packages['metadata-url'])->replace('%package%', $package),
                str($packages['metadata-url'])->replace('%package%', $package.'~dev'),
            ])
            ->flatten()
            ->map(fn ($path) => json_decode(Storage::disk('temp')->get($path->start('/')->start($buildState->getTempPrefix())), true))
            ->pluck('packages')
            ->map(function ($packages) {
                return collect($packages)
                    ->map(function ($versions, $package) {
                        return collect($versions)
                            ->filter(fn ($version) => isset($version['dist']['url']))
                            ->map(fn ($version) => [
                                'package' => $package,
                                'url' => $version['dist']['url'],
                                'version' => $version['version_normalized'],
                            ]);
                    })
                    ->flatten(1);
            })
            ->flatten(1)
            ->map(function ($package) use ($url_host) {
                $package['url'] = str($package['url'])->replace($url_host, '')->ltrim('/')->toString();

                return $package;
            })
            ->map(function ($package) {
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
            })
            ->filter(fn (Collection $tags, string $url) => ! str($url)->startsWith(['http://', 'https://']));

        Storage::disk('temp')->deleteDirectory('.tags');
        $packages->each(function (Collection $tags, string $url) use ($buildState) {
            $path = str('.tags')->append('/')->append($url)->append('.json')->start('/')->start($buildState->getTempPrefix());
            Storage::disk('temp')->put($path, $tags->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });
    }
}
