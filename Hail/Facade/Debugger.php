<?php
namespace Hail\Facade;

/**
 * Class Debugger
 *
 * @package Hail\Facade
 */
class Debugger extends Facade
{
	protected static $alias = \Hail\Tracy\Debugger::class;
}