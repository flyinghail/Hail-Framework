<?php

/*
 * This file is part of Respect/Validation.
 *
 * (c) Alexandre Gomes Gaigalas <alexandre@gaigalas.net>
 *
 * For the full copyright and license information, please view the "LICENSE.md"
 * file that was distributed with this source code.
 */

namespace Hail\Validation\Rules\SubdivisionCode;

use Hail\Validation\Rules\AbstractSearcher;

/**
 * Validator for Åland subdivision code.
 *
 * ISO 3166-1 alpha-2: AX
 *
 * @link http://www.geonames.org/AX/administrative-division-aland.html
 */
class AxSubdivisionCode extends AbstractSearcher
{
    public $haystack = [null, ''];

    public $compareIdentical = true;
}
