<?php

declare(strict_types=1);

namespace Mosyca\Core\Console\Command;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\TemplateAwareActionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mosyca:action:show', description: 'Show full details of a registered Mosyca action')]
final class ActionShowCommand extends Command
{
    public function __construct(private readonly ActionRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Action name (e.g. core:system:ping)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actionName = (string) $input->getArgument('name');

        if (!$this->registry->has($actionName)) {
            $io->error("Action '{$actionName}' not found. Run <info>mosyca:action:list</info> to see all registered actions.");

            return Command::FAILURE;
        }

        $plugin = $this->registry->get($actionName);

        $io->title($plugin->getName());
        $io->text($plugin->getDescription());
        $io->newLine();

        $io->section('Details');
        $io->definitionList(
            ['Mode' => $plugin->isMutating() ? '⚠️  write (mutating)' : '✅ read-only'],
            ['Format' => $plugin->getDefaultFormat()],
            ['Default template' => $plugin->getDefaultTemplate() ?? '(generic default)'],
            ['Tags' => implode(', ', $plugin->getTags()) ?: '(none)'],
            ['Scopes' => implode(', ', $plugin->getRequiredScopes()) ?: '(none)'],
        );

        if ($plugin instanceof TemplateAwareActionInterface) {
            $templates = $plugin->getTemplates();
            if (!empty($templates)) {
                $io->section('Named Templates');
                $rows = [];
                foreach ($templates as $label => $path) {
                    $rows[] = ["--template={$label}", $path];
                }
                $io->table(['Option', 'Template path'], $rows);
            }
        }

        $params = $plugin->getParameters();
        if (!empty($params)) {
            $io->section('Parameters');
            $rows = [];
            foreach ($params as $paramName => $spec) {
                $rows[] = [
                    "--{$paramName}",
                    $spec['type'] ?? 'string',
                    ($spec['required'] ?? false) ? 'yes' : 'no',
                    $spec['description'] ?? '',
                    isset($spec['default']) ? (string) json_encode($spec['default'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) : '',
                ];
            }
            $io->table(['Option', 'Type', 'Required', 'Description', 'Default'], $rows);
        }

        $io->section('Usage');
        $io->text($plugin->getUsage());

        return Command::SUCCESS;
    }
}
