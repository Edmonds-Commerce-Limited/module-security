<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Integration;

use PHPUnit\Framework\Attributes\Test;
use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test proving the module is mounted, registered and enabled in the test application.
 *
 * If the composer path repository or the docker bind mount is broken this is what fails first.
 */
class ModuleEnabledTest extends TestCase
{
    #[Test]
    public function theModuleIsEnabledInTheTestApplication(): void
    {
        $moduleList = Bootstrap::getObjectManager()->get(ModuleListInterface::class);

        $this->assertTrue(
            $moduleList->has('EdmondsCommerce_Security'),
            'EdmondsCommerce_Security is not enabled in the integration test application'
        );
    }
}
