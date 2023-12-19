<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use App\Extensions\Internals\JsonFileModifier;
use App\Extensions\Internals\PluginConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[BuildExtension(name: 'File Restrictions Map File Generator', key: 'file-restrictions-map-generator')]
class FileRestrictionsMapFileGeneratorExtension
{
    /**
     * Prepare file restrictions map file
     */
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(PluginConfig $pluginConfig, Command $command, BuildStateInterface $buildState, JsonFileModifier $jsonFileModifier): void
    {
        $url_host = $buildState->getConfig()
            ->get('archive', collect())
            ->get('prefix-url', $buildState->getConfig()->get('homepage'));

        $tagged_versions = collect();

        $jsonFileModifier->modifyVersions(function (Collection $version, string $package_name, Stringable $json_path) use ($pluginConfig, $tagged_versions, $url_host, $command) {
            if ($version->has('dist') === false) {
                $command->line("Version {$package_name}:{$version['version']} does not have a dist - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return;
            }

            $dist = $version->get('dist', collect());

            if ($dist->has('url') === false) {
                $command->line("Version {$package_name}:{$version['version']} does not have a url - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return;
            }

            $url = $dist->get('url');
            $path = str($url)->replace($url_host, '')->ltrim('/')->toString();
            $tags = $this->getTagsForVersion($package_name, $version);

            if (!str($path)->startsWith(['http://', 'https://'])) {
                $tagged_versions->push([
                    'package' => $package_name,
                    'url' => $path,
                    'version' => $version['version_normalized'],
                    'tags' => $tags,
                ]);
            } else {
                $command->line("Version {$package_name}:{$version['version']} has a remote url - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);
            }

            $command->line("Version {$package_name}:{$version['version']} has been tagged with the following tags: ".implode(', ', $tags->toArray()), verbosity: OutputInterface::VERBOSITY_DEBUG);

            if(!$pluginConfig->get('extra-json', false)){
                return;
            }

            if(!$json_path->start('/')->startsWith('/p2/')) {
                $command->line("Version {$package_name}:{$version['version']} is not in a Composer 2 package file - skipping modification of json file.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return;
            }

            if($version->has('extra')) {
                $extra = $version->get('extra');
            } else {
                $extra = collect();
                $version->put('extra', $extra);
            }

            $extra->put('s3-satis-file-restrictions', $tags);
        });

        Storage::disk('temp')->deleteDirectory('.tags');
        $tagged_versions
            ->groupBy('url')
            ->map(function (Collection $packages) {
                return $packages->pluck('tags')->unique()->flatten();
            })
            ->each(function (Collection $tags, string $url) use ($buildState) {
                $path = str('.tags')->append('/')->append($url)->append('.json')->start('/')->start($buildState->getTempPrefix());
                Storage::disk('temp')->put($path, $tags->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            });
    }

    protected function getTagsForVersion(string $package_name, Collection $version): Collection
    {
        $version_normalized = $version->get('version_normalized');

        $tags = collect(["{$package_name}:{$version_normalized}"]);
        if (preg_match('/^(\\d)\\.(\\d)\\.(\\d)\\.(\\d)$/u', $version_normalized, $matches)) {
            $tags->push("{$package_name}:{$matches[1]}.{$matches[2]}.{$matches[3]}.x");
            $tags->push("{$package_name}:{$matches[1]}.{$matches[2]}.x");
            $tags->push("{$package_name}:{$matches[1]}.x");
        }

        return $tags;
    }
}
