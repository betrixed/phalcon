<?php

/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phalcon\Tests\Unit\Acl\Adapter\Memory;

use Phalcon\Acl\Adapter\Memory;
use Phalcon\Acl\Enum;
use UnitTester;

/**
 * Class GetActiveRoleCest
 *
 * @package Phalcon\Tests\Unit\Acl\Adapter\Memory
 */
class GetActiveRoleCest
{
    /**
     * Tests Phalcon\Acl\Adapter\Memory :: getActiveRole() - default
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2020-09-09
     */
    public function aclAdapterMemoryGetActiveRoleDefault(UnitTester $I)
    {
        $I->wantToTest('Acl\Adapter\Memory - getActiveRole() - default');

        $acl = new Memory();

        $I->assertNull(
            $acl->getActiveRole()
        );
    }

    /**
     * Tests Phalcon\Acl\Adapter\Memory :: getActiveRole() - default
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2020-09-09
     */
    public function aclAdapterMemoryGetActiveRole(UnitTester $I)
    {
        $I->wantToTest('Acl\Adapter\Memory - getActiveRole()');

        $acl = new Memory();
        $acl->setDefaultAction(Enum::DENY);
        $acl->addRole('Guests');
        $acl->addComponent('Login', ['help', 'index']);
        $acl->allow('Guests', 'Login', '*');


        $I->assertTrue(
            $acl->isAllowed('Guests', 'Login', 'index')
        );

        $I->assertEquals('Guests', $acl->getActiveRole());
    }
}
