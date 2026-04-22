<?php

namespace ClassicImporter\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ClassicImporter\DumpManager;

class DumpManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $dumpManager = new DumpManager($container);

        return $dumpManager;
    }
}
