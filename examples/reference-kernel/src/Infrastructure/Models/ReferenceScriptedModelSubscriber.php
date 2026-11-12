<?php

declare(strict_types=1);

namespace AgentsReference\Infrastructure\Models;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * DEMO ONLY — installs the reference application's hand-written model ({@see ReferenceScriptedModelTransport}) as the
 * Argus transport at the start of every web request and every console command, before any code can make a provider
 * call. Answers: `recordings/reference-conversation.json`; request log: `var/model-requests/`.
 */
class ReferenceScriptedModelSubscriber implements EventSubscriberInterface
{
    public const string ANSWERS_FILE = '/recordings/reference-conversation.json';
    public const string REQUEST_LOG_DIRECTORY = '/var/model-requests';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['installScriptedModel', 4096],
            ConsoleEvents::COMMAND => ['installScriptedModel', 4096],
        ];
    }

    public function installScriptedModel(): void
    {
        $referenceDirectory = DDDService::instance()->getRootDir();
        ArgusApiOperations::$transportRecording = ReferenceScriptedModelTransport::fromAnswersFile(
            $referenceDirectory . self::ANSWERS_FILE,
            $referenceDirectory . self::REQUEST_LOG_DIRECTORY
        );
    }
}
