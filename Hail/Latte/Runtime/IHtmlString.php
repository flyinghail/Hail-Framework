<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Hail\Latte\Runtime;


interface IHtmlString
{

	/**
	 * @return string in HTML format
	 */
	function __toString(): string;

}
