<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalog;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogSharedBlocks;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillCatalogToolComponents;
use DDD\Domain\AI\Entities\Skills\Catalog\AISkillToolDocumentation;
use DDD\Domain\AI\Services\Skills\AISkillCatalogDocService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Token-size CARTOGRAPHY of the agent's skill surface: the per-turn context cost the model ingests when a skill is
 * loaded, decomposed Skill → Package → Function (tool), plus the skill's instruction (`.md`) block. The
 * tool-definition tokens are measured from the EXACT wire schema the loop sends — `ToolDefinitions::forPackages()
 * ->toOpenAiToolSchema()` (the same call as {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService} buildToolSchemas)
 * — not the /documentation shape, so the numbers are the real per-turn cost.
 *
 * It aggregates every skill of the catalog ({@see AISkillCatalogDocService::buildSkillCatalog()}) and ranks the
 * heaviest tools and instruction blocks. Read-only (reflection and the skill `.md` files, no database) — no account
 * needed.
 *
 *   php bin/console app:ai-agent:skill-token-map                         # full tree, heaviest-first, + rankings
 *   php bin/console app:ai-agent:skill-token-map --json                  # machine-readable (feed the register / a diff)
 *   php bin/console app:ai-agent:skill-token-map --tool=getOrders        # one tool + component breakdown
 *   php bin/console app:ai-agent:skill-token-map --tool=getOrders --doc        # + the EXACT wire schema the model receives
 *   php bin/console app:ai-agent:skill-token-map --shared-blocks         # the two once-per-turn shared system blocks
 *   php bin/console app:ai-agent:skill-token-map --top=15                # how many entries in the ranking sections
 */
