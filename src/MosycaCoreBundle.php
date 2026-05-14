<?php

declare(strict_types=1);

namespace Mosyca\Core;

use Mosyca\Core\DependencyInjection\Compiler\ActionCommandLoaderPass;
use Mosyca\Core\DependencyInjection\Compiler\ActionRegistrationPass;
use Mosyca\Core\DependencyInjection\Compiler\ResourceRegistrationPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MosycaCoreBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ActionRegistrationPass());
        $container->addCompilerPass(new ResourceRegistrationPass());
        // Run after AddConsoleCommandPass (priority 0) has created console.command_loader.
        $container->addCompilerPass(new ActionCommandLoaderPass(), PassConfig::TYPE_BEFORE_REMOVING, -10);
    }
}
