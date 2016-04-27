<?php

/*
 * This file is part of Respect/Validation.
 *
 * (c) Alexandre Gomes Gaigalas <alexandre@gaigalas.net>
 *
 * For the full copyright and license information, please view the "LICENSE.md"
 * file that was distributed with this source code.
 */

namespace Hail\Validation\Rules;

class Prnt extends AbstractCtypeRule
{
    protected function ctypeFunction($input)
    {
        return ctype_print($input);
    }
}
