<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;

final class ComposerJsonEditor
{
    /**
     * @param list<Upgrade> $upgrades
     */
    public static function apply(string $contents, array $upgrades, bool $sortPackages): string
    {
        $manipulator = new JsonManipulator($contents);
        foreach ($upgrades as $upgrade) {
            if (!$manipulator->addLink(self::section($upgrade), $upgrade->name, $upgrade->target()->constraint, $sortPackages)) {
                return self::reencode($contents, $upgrades);
            }
        }

        return $manipulator->getContents();
    }

    /**
     * Loses the file's formatting, so it's only for files JsonManipulator can't edit in place.
     *
     * @param list<Upgrade> $upgrades
     */
    private static function reencode(string $contents, array $upgrades): string
    {
        $data = JsonFile::parseJson($contents);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('composer.json does not contain an object.');
        }
        foreach ($upgrades as $upgrade) {
            $section = $data[self::section($upgrade)] ?? [];
            if (!\is_array($section)) {
                throw new \UnexpectedValueException(\sprintf('"%s" in composer.json is not an object.', self::section($upgrade)));
            }
            $section[$upgrade->name] = $upgrade->target()->constraint;
            $data[self::section($upgrade)] = $section;
        }

        return JsonFile::encode($data)."\n";
    }

    private static function section(Upgrade $upgrade): string
    {
        return $upgrade->dev ? 'require-dev' : 'require';
    }
}
