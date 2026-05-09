<?php

declare(strict_types=1);

namespace Mosyca\Core;

use Mosyca\Core\DependencyInjection\Compiler\PluginCommandLoaderPass;
use Mosyca\Core\DependencyInjection\Compiler\PluginRegistrationPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MosycaCoreBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new PluginRegistrationPass());
        // Run after AddConsoleCommandPass (priority 0) has created console.command_loader.
        $container->addCompilerPass(new PluginCommandLoaderPass(), PassConfig::TYPE_BEFORE_REMOVING, -10);
    }
}
