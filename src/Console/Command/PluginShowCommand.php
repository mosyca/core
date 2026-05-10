<?php

declare(strict_types=1);

namespace Mosyca\Core\Console\Command;

use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\TemplateAwarePluginInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mosyca:plugin:show', description: 'Show full details of a registered Mosyca plugin')]
final class PluginShowCommand extends Command
{
    public function __construct(private readonly PluginRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Plugin name (e.g. core:system:ping)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginName = (string) $input->getArgument('name');

        if (!$this->registry->has($pluginName)) {
            $io->error("Plugin '{$pluginName}' not found. Run <info>mosyca:plugin:list</info> to see all registered plugins.");

            return Command::FAILURE;
        }

        $plugin = $this->registry->get($pluginName);

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

        if ($plugin instanceof TemplateAwarePluginInterface) {
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
