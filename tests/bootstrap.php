<?php

// Charge le vrai code de Grocy (classe de base, helpers, Guzzle) puis le plugin.
$grocySource = getenv('GROCY_SRC') ?: __DIR__ . '/../.cache/grocy-4.7.1';
if (!is_file("$grocySource/packages/autoload.php"))
{
	fwrite(STDERR, "Sources Grocy introuvables ($grocySource) : lancer tests/fetch-grocy.sh\n");
	exit(2);
}

require_once "$grocySource/packages/autoload.php";
require_once "$grocySource/helpers/extensions.php";
require_once "$grocySource/helpers/BaseBarcodeLookupPlugin.php";

define('GROCY_LOCALE', 'fr');
define('GROCY_DATAPATH', sys_get_temp_dir());

require_once __DIR__ . '/../MultiSourceBarcodeLookupPlugin.php';
