<?php

declare(strict_types=1);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
        'phpdoc_to_comment' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__.'/src', __DIR__.'/tests'])
            ->append([__DIR__.'/bin/ccu', __FILE__]),
    );
