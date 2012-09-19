<?php
/*
 * This file is part of the Mouf core package.
 *
 * (c) 2012 David Negrier <david@mouf-php.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */
 

define('ROOT_URL', '/mouf2/');
/**
 * This is a file automatically generated by the Mouf framework. Do not put any code except 'define' operations
 * as it could be overwritten.
 * Instead, use the Mouf User Interface to set all your constants: http://[server]/mouf/mouf/config
 */

/**
 * If you set this variable to true, your Mouf installation will act as a repository: it will allow other Mouf installations to download any package available.
 * By default, this is behaviour is disabled.
 */
define('ACT_AS_REPOSITORY', true);
/**
 * If true, the repository will accept package upload. When a package is requested for download, it will send the uploaded version (it will not ZIP the package each time), so this setting should be set to FALSE on a developer machine, and only to TRUE on a repository.
 */
define('UPLOAD_REPOSITORY', false);
