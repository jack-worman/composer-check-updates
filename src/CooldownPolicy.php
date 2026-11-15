<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Config;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Util\Platform;

/**
 * Resolves the cooldown "composer update" would enforce, the way "composer outdated" does.
 */
final class CooldownPolicy
{
    /**
     * @param string|null $period overrides the configured period, "0" turns the cooldown off
     */
    public static function fromConfig(Config $config, ?string $period = null): ?CooldownPolicyConfig
    {
        $policy = PolicyConfig::fromConfig($config);
        if (null !== $period) {
            try {
                $seconds = CooldownPolicyConfig::parseDuration($period);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException(\sprintf('Invalid cooldown period "%s", use a number of seconds or a duration like "7 days".', $period), 0, $e);
            }
            $cooldown = new CooldownPolicyConfig(true, $policy->cooldown->audit, $policy->cooldown->ignore, $seconds);

            return $cooldown->hasCooldown() ? $cooldown : null;
        }

        if (Platform::getBoolEnv('COMPOSER_NO_BLOCKING', false) || Platform::getBoolEnv('COMPOSER_NO_SECURITY_BLOCKING', false)) {
            return null;
        }

        return $policy->enabled && $policy->cooldown->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE) ? $policy->cooldown : null;
    }
}
