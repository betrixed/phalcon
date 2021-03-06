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

namespace Phalcon\Tests\Unit\Di\Service;

use UnitTester;

/**
 * Class IsResolvedCest
 *
 * @package Phalcon\Tests\Unit\Di\Service
 */
class IsResolvedCest
{
    /**
     * Unit Tests Phalcon\Di\Service :: isResolved()
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2019-09-09
     */
    public function diServiceIsResolved(UnitTester $I)
    {
        $I->wantToTest('Di\Service - isResolved()');

        $I->skipTest('Need implementation');
    }
}
