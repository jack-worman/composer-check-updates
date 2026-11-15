<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests;

use Composer\Util\Platform;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A throwaway project whose packages come from an inline repository, so the tests never reach Packagist.
 */
final class Project
{
    public readonly string $dir;

    /** @var list<array<string, mixed>> */
    private array $packages = [];

    /** @var array<string, string|false> */
    private array $environment = [];

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/ccu-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/home', 0o777, true);
        foreach (['COMPOSER_HOME' => $this->dir.'/home', 'COMPOSER_CACHE_DIR' => $this->dir.'/cache', 'COMPOSER' => null, 'COMPOSER_NO_BLOCKING' => null, 'COMPOSER_BINARY' => null] as $name => $value) {
            $this->environment[$name] = Platform::getEnv($name);
            null === $value ? Platform::clearEnv($name) : Platform::putEnv($name, $value);
        }
    }

    public function remove(): void
    {
        foreach ($this->environment as $name => $value) {
            false === $value ? Platform::clearEnv($name) : Platform::putEnv($name, $value);
        }
        new Filesystem()->remove($this->dir);
    }

    /**
     * @param array<string, string> $require
     */
    public function release(string $name, string $version, string $age = '30 days', array $require = []): self
    {
        $this->packages[] = [
            'name' => $name,
            'version' => $version,
            'time' => new \DateTimeImmutable('-'.$age)->format(\DATE_ATOM),
            'dist' => ['type' => 'zip', 'url' => 'https://example.invalid/'.$name.'/'.$version.'.zip'],
            'require' => (object) $require,
        ];

        return $this;
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     * @param array<string, mixed>  $config
     * @param array<string, string> $locked     the installed version of each package
     */
    public function write(array $require, array $requireDev = [], array $config = [], array $locked = []): self
    {
        $json = [
            'name' => 'acme/project',
            'repositories' => [['type' => 'package', 'package' => $this->packages], ['packagist.org' => false]],
            'require' => (object) $require,
            'require-dev' => (object) $requireDev,
            'config' => (object) $config,
        ];
        file_put_contents($this->dir.'/composer.json', json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");

        if ([] !== $locked) {
            $lock = ['content-hash' => '', 'packages' => [], 'packages-dev' => []];
            foreach ($locked as $name => $version) {
                $lock[\array_key_exists($name, $requireDev) ? 'packages-dev' : 'packages'][] = ['name' => $name, 'version' => $version, 'type' => 'library'];
            }
            file_put_contents($this->dir.'/composer.lock', json_encode($lock, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        }

        return $this;
    }

    public function composerJson(): string
    {
        return (string) file_get_contents($this->dir.'/composer.json');
    }

    /**
     * @return array<string, string>
     */
    public function requirements(string $section = 'require'): array
    {
        $json = json_decode($this->composerJson(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($json) && \is_array($json[$section]));

        /** @var array<string, string> */
        return $json[$section];
    }
}
