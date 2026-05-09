<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Functional;

use Mosyca\Core\MosycaCoreBundle;
use Mosyca\Core\Plugin\Builtin\EchoPlugin;
use Mosyca\Core\Plugin\Builtin\PingPlugin;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MosycaCoreBundle();
    }

    protected function configureContainer(ContainerConfigurator $container, \Symfony\Component\Config\Loader\LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        // Simulate what an application's services.yaml would do:
        // register plugin classes as services so _instanceof can tag them.
        $container->services()
            ->set(PingPlugin::class)->autoconfigure()->autowire()
            ->set(EchoPlugin::class)->autoconfigure()->autowire();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mosyca-core-test/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/mosyca-core-test/log';
    }
}
