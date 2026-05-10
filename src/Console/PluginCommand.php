<?php

declare(strict_types=1);

namespace Mosyca\Core\Console;

use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wraps a PluginInterface as a Symfony Console Command.
 *
 * Generated dynamically by ConsoleAdapter — never instantiated directly.
 */
final class PluginCommand extends Command
{
    public function __construct(
        private readonly PluginInterface $plugin,
        private readonly OutputRendererInterface $renderer,
    ) {
        parent::__construct($plugin->getName());
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->plugin->getDescription())
            ->setHelp($this->buildHelp());

        foreach ($this->plugin->getParameters() as $name => $spec) {
            $mode = ($spec['required'] ?? false)
                ? InputOption::VALUE_REQUIRED
                : InputOption::VALUE_OPTIONAL;

            $this->addOption(
                $name,
                null,
                $mode,
                $spec['description'] ?? '',
                $spec['default'] ?? null,
            );
        }

        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json|yaml|raw|table|text|mcp', $this->plugin->getDefaultFormat())
            ->addOption('template', null, InputOption::VALUE_OPTIONAL, 'Named Twig template (use mosyca:plugin:show to list available names)')
            ->addOption('template-inline', null, InputOption::VALUE_OPTIONAL, 'Inline Twig template string (only used with --format=text)')
            ->addOption('no-confirm', null, InputOption::VALUE_NONE, 'Skip confirmation prompt for mutating plugins');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $skipConfirm = true === $input->getOption('no-confirm');
        if ($this->plugin->isMutating() && $input->isInteractive() && !$skipConfirm) {
            if (!$io->confirm(\sprintf('⚠️  <comment>%s</comment> writes data. Continue?', $this->plugin->getName()), false)) {
                $io->warning('Aborted.');

                return Command::SUCCESS;
            }
        }

        $args = $this->collectArgs($input, $io);
        if (null === $args) {
            return Command::INVALID;
        }

        $formatOpt = $input->getOption('format');
        $format = \is_string($formatOpt) ? $formatOpt : $this->plugin->getDefaultFormat();

        // --template-inline wins over --template; fall back to plugin default.
        $templateStringOpt = $input->getOption('template-inline');
        $templateOpt = $input->getOption('template');
        $template = \is_string($templateStringOpt) && '' !== $templateStringOpt
            ? $templateStringOpt
            : (\is_string($templateOpt) && '' !== $templateOpt
                ? $templateOpt
                : $this->plugin->getDefaultTemplate());

        $result = $this->plugin->execute($args);
        $output->writeln($this->renderer->render($result, $format, $template));

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Collect and type-coerce plugin args from CLI options.
     *
     * @return array<string, mixed>|null null when a required param is missing
     */
    private function collectArgs(InputInterface $input, SymfonyStyle $io): ?array
    {
        $args = [];

        foreach ($this->plugin->getParameters() as $name => $spec) {
            $raw = $input->getOption($name);

            if (null === $raw) {
                if ($spec['required'] ?? false) {
                    $io->error("Required option '--{$name}' is missing.");

                    return null;
                }
                continue;
            }

            $args[$name] = $this->coerce($raw, $spec['type'] ?? 'string');
        }

        return $args;
    }

    private function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool', 'boolean' => \is_bool($value) ? $value : (bool) filter_var((string) $value, \FILTER_VALIDATE_BOOLEAN),
            'int', 'integer' => (int) (string) $value,
            'array' => $this->toArray($value),
            default => (string) $value,
        };
    }

    /** @return array<mixed> */
    private function toArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        $str = (string) $value;
        $decoded = json_decode($str, true);

        return \is_array($decoded) ? $decoded : explode(',', $str);
    }

    private function buildHelp(): string
    {
        $lines = [$this->plugin->getUsage(), ''];
        $params = $this->plugin->getParameters();

        if (!empty($params)) {
            $lines[] = '<comment>Parameters:</comment>';
            foreach ($params as $name => $spec) {
                $required = ($spec['required'] ?? false) ? ' <info>[required]</info>' : '';
                $lines[] = "  <info>--{$name}</info>{$required}  ".($spec['description'] ?? '');

                if (isset($spec['example'])) {
                    $lines[] = '    Example: '.$spec['example'];
                }
                if (!empty($spec['enum'])) {
                    $lines[] = '    Allowed: '.implode(', ', (array) $spec['enum']);
                }
            }
        }

        $scopes = $this->plugin->getRequiredScopes();
        if (!empty($scopes)) {
            $lines[] = '';
            $lines[] = '<comment>Required scopes:</comment> '.implode(', ', $scopes);
        }

        return implode("\n", $lines);
    }
}
