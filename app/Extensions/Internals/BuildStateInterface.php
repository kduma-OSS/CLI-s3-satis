<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;

interface BuildStateInterface
{
    public function getTempPrefix(): string;

    public function setTempPrefix(string $prefix): self;



    public function getConfigFilePath(): string;

    public function setConfigFilePath(string $path): self;



    public function setRepositoryUrls(array $repository_urls): BuildState;

    public function getRepositoryUrls(): array;

    public function addRepositoryUrl(string $repository_url): BuildState;



    public function setPlaceholders(Collection $placeholders): BuildState;

    public function getPlaceholders(): Collection;

    public function isForceFreshDownloads(): bool;

    public function setForceFreshDownloads(bool $force_fresh_downloads): BuildState;




    public function isLastStepSkipped(): bool;

    public function getConfig(): Collection;
}
