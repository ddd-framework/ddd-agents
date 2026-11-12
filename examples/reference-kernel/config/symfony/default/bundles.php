<?php

declare(strict_types=1);

// The reference application: the framework, the DDD bundle and the logger — nothing else.
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    DDD\DDDBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
];
