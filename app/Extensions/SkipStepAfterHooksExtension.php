<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\PluginConfig;

#[BuildExtension(name: 'Skip Step After Hook', key: 'skip-step-after-hook')]
class SkipStepAfterHooksExtension
{
    #[BuildHook(BuildHooks::BEFORE_CREATE_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::BEFORE_DOWNLOAD_FROM_S3)]
    #[BuildHook(BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY)]
    #[BuildHook(BuildHooks::BEFORE_UPLOAD_TO_S3)]
    #[BuildHook(BuildHooks::BEFORE_REMOVE_MISSING_FILES_FROM_S3)]
    #[BuildHook(BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY)]
    #[BuildHook(BuildHooks::BEFORE_INITIAL_CLEAR_TEMP_DIRECTORY)]
    public function hook(BuildHooks $hook, PluginConfig $config): bool
    {
        return ! $config->get('skip', collect())->contains($hook->name);
    }
}
