<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Hail\Latte\Runtime;

use Hail\Latte;


/**
 * Filter runtime info
 */
class FilterInfo
{
	use Latte\Strict;

	/** @var string|NULL */
	public $contentType;


	public function __construct($contentType = NULL)
	{
		$this->contentType = $contentType;
	}

}
