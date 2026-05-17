<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Functional;

use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Action\Identity\GroupListAction;
use Mosyca\Core\Action\Identity\GroupReadAction;
use Mosyca\Core\Action\Identity\TenantListAction;
use Mosyca\Core\Action\Identity\TenantReadAction;
use Mosyca\Core\Action\Identity\UserListAction;
use Mosyca\Core\Action\Identity\UserReadAction;
use Mosyca\Core\MosycaCoreBundle;
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

        // Fixture identity data for ADR 1.5 provider tests (Rule ID1).
        $container->extension('mosyca', [
            'identity' => [
                'tenants' => [
                    'demecan_gmbh' => ['name' => 'Demecan GmbH', 'metadata' => ['region' => 'EU']],
                    'theranova_digital' => ['name' => 'Theranova Digital Solutions'],
                ],
                'users' => [
                    'operator_roland' => [
                        'email' => 'r.urban@example.com',
                        'display_name' => 'Roland Urban',
                        'groups' => ['admins'],
                        'allowed_tenants' => ['demecan_gmbh'],
                    ],
                ],
                'groups' => [
                    'admins' => [
                        'roles' => ['ROLE_MOSYCA_ADMIN'],
                        'permissions' => ['*'],
                    ],
                    'support' => [
                        'roles' => ['ROLE_MOSYCA_OPERATOR'],
                        'permissions' => ['mosyca_tenant_read'],
                    ],
                ],
            ],
        ]);

        // Simulate what an application's services.yaml would do:
        // register action classes as services so _instanceof can tag them.
        $container->services()
            ->set(PingAction::class)->autoconfigure()->autowire()
            ->set(EchoAction::class)->autoconfigure()->autowire()
            ->set(TenantReadAction::class)->autoconfigure()->autowire()
            ->set(TenantListAction::class)->autoconfigure()->autowire()
            ->set(UserReadAction::class)->autoconfigure()->autowire()
            ->set(UserListAction::class)->autoconfigure()->autowire()
            ->set(GroupReadAction::class)->autoconfigure()->autowire()
            ->set(GroupListAction::class)->autoconfigure()->autowire();
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
