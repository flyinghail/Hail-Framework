<?php
/**
 * Created by IntelliJ IDEA.
 * User: FlyingHail
 * Date: 2015/6/30 0030
 * Time: 17:43
 */

function _e($msg)
{
	echo _($msg);
}

function _n($msg, $msg_plural, $count)
{
	return Gettext::ngettext($msg, $msg_plural, $count);
}

function _d($domain, $msg)
{
	return Gettext::dgettext($domain, $msg);
}

function _dn($domain, $msg, $msg_plural, $count)
{
	return Gettext::dngettext($domain, $msg, $msg_plural, $count);
}