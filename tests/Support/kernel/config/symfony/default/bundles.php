<?php

declare(strict_types=1);

// The package test kernel: the framework, the DDD bundle and the logger — no application bundle.
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    DDD\DDDBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
];
