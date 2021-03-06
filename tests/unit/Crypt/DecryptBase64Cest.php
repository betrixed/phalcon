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

namespace Phalcon\Tests\Unit\Crypt;

use Phalcon\Crypt\Crypt;
use Phalcon\Crypt\Mismatch;
use UnitTester;

/**
 * Class DecryptBase64Cest
 *
 * @package Phalcon\Tests\Unit\Crypt
 */
class DecryptBase64Cest
{
    /**
     * Tests decrypt without using HMAC
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2020-09-09
     * @issue  https://github.com/phalcon/cphalcon/issues/13379
     */
    public function shouldNotThrowExceptionIfKeyMismatch(UnitTester $I)
    {
        $I->wantToTest(
            'Crypt - decryptBase64() not throwing Exception on key mismatch'
        );

        $crypt = new Crypt();

        $actual = $crypt->decryptBase64(
            $crypt->encryptBase64('le text', 'encrypt key'),
            'wrong key'
        );

        $I->assertNotEmpty($actual);
    }

    /**
     * Tests decrypt using HMAC
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2020-09-09
     * @issue  https://github.com/phalcon/cphalcon/issues/13379
     */
    public function shouldThrowExceptionIfHashMismatch(UnitTester $I)
    {
        $I->expectThrowable(
            new Mismatch('Hash does not match.'),
            function () {
                $crypt = new Crypt();

                $crypt->useSigning(true);

                $crypt->decryptBase64(
                    $crypt->encryptBase64('le text', 'encrypt key'),
                    'wrong key'
                );
            }
        );
    }

    /**
     * Tests decrypt using HMAC
     *
     * @param UnitTester $I
     *
     * @author Phalcon Team <team@phalcon.io>
     * @since  2020-09-09
     * @issue  https://github.com/phalcon/cphalcon/issues/13379
     */
    public function shouldDecryptSignedString(UnitTester $I)
    {
        $crypt = new Crypt();

        $crypt->useSigning(true);

        $crypt->setKey('secret');

        $expected  = 'le text';
        $encrypted = $crypt->encryptBase64($expected);
        $actual    = $crypt->decryptBase64($encrypted);

        $I->assertEquals($expected, $actual);
    }
}
