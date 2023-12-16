<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;

class BuildState implements BuildStateInterface
{
    protected Collection $placeholders;
    public bool $last_step_executed = false;
    protected Collection $config;

    public function __construct(
        protected string $temp_prefix,
        protected string $config_file_path,
        protected array $repository_urls = [],
        protected bool $force_fresh_downloads = false,
    ) {
        $this->placeholders = collect();

        $this->config = collect(json_decode(file_get_contents($config_file_path), true))->recursive();
    }

    public function getTempPrefix(): string
    {
        return $this->temp_prefix;
    }

    public function setTempPrefix(string $prefix): self
    {
        $this->temp_prefix = $prefix;

        return $this;
    }

    public function getConfigFilePath(): string
    {
        return $this->config_file_path;
    }

    public function setConfigFilePath(string $path): self
    {
        $this->config_file_path = $path;

        return $this;
    }

    public function getRepositoryUrls(): array
    {
        return $this->repository_urls;
    }

    public function setRepositoryUrls(array $repository_urls): BuildState
    {
        $this->repository_urls = $repository_urls;
        return $this;
    }

    public function addRepositoryUrl(string $repository_url): BuildState
    {
        $this->repository_urls[] = $repository_url;
        return $this;
    }

    public function getPlaceholders(): Collection
    {
        return $this->placeholders;
    }

    public function setPlaceholders(Collection $placeholders): BuildState
    {
        $this->placeholders = $placeholders;
        return $this;
    }

    public function isForceFreshDownloads(): bool
    {
        return $this->force_fresh_downloads;
    }

    public function setForceFreshDownloads(bool $force_fresh_downloads): BuildState
    {
        $this->force_fresh_downloads = $force_fresh_downloads;
        return $this;
    }

    public function isLastStepSkipped(): bool
    {
        return !$this->last_step_executed;
    }

    public function getConfig(): Collection
    {
        return $this->config;
    }
}
