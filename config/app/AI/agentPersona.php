<?php

declare(strict_types=1);

/**
 * The agent's persona names — the package default, read as `Config::get('AI.agentPersona')`. An application replaces
 * this file whole with its own `config/app/AI/agentPersona.php` (keys are never merged).
 *
 * `agentName`: the agent's own name, `{%AGENT_NAME%}` in the base prompt and the character prompt.
 * `productName`: the product name used when the scope service names none for a conversation (`{%PRODUCT_NAME%}`).
 */
return [
    'agentName' => 'Assistant',
    'productName' => 'this product',
];