#[AsCommand(
    name: 'app:ai-agent:skill-token-map',
    description: 'Map the per-turn token size of every agent skill, decomposed by package then by function (tool).'
)]
class SkillTokenMapCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the map as JSON instead of the human tree')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many rows in the ranking sections (default 12)', '12')
            ->addOption('detail', null, InputOption::VALUE_NONE, 'Decompose EACH function into its token components: description, each input parameter, and each shared $def (where the nested-DTO bloat lives)')
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Only this skill (dotted name, e.g. Orders.Overview) — scopes the tree (rankings still global)')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Only this tool/function name — implies --detail, scopes the tree to it')
            ->addOption('doc', null, InputOption::VALUE_NONE, 'With --tool=<name>: dump that tool\'s FULL wire schema (the exact JSON-Schema the model receives) + its component breakdown — for inspecting where a tool\'s tokens actually sit')
            ->addOption('shared-blocks', null, InputOption::VALUE_NONE, 'Dump the two once-per-turn shared system blocks (QueryOptions syntax + shared request parameters) with their token cost + rendered markdown');
    }

    /**
     * Prints the token map, one tool's wire schema or the shared blocks.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $aISkillCatalogDocService = $this->getAISkillCatalogDocService();
        $top = max(1, (int)$input->getOption('top'));
        $skillFilter = $input->getOption('skill') !== null ? (string)$input->getOption('skill') : null;
        $toolFilter = $input->getOption('tool') !== null ? (string)$input->getOption('tool') : null;
        $detail = (bool)$input->getOption('detail') || $toolFilter !== null;
        $json = (bool)$input->getOption('json');

        // Headless dump of the two once-per-turn shared system blocks (QueryOptions syntax + shared request params).
        if ($input->getOption('shared-blocks')) {
            $sharedBlocks = $aISkillCatalogDocService->buildSharedBlocks();
            if ($json) {
                $output->writeln((string)json_encode($sharedBlocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }
            $this->renderSharedBlocks($sharedBlocks, $output);
            return Command::SUCCESS;
        }

        // Headless dump of ONE tool's full wire schema + component breakdown (the exact thing the model receives).
        if ($input->getOption('doc')) {
            if ($toolFilter === null) {
                $output->writeln('<error>--doc requires --tool=<toolName> (e.g. --tool=getOrders --doc)</error>');
                return Command::FAILURE;
            }
            $toolDocumentation = $aISkillCatalogDocService->buildToolDocumentation($toolFilter);
            if ($toolDocumentation === null) {
                $output->writeln(sprintf('<error>Tool not found in the catalog: %s</error>', $toolFilter));
                return Command::FAILURE;
            }
            if ($json) {
                $output->writeln((string)json_encode($toolDocumentation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }
            $this->renderToolDocumentation($toolDocumentation, $output);
            return Command::SUCCESS;
        }

        $catalog = $aISkillCatalogDocService->buildSkillCatalog();

        if ($json) {
            $output->writeln((string)json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $this->renderTree($catalog, $output, $detail, $skillFilter, $toolFilter);
        if ($skillFilter === null && $toolFilter === null) {
            $this->renderRankings($catalog, $top, $output);
        }
        return Command::SUCCESS;
    }

    protected function renderTree(AISkillCatalog $catalog, OutputInterface $output, bool $detail = false, ?string $skillFilter = null, ?string $toolFilter = null): void
    {
        $output->writeln('<info>Agent skill token map</info> — per-turn cost when a skill is loaded (wire schema, GPT-3 BPE tokenizer), heaviest first');
        $output->writeln('');
        foreach ($catalog->skills as $skill) {
            if ($skillFilter !== null && strcasecmp($skill->name, $skillFilter) !== 0) {
                continue;
            }
            $output->writeln(sprintf(
                '<info>%-34s</info> %6s tok   <fg=gray>(instruction %s + tools %s · %d tools)</>',
                $skill->name,
                number_format($skill->total),
                number_format($skill->instructionTokens),
                number_format($skill->toolDefTokens),
                $skill->toolCount,
            ));
            foreach ($skill->packages as $package) {
                $output->writeln(sprintf(
                    '  %-32s %6s tok  <fg=gray>(%d tools)</>',
                    $package->name,
                    number_format($package->total),
                    $package->toolCount,
                ));
                foreach ($package->tools as $tool) {
                    if ($toolFilter !== null && strcasecmp($tool->name, $toolFilter) !== 0) {
                        continue;
                    }
                    $output->writeln(sprintf('      %-30s %6s tok', $tool->name, number_format($tool->tokens)));
                    if ($detail && $tool->components !== null) {
                        $this->renderComponents($tool->components, $output);
                    }
                }
            }
            $output->writeln('');
        }
    }

    /**
     * The 4th level: where a single function's tokens live (description · each input parameter · each shared $def).
     */
    protected function renderComponents(AISkillCatalogToolComponents $components, OutputInterface $output): void
    {
        $output->writeln(sprintf('          <fg=gray>· description</>          %6s tok', number_format($components->description)));
        foreach ($components->parameters as $name => $tokens) {
            $output->writeln(sprintf('          <fg=gray>· param</>  %-18s %6s tok', $this->truncate((string)$name, 18), number_format((int)$tokens)));
        }
        foreach ($components->defs as $name => $tokens) {
            $output->writeln(sprintf('          <fg=gray>· $def</>   %-18s %6s tok', $this->truncate((string)$name, 18), number_format((int)$tokens)));
        }
        $output->writeln(sprintf('          <fg=gray>· envelope (structural)</>  %6s tok', number_format($components->envelope)));
    }

    protected function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** `--shared-blocks`: the two once-per-turn system blocks (factored OUT of the per-tool schemas) with cost + markdown. */
    protected function renderSharedBlocks(AISkillCatalogSharedBlocks $sharedBlocks, OutputInterface $output): void
    {
        $output->writeln('<info>Shared system blocks</info> — emitted ONCE in the agent system context (not per tool), GPT-3 BPE');
        $output->writeln('');
        foreach ($sharedBlocks->blocks as $block) {
            $output->writeln(sprintf('<info>%s</info>  %s tok   <fg=gray>(%s)</>', $block->title, number_format($block->tokens), $block->key));
            $output->writeln($block->markdown);
            $output->writeln('');
        }
    }

    /** `--tool=X --doc`: ONE tool's component breakdown + the exact wire JSON-Schema the model receives. */
    protected function renderToolDocumentation(AISkillToolDocumentation $toolDocumentation, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<info>%s</info>  %s tok/turn   <fg=gray>(skill %s · package %s)</>',
            $toolDocumentation->name,
            number_format($toolDocumentation->tokens),
            $toolDocumentation->skill,
            $toolDocumentation->package,
        ));
        $output->writeln('');
        $output->writeln('<info>── component breakdown ──</info>');
        $this->renderComponents($toolDocumentation->components, $output);
        $output->writeln('');
        $output->writeln('<info>── wire schema (the exact JSON-Schema the model receives) ──</info>');
        $output->writeln((string)json_encode($toolDocumentation->openAiToolSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return AISkillCatalogDocService */
    protected function getAISkillCatalogDocService(): AISkillCatalogDocService
    {
        /** @var AISkillCatalogDocService $aISkillCatalogDocService */
        $aISkillCatalogDocService = DDDService::instance()->getService(AISkillCatalogDocService::class);
        return $aISkillCatalogDocService;
    }

    protected function renderRankings(AISkillCatalog $catalog, int $top, OutputInterface $output): void
    {
        $totals = $catalog->totals;
        $output->writeln('<info>── totals ──</info>');
        $output->writeln(sprintf(
            '  %d skills · %d unique packages (%d skill-package links) · %d unique tools (%d skill-tool links)',
            $totals->skillCount,
            $totals->uniquePackageCount,
            $totals->packageCount,
            $totals->uniqueToolCount,
            $totals->toolCount,
        ));
        $output->writeln(sprintf(
            '  Σ if every skill loaded at once: %s tok   (skill instructions %s + unique tool-defs %s)',
            number_format($totals->sumOfSkillTotals),
            number_format($totals->instructionTokens),
            number_format($totals->uniqueToolDefTokens),
        ));

        // Flat heaviest tools (dedup by name — its wire schema is identical wherever it appears).
        $toolRows = [];
        foreach ($catalog->skills as $skill) {
            foreach ($skill->packages as $package) {
                foreach ($package->tools as $tool) {
                    $toolRows[$tool->name] = $tool->tokens;
                }
            }
        }
        arsort($toolRows);
        $output->writeln('');
        $output->writeln(sprintf('<info>── TOP %d heaviest tools (unique) ──</info>', $top));
        $shown = 0;
        foreach ($toolRows as $name => $tokens) {
            $output->writeln(sprintf('  %6s tok  %s', number_format($tokens), $name));
            if (++$shown >= $top) {
                break;
            }
        }

        $output->writeln('');
        $output->writeln('<info>── heaviest skill-instruction blocks ──</info>');
        $instrRows = [];
        foreach ($catalog->skills as $skill) {
            $instrRows[$skill->name] = $skill->instructionTokens;
        }
        arsort($instrRows);
        $shown = 0;
        foreach ($instrRows as $name => $tokens) {
            $output->writeln(sprintf('  %6s tok  %s', number_format($tokens), $name));
            if (++$shown >= $top) {
                break;
            }
        }
    }
}
