<?php
/*
 * This file is part of the Mouf core package.
 *
 * (c) 2012 David Negrier <david@mouf-php.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

use Mouf\MoufManager;

//require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../../../../vendor/autoload.php';

//////////////// The autoloader for Mouf //////////////////:

// Let's add to the project's autoloader with Mouf classes.
// Mouf classes will overwrite the projet's classes.
$loader = ComposerAutoloaderInit::getLoader(); 
$map = require __DIR__ . '/../vendor/composer/autoload_namespaces.php';
foreach ($map as $namespace => $path) {
	$loader->add($namespace, $path);
}

$classMap = require __DIR__ . '/../vendor/composer/autoload_classmap.php';
if ($classMap) {
	$loader->addClassMap($classMap);
}

require_once  __DIR__ . '/../vendor/mouf/utils.i18n.fine/src/msgFunctions.php';
require_once  __DIR__ . '/../vendor/mouf/utils.common.getvars/src/tcm_utils.php';
require_once __DIR__ . '/../vendor/mouf/utils.common.getvars/src/TcmUtilsException.php';

//////////////// End of the autoloader for Mouf //////////////////:

require_once __DIR__.'/../../../../mouf/MoufComponents.php';

// FIXME: rewrite this to support many MoufComponents!!!
// Maybe with a "default" environment (first loaded) and a "getMoufManagerByName()" that loads on the fly?
// Scopes: APP - MOUF - DEFAULT? (first loaded)
// Autre idée: getMoufManager("adresse du fichier!")
MoufManager::switchToHidden();
require_once 'MoufComponents.php';


define('ROOT_PATH', realpath(__DIR__."/..").DIRECTORY_SEPARATOR);

require_once __DIR__.'/../config.php';

define('MOUF_URL', ROOT_URL);


// We are part of mouf, let's chain with the main autoloader if it exists.
/*if (file_exists(__DIR__.'/../../../../vendor/autoload.php')) {
	require_once __DIR__.'/../../../../vendor/autoload.php';
}*/

// Finally, let's include the MoufUI if it exists.
if (file_exists(__DIR__.'/../../../../mouf/MoufUI.php')) {
	require_once __DIR__.'/../../../../mouf/MoufUI.php';
}

?>