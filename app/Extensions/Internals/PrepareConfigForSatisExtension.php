<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Facades\Storage;

#[BuildExtension(name: 'Prepare Config For Satis Extension', key: 'prepare-config-for-satis')]
class PrepareConfigForSatisExtension
{
    private ?string $original_path = null;

    #[BuildHook(BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY)]
    public function prepare(BuildStateInterface $buildState): void
    {
        $config = $buildState->getConfig()->except('s3-satis');

        $json = json_encode($config->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $path = str($buildState->getTempPrefix())->append('/')->append('satis.json');

        Storage::disk('temp')->put($path, $json);

        $config_file_path = config('filesystems.disks.temp.root')->append($path);

        $this->original_path = $buildState->getConfigFilePath();
        $buildState->setConfigFilePath($config_file_path);
    }

    #[BuildHook(BuildHooks::BEFORE_UPLOAD_TO_S3)]
    public function cleanup(BuildState $buildState): void
    {
        $path = str('satis.json')->start('/')->start($buildState->getTempPrefix());

        Storage::disk('temp')->delete($path);

        $buildState->setConfigFilePath($this->original_path);
    }
}
