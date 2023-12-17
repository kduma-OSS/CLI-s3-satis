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

#[BuildExtension(name: 'Remove Fields From JSON', key: 'remove-fields-from-json')]
class RemoveFieldsFromJsonExtension
{
    #[BuildHook(BuildHooks::AFTER_BUILD_SATIS_REPOSITORY)]
    public function hook(PluginConfig $pluginConfig, JsonFileModifier $modifier): void
    {
        $to_remove = collect([
            'source' => $pluginConfig->get('remove', collect())->contains('source'),
            'authors' => $pluginConfig->get('remove', collect())->contains('authors'),
            'homepage' => $pluginConfig->get('remove', collect())->contains('homepage'),
            'support' => $pluginConfig->get('remove', collect())->contains('support'),
        ]);

        $modifier->modifyVersions(function (Collection $version, string $package_name, Stringable $path) use ($to_remove) {
            if ($to_remove->get('source') && $version->has('source')) {
                $version->forget('source');
            }

            if ($to_remove->get('authors') && $version->has('authors')) {
                $version->forget('authors');
            }

            if ($to_remove->get('homepage') && $version->has('homepage')) {
                $version->forget('homepage');
            }

            if ($to_remove->get('support') && $version->has('support')) {
                $version->forget('support');
            }
        });
    }
}
