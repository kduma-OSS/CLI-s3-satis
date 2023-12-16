<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\PluginConfig;
use LaravelZero\Framework\Commands\Command;

#[BuildExtension(name: 'Pause At Hook', key: 'pause-at-hook')]
class PauseAtHooksExtension
{
    #[BuildHook(BuildHooks::BEFORE_INITIAL_CLEAR_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::AFTER_INITIAL_CLEAR_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::BEFORE_CREATE_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::AFTER_CREATE_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::BEFORE_DOWNLOAD_FROM_S3)]
    #[BuildHook(BuildHooks::AFTER_DOWNLOAD_FROM_S3)]
    #[BuildHook(BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY)]
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    #[BuildHook(BuildHooks::BEFORE_UPLOAD_TO_S3)]
    #[BuildHook(BuildHooks::AFTER_UPLOAD_TO_S3)]
    #[BuildHook(BuildHooks::BEFORE_REMOVE_MISSING_FILES_FROM_S3)]
    #[BuildHook(BuildHooks::AFTER_REMOVE_MISSING_FILES_FROM_S3)]
    #[BuildHook(BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::AFTER_FINAL_CLEAR_TEMP_DIRECTORY)]
    public function hook(BuildHooks $hook, PluginConfig $config, Command $command): void
    {
        if ($config->get('pause', collect())->contains($hook->name)) {
            $command->warn("Paused at hook {$hook->name}.");
            $command->ask('Press enter to continue...');
        }
    }
}
