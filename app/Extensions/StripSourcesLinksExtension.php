<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;

#[BuildExtension(name: 'Strip Sources Links', key: 'strip-sources-links')]
class StripSourcesLinksExtension
{
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(BuildStateInterface $buildState): void
    {
        $packages_path = str('packages.json')->start('/')->start($buildState->getTempPrefix());
        $packages = json_decode(Storage::disk('temp')->get($packages_path), true);
        $packages = collect($packages)->recursive();

        $packages->get('available-packages', collect())
            ->map(fn ($package) => [
                str($packages->get('metadata-url'))->replace('%package%', $package),
                str($packages->get('metadata-url'))->replace('%package%', $package.'~dev'),
            ])
            ->flatten()
            ->map(fn (Stringable $path) => $this->processPackagesFile($path, $buildState));

        $packages['includes'] = $packages->get('includes', collect())
            ->mapWithKeys(function ($parameters, $path) use ($buildState) {
                $this->processIncludesFile(str($path), $buildState);

                dump($parameters);

                if ($parameters->has('sha1')) {
                    $old_sha1 = $parameters->get('sha1');

                    $parameters['sha1'] = sha1_file(
                        config('filesystems.disks.temp.root')
                            ->append(
                                str($path)->start('/')->start($buildState->getTempPrefix())
                            )
                    );

                    $newPath = str($path)->replace($old_sha1, $parameters['sha1'])->toString();

                    Storage::disk('temp')->move(
                        str($path)->start('/')->start($buildState->getTempPrefix()),
                        str($newPath)->start('/')->start($buildState->getTempPrefix())
                    );

                    return [$newPath => $parameters];
                }

            });

        Storage::disk('temp')->put($packages_path, json_encode($packages->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function processPackagesFile(Stringable $path, BuildStateInterface $buildState): void
    {
        $path = $path->start('/')->start($buildState->getTempPrefix());

        /** @var Collection $json */
        $json = collect(json_decode(Storage::disk('temp')->get($path), true))->recursive();

        $json->get('packages', collect())
            ->map(function (Collection $versions, string $package) {
                $versions->map(function (Collection $version, int $key) {
                    if ($version->has('source')) {
                        $version->forget('source');
                    }
                });
            });

        Storage::disk('temp')->put($path, json_encode($json->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function processIncludesFile(Stringable $path, BuildStateInterface $buildState): void
    {
        $path = $path->start('/')->start($buildState->getTempPrefix());

        /** @var Collection $json */
        $json = collect(json_decode(Storage::disk('temp')->get($path), true))->recursive();

        $json->get('packages', collect())
            ->map(function (Collection $versions, string $package) {
                $versions->map(function (Collection $version, string $version_name) {
                    if ($version->has('source')) {
                        $version->forget('source');
                    }
                });
            });

        Storage::disk('temp')->put($path, json_encode($json->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
