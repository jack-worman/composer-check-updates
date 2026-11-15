<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

enum Target: string
{
    case Latest = 'latest';
    case Minor = 'minor';
    case Patch = 'patch';
    case Semver = 'semver';
}
