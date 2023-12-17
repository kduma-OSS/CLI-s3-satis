<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use App\Extensions\Internals\JsonFileModifier;
use App\Extensions\Internals\PluginConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[BuildExtension(name: 'Skip Remote Versions', key: 'skip-remote-versions')]
class SkipRemoteVersionsExtension
{
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(Command $command, BuildStateInterface $buildState, JsonFileModifier $modifier): void
    {
        $url_host = $buildState->getConfig()
            ->get('archive', collect())
            ->get('prefix-url', $buildState->getConfig()->get('homepage'));

        $modifier->modifyVersions(function (Collection $version, string $package_name) use ($url_host, $command) {
            if ($version->has('dist') === false) {
                $command->error("Version {$package_name}:{$version['version']} does not have a dist - removing.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return false;
            }

            $dist = $version->get('dist', collect());

            if ($dist->has('url') === false) {
                $command->error("Version {$package_name}:{$version['version']} does not have a url - removing.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return false;
            }

            $url = $dist->get('url');
            $path = str($url)->replace($url_host, '')->ltrim('/')->toString();

            if (str($path)->startsWith(['http://', 'https://'])) {
                $command->error("Version {$package_name}:{$version['version']} has a remote url - removing.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                return false;
            }

            return true;
        });
    }
}
