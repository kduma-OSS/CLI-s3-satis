<?php

namespace App\Extensions\Internals;

use Composer\Satis\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

#[BuildExtension(name: 'Satis Purge after Build', key: 'satis-purge')]
class SatisPurgeExtension
{
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(BuildStateInterface $buildState): void
    {
        if(!$buildState->getConfig()->has('archive')) {
            return;
        }

        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(new ArrayInput([
            'command' => 'purge',
            'file' => $buildState->getConfigFilePath(),
            'output-dir' => (string) config('filesystems.disks.temp.root')->append($buildState->getTempPrefix()),
        ]));
    }
}
