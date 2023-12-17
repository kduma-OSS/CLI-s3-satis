<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;

class JsonFileModifier
{
    public function __construct(protected BuildStateInterface $buildState)
    {
    }

    protected ?Collection $packages = null;

    public function modifyPackages(callable $callback): static
    {
        $packages = json_decode(Storage::disk('temp')->get($this->getRootPackagesPath()), true);
        $packages = collect($packages)->recursive();

        $packages->get('available-packages', collect())
            ->map(fn ($package) => [
                str($packages->get('metadata-url'))->replace('%package%', $package),
                str($packages->get('metadata-url'))->replace('%package%', $package.'~dev'),
            ])
            ->flatten()
            ->each(fn(Stringable $path) => $this->processPackagesFile($path, $callback));

        $packages['includes'] = $packages->get('includes', collect())
            ->each(fn(Collection $parameters, string $path) => $this->processPackagesFile(str($path), $callback))
            ->mapWithKeys(function (Collection $parameters, string $path)  {
                if (!$parameters->has('sha1')) {
                    return [$path => $parameters];
                }

                $old_sha1 = $parameters->get('sha1');

                $parameters['sha1'] = sha1_file(
                    config('filesystems.disks.temp.root')
                        ->append(
                            str($path)->start('/')->start($this->buildState->getTempPrefix())
                        )
                );

                $newPath = str($path)->replace($old_sha1, $parameters['sha1'])->toString();

                Storage::disk('temp')->move(
                    str($path)->start('/')->start($this->buildState->getTempPrefix()),
                    str($newPath)->start('/')->start($this->buildState->getTempPrefix())
                );

                return [$newPath => $parameters];
            });

        $json = $packages->toJson(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Storage::disk('temp')->put($this->getRootPackagesPath(), $json);

        return $this;
    }

    public function modifyVersions(callable $callback): static
    {
        return $this->modifyPackages(function (Collection $versions, string $package_name, Stringable $path) use ($callback) {
            $versions->each(fn(Collection $version) => $callback($version, $package_name, $path));
        });
    }

    protected function getRootPackagesPath(): string
    {
        return str('packages.json')->start('/')->start($this->buildState->getTempPrefix())->toString();
    }

    protected function processPackagesFile(Stringable $path, callable $callback): void
    {
        $fs_path = $path->start('/')->start($this->buildState->getTempPrefix());
        /** @var Collection $packages */
        $packages = collect(json_decode(Storage::disk('temp')->get($fs_path), true))->recursive();

        $packages
            ->get('packages', collect())
            ->each(fn(Collection $versions, string $package_name) => $callback($versions, $package_name, $path));

        $json = $packages->toJson(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Storage::disk('temp')->put($fs_path, $json);
    }
}
