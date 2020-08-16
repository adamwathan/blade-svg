<?php

declare(strict_types=1);

namespace BladeUI\Icons;

use BladeUI\Icons\Components\Svg as SvgComponent;
use BladeUI\Icons\Exceptions\CannotRegisterIconSet;
use BladeUI\Icons\Exceptions\SvgNotFound;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Factory
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $defaultClass;

    /** @var array */
    private $sets = [];

    /** @var array */
    private $cache = [];

    public function __construct(Filesystem $filesystem, string $defaultClass = '')
    {
        $this->filesystem = $filesystem;
        $this->defaultClass = $defaultClass;
    }

    public function all(): array
    {
        return $this->sets;
    }

    /**
     * @throws CannotRegisterIconSet
     */
    public function add(string $set, array $options): self
    {
        if (! isset($options['path']) && !isset($options['disk'])) {
            throw CannotRegisterIconSet::pathOrDiskNotDefined($set);
        }

        if (! isset($options['prefix'])) {
            throw CannotRegisterIconSet::prefixNotDefined($set);
        }

        if ($collidingSet = $this->getSetByPrefix($options['prefix'])) {
            throw CannotRegisterIconSet::prefixNotUnique($set, $collidingSet);
        }

        if (isset($options['path']) && $this->filesystem->missing($options['path'])) {
            throw CannotRegisterIconSet::nonExistingPath($set, $options['path']);
        }

        $this->sets[$set] = $options;

        $this->cache = [];

        return $this;
    }

    public function registerComponents(): void
    {
        foreach ($this->sets as $set) {
            if (isset($set['path'])) {
                $this->registerComponentsByPath($set);
            } else {
                $this->registerComponentsByDisk($set);
            }
        }
    }

    protected function registerComponentsByPath($set) : void
    {
        foreach ($this->filesystem->allFiles($set['path']) as $file) {
            $relativePath = Str::after($file->getPath(), $set['path']);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $path = array_filter(explode('/', $relativePath));
            Blade::component(
                SvgComponent::class,
                implode('.', array_filter($path + [$file->getFilenameWithoutExtension()])),
                $set['prefix']
            );
        }
    }

    protected function registerComponentsByDisk($set) : void
    {
        $allFiles = Storage::disk($set['disk'])->allFiles();
        foreach ($allFiles as $file) {
            $file = str_replace('/', '.', $file);
            $path = pathinfo($file, PATHINFO_FILENAME);

            Blade::component(
                SvgComponent::class,
                $path,
                $set['prefix']
            );
        }
    }

    /**
     * @throws SvgNotFound
     */
    public function svg(string $name, $class = '', array $attributes = []): Svg
    {
        [$set, $name] = $this->splitSetAndName($name);

        return new Svg($name, $this->contents($set, $name), $this->formatAttributes($set, $class, $attributes));
    }

    /**
     * @throws SvgNotFound
     */
    private function contents(string $set, string $name): string
    {
        if (isset($this->cache[$set][$name])) {
            return $this->cache[$set][$name];
        }

        if (isset($this->sets[$set])) {
            try {
                return $this->cache[$set][$name] = isset($this->sets[$set]['path'])
                    ? $this->getSvgFromPath($name, $this->sets[$set]['path'])
                    : $this->getSvgFromDisk($name, $this->sets[$set]['disk']);
            } catch (FileNotFoundException $exception) {
                //
            }
        }

        throw SvgNotFound::missing($set, $name);
    }

    private function getSvgFromPath(string $name, string $path): string
    {
        return trim($this->filesystem->get(sprintf(
            '%s/%s.svg',
            rtrim($path),
            str_replace('.', '/', $name)
        )));
    }

    private function getSvgFromDisk(string $name, string $disk): string
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.svg';
        return Storage::disk($disk)->get($path);
    }

    private function splitSetAndName(string $name): array
    {
        $prefix = Str::before($name, '-');

        $set = $this->getSetByPrefix($prefix);

        $name = $set ? Str::after($name, '-') : $name;

        return [$set ?? 'default', $name];
    }

    private function getSetByPrefix(string $prefix): ?string
    {
        return collect($this->sets)->where('prefix', $prefix)->keys()->first();
    }

    private function formatAttributes(string $set, $class = '', array $attributes = []): array
    {
        if (is_string($class)) {
            if ($class = $this->buildClass($set, $class)) {
                $attributes['class'] = $attributes['class'] ?? $class;
            }
        } elseif (is_array($class)) {
            $attributes = $class;

            if (! isset($attributes['class']) && $class = $this->buildClass($set, '')) {
                $attributes['class'] = $class;
            }
        }

        return $attributes;
    }

    private function buildClass(string $set, string $class): string
    {
        return trim(sprintf(
            '%s %s',
            trim(sprintf('%s %s', $this->defaultClass, $this->sets[$set]['class'] ?? '')),
            $class
        ));
    }
}
