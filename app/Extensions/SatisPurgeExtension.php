<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildState;
use App\Extensions\Internals\BuildStateInterface;
use Composer\Satis\Console\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;

#[BuildExtension(name: 'Satis Purge after Build', key: 'satis-purge')]
class SatisPurgeExtension
{
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(BuildState $buildState): void
    {
        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(new ArrayInput([
            'command' => 'purge',
            'file' => $buildState->getConfigFilePath(),
            'output-dir' => (string) config('filesystems.disks.temp.root')->append($buildState->getTempPrefix())
        ]));
    }
}
