<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;
use olvlvl\ComposerAttributeCollector\Attributes;
use olvlvl\ComposerAttributeCollector\TargetClass;
use Symfony\Component\Console\Output\OutputInterface;

class ExtensionRunner
{
    private ?Collection $extensions = null;
    private array $enabled_extensions = [];
    private array $initialized_extensions = [];

    public function enableExtension(string $extension): void
    {
        if($this->getExtensions()->has($extension) === false) {
            throw new \InvalidArgumentException("Extension $extension does not exist.");
        }

        $this->enabled_extensions[] = $extension;
    }

    public function disableExtension(string $extension): void
    {
        $this->enabled_extensions = array_filter($this->enabled_extensions, fn(string $enabled): bool => $enabled !== $extension);
    }

    public function execute(Command $command, BuildHooks $hook, BuildStateInterface $buildState): bool
    {
        $skip_flag = false;

        $command->line("Running {$hook->name} plugin hook...", verbosity: OutputInterface::VERBOSITY_DEBUG);

        $extensions = $this->getExtensions();
        $extensions = collect($this->enabled_extensions)
            ->map(fn(string $key): ExtensionDescriptor => $extensions->get($key))
            ->filter(fn(ExtensionDescriptor $descriptor): bool => $descriptor->hooks->has($hook->name))
            ->each(function (ExtensionDescriptor $descriptor) {
                if(in_array($descriptor->class_name, $this->initialized_extensions)) {
                    return;
                }

                $this->initialized_extensions[$descriptor->class_name] = app($descriptor->class_name);
            })
            ->map(function (ExtensionDescriptor $descriptor) use (&$skip_flag, $command, $hook, $buildState) {
                $descriptor->hooks->get($hook->name)->each(function (HookDescriptor $hook) use ($command, $descriptor, $buildState, &$skip_flag) {
                    $command->line("Running {$descriptor->key}::{$hook->method_name}() plugin hook...", verbosity: OutputInterface::VERBOSITY_DEBUG);
                    if(false === $this->initialized_extensions[$descriptor->class_name]->{$hook->method_name}($buildState)) {
                        $command->line("{$descriptor->key}::{$hook->method_name}() plugin hook set a skip flag.", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                        $skip_flag = true;
                    }
                    $command->line("Finished running {$descriptor->key}::{$hook->method_name}() plugin hook.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                });
            });

        $command->line("Finished running {$hook->name} plugin hook.", verbosity: OutputInterface::VERBOSITY_DEBUG);

        return !$skip_flag;
    }

    private function getExtensions(): Collection
    {
        if($this->extensions !== null) {
            return $this->extensions;
        }

        return $this->extensions = collect(Attributes::findTargetClasses(BuildExtension::class))
            ->map(fn(TargetClass $target): ExtensionDescriptor => new ExtensionDescriptor(
                name: $target->attribute->name,
                key: $target->attribute->key ?? $target->name,
                class_name: $target->name,
                hooks: $this->getHooks($target),
            ))
            ->keyBy(fn(ExtensionDescriptor $descriptor): string => $descriptor->key);
    }

    private function getHooks(TargetClass $target): Collection
    {
        return collect(Attributes::forClass($target->name)->methodsAttributes)
            ->map(function (array $attributes, string $method) {
                return collect($attributes)
                    ->filter(fn(object $attribute): bool => $attribute instanceof BuildHook)
                    ->map(fn(BuildHook $attribute): HookDescriptor => new HookDescriptor(
                        hook: $attribute->hook,
                        method_name: $method,
                    ));
            })
            ->flatten(1)
            ->groupBy(fn(HookDescriptor $hook): string => $hook->hook->name);
    }
}
