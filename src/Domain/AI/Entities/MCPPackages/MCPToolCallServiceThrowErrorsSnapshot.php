<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Infrastructure\Services\Service;

/**
 * The `throwErrors` flag of every service the executor injects into ONE tool call, taken before the call and put back
 * after it.
 *
 * The services come from the DI container, so the instance a tool receives is the one every later caller of the same
 * process receives too. Tools set `$shopsService->throwErrors = true` (in most MCP packages) and never reset it, so a later `find()` that expects null on a miss — the executor's own entitlement gate, a tool that
 * relies on the default, a service in the next turn of the same worker — throws a `NotFoundException` instead. Inside
 * the tool call the flag keeps working exactly as the tool set it; only its survival past the call ends here.
 *
 * Services a tool resolves itself (`AppService::instance()->getService(...)`) are not injected and not covered.
 *
 * @phpstan-consistent-constructor
 */
class MCPToolCallServiceThrowErrorsSnapshot
{
    /** @var Service[] the services injected into the tool call */
    protected array $injectedServices = [];

    /** @var bool[] the flag of `$injectedServices[$index]` when the snapshot was taken */
    protected array $throwErrorsByServiceIndex = [];

    /**
     * The flags of the services among a tool call's resolved method arguments.
     *
     * @param array<int, mixed> $toolMethodArguments the arguments the executor passes to the tool method
     */
    public static function takeFromToolMethodArguments(array $toolMethodArguments): static
    {
        $snapshot = new static();
        foreach ($toolMethodArguments as $toolMethodArgument) {
            if ($toolMethodArgument instanceof Service) {
                $snapshot->injectedServices[] = $toolMethodArgument;
                $snapshot->throwErrorsByServiceIndex[] = $toolMethodArgument->throwErrors;
            }
        }

        return $snapshot;
    }

    /** Put every injected service's flag back to the value it had before the tool call. */
    public function restore(): void
    {
        foreach ($this->injectedServices as $serviceIndex => $injectedService) {
            $injectedService->throwErrors = $this->throwErrorsByServiceIndex[$serviceIndex];
        }
    }
}
