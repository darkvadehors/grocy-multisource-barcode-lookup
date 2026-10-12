<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeHttp.php';

use GuzzleHttp\Psr7\Response;

const OFF = 'world.openfoodfacts.org';
const OBF = 'world.openbeautyfacts.org';
const OPFF = 'world.openpetfoodfacts.org';
const OPF = 'world.openproductsfacts.org';
const GTIN = 'opengtindb.org';
const UPCDB = 'api.upcdatabase.org';
const USDA = 'api.nal.usda.gov';
const UPCITEM = 'api.upcitemdb.com';
const ALL_KEYS = ['opengtindb_queryid' => 'QID+SECRET/1=', 'upcdatabase_key' => 'UPC SECRET&2', 'usda_fdc_key' => 'USDA-SECRET-3'];

$tests = [];
$logs = [];
$clock = 0.0;

function test(string $name, callable $fn): void { global $tests; $tests[$name] = $fn; }
function check(bool $condition, string $message): void { if (!$condition) { throw new Exception($message); } }
function json(array $data, int $status = 200): Response { return new Response($status, ['Content-Type' => 'application/json'], json_encode($data)); }
function offFound(array $product): Response { return json(['status' => 1, 'product' => $product]); }

function plugin(FakeHttp $http, array $keys = [], array $settings = []): MultiSourceBarcodeLookupPlugin
{
	global $logs;
	$logs = [];
	MultiSourceBarcodeLookupPlugin::$HttpHandler = $http->Handler();
	$keysFile = tempnam(sys_get_temp_dir(), 'lookup-keys');
	file_put_contents($keysFile, json_encode($keys));
	MultiSourceBarcodeLookupPlugin::$KeysFile = $keysFile;
	MultiSourceBarcodeLookupPlugin::$Logger = function (string $message) { global $logs; $logs[] = $message; };
	MultiSourceBarcodeLookupPlugin::$Clock = null;
	$locations = [(object)['id' => 1], (object)['id' => 5]];
	$quantityUnits = [(object)['id' => 2], (object)['id' => 7]];
	$userSettings = array_merge(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1], $settings);
	return new MultiSourceBarcodeLookupPlugin($locations, $quantityUnits, $userSettings);
}

test('T1 Open Food Facts trouve : nom localisé, marque, image, source', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name' => 'Nutella', 'product_name_fr' => 'Nutella', 'brands' => 'Ferrero,Nutella', 'image_front_url' => 'https://images.openfoodfacts.org/n.jpg']);
	$r = plugin($http)->Lookup('3017620422003');
	check($r['name'] === 'Nutella (Ferrero)', 'nom : ' . $r['name']);
	check($r['__image_url'] === 'https://images.openfoodfacts.org/n.jpg', 'image');
	check($r['description'] === 'Source : Open Food Facts', 'description');
	check($r['__barcode'] === '3017620422003' && $r['location_id'] === 1 && $r['qu_id_stock'] === 2, 'champs Grocy');
});

test('T1b marque déjà dans le nom (casse et accents ignorés) : pas de doublon', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name_fr' => 'Crème ÉNERGIE Lait', 'brands' => 'Energie']);
	$r = plugin($http)->Lookup('3000000000001');
	check($r['name'] === 'Crème ÉNERGIE Lait', 'nom : ' . $r['name']);
});

test('T2 OFF introuvable, Open Beauty Facts trouve', function () {
	$http = new FakeHttp();
	$http->Routes[OBF] = offFound(['product_name' => 'Nivea Deo', 'brands' => 'Nivea', 'image_url' => 'https://images.openbeautyfacts.org/x.jpg']);
	$r = plugin($http)->Lookup('4005808837311');
	check($r['name'] === 'Nivea Deo', 'nom : ' . $r['name']);
	check($r['description'] === 'Source : Open Beauty Facts', 'source');
});

test('T3 OFF et Open Products Facts trouvent : OFF prioritaire', function () {
	$http = new FakeHttp();
	$http->Routes[OPF] = offFound(['product_name' => 'Produit OPF']);
	$http->Routes[OFF] = offFound(['product_name' => 'Produit OFF']);
	$r = plugin($http)->Lookup('3000000000002');
	check($r['name'] === 'Produit OFF', 'nom : ' . $r['name']);
});

test('T4 niveau 2 dans l\'ordre, arrêt au premier trouvé', function () {
	$http = new FakeHttp();
	$http->Routes[GTIN] = new Response(200, [], "error=1\n---\n");
	$http->Routes[UPCDB] = json(['success' => true, 'title' => 'Lessive Liquide', 'brand' => 'Xtra']);
	$r = plugin($http, ALL_KEYS)->Lookup('3000000000003');
	check($r['name'] === 'Lessive Liquide (Xtra)' && $r['description'] === 'Source : UPCDatabase.org', 'résultat : ' . json_encode($r));
	check(!in_array(USDA, $http->Calls) && !in_array(UPCITEM, $http->Calls), 'USDA/UPCitemdb appelés : ' . implode(',', $http->Calls));
	check(array_search(GTIN, $http->Calls) < array_search(UPCDB, $http->Calls), 'ordre');
});

test('T5 sans clés : sources à clé non appelées, UPCitemdb appelée', function () {
	$http = new FakeHttp();
	$http->Routes[UPCITEM] = json(['code' => 'OK', 'items' => [['title' => 'Stylo Bille', 'brand' => 'Bic', 'images' => ['https://img/s.jpg']]]]);
	$r = plugin($http)->Lookup('3000000000004');
	check($r['name'] === 'Stylo Bille (Bic)' && $r['__image_url'] === '', 'résultat : ' . json_encode($r));
	foreach ([GTIN, UPCDB, USDA] as $h) { check(!in_array($h, $http->Calls), "$h appelé sans clé"); }
});

test('T6 USDA : gtinUpc différent ignoré, correspondance exacte retenue (zéros de tête)', function () {
	$http = new FakeHttp();
	$http->Routes[USDA] = json(['foods' => [
		['gtinUpc' => '999999999999', 'description' => 'AUTRE PRODUIT'],
		['gtinUpc' => '009800895007', 'description' => 'HAZELNUT SPREAD WITH COCOA', 'brandName' => 'NUTELLA']
	]]);
	$r = plugin($http, ['usda_fdc_key' => 'k'])->Lookup('9800895007');
	check($r['name'] === 'Hazelnut Spread With Cocoa (Nutella)', 'nom : ' . $r['name']);
	$http2 = new FakeHttp();
	$http2->Routes[USDA] = json(['foods' => [['gtinUpc' => '111', 'description' => 'X']]]);
	check(plugin($http2, ['usda_fdc_key' => 'k'])->Lookup('3000000000006') === null, 'non-correspondance acceptée');
});

test('T7 pannes partout (délai, 500, JSON invalide, 429, erreur 5) : null, aucune exception, journalisé', function () {
	global $logs;
	$http = new FakeHttp();
	$http->Routes[OFF] = 'timeout';
	$http->Routes[OBF] = new Response(500, [], 'oops');
	$http->Routes[OPFF] = new Response(200, [], '<html>pas du json</html>');
	$http->Routes[GTIN] = new Response(200, [], "error=5\n---\n");
	$http->Routes[UPCDB] = 'timeout';
	$http->Routes[USDA] = new Response(500, [], '');
	$http->Routes[UPCITEM] = json(['code' => 'TOO_FAST'], 429);
	check(plugin($http, ALL_KEYS)->Lookup('3000000000007') === null, 'résultat non nul');
	check(count($logs) === 7, 'journal : ' . count($logs));
});

test('T7b pannes au niveau 1 mais UPCitemdb trouve', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = 'timeout';
	$http->Routes[UPCITEM] = json(['code' => 'OK', 'items' => [['title' => 'Piles AA']]]);
	check(plugin($http)->Lookup('3000000000008')['name'] === 'Piles AA', 'nom');
});

test('T8 aucune source ne trouve : null', function () {
	$http = new FakeHttp();
	$http->Routes[GTIN] = new Response(200, [], "error=1\n---\n");
	$http->Routes[UPCITEM] = json(['code' => 'INVALID_UPC'], 400);
	check(plugin($http, ALL_KEYS)->Lookup('3000000000009') === null, 'non nul');
});

test('T9 code-barres invalide : null, aucune requête', function () {
	foreach (['abc', '12345', '123456789012345'] as $code) {
		$http = new FakeHttp();
		check(plugin($http)->Lookup($code) === null && $http->Calls === [], "code $code");
	}
});

test('T10 Open EAN en ISO-8859-1 : nom converti en UTF-8, detailname prioritaire', function () {
	$http = new FakeHttp();
	$http->Routes[GTIN] = new Response(200, [], mb_convert_encoding("error=0\n---\nname=Kase\ndetailname=Käse Gouda jung\nvendor=Müller\n---\n", 'ISO-8859-1', 'UTF-8'));
	$r = plugin($http, ['opengtindb_queryid' => 'q'])->Lookup('4000000000010');
	check($r['name'] === 'Käse Gouda jung (Müller)', 'nom : ' . $r['name']);
	check($r['description'] === 'Source : Open EAN / GTIN Database', 'source');
});

test('T12 aucune clé dans le journal, même si l\'erreur contient l\'URL', function () {
	global $logs;
	$http = new FakeHttp();
	foreach ([GTIN, UPCDB, USDA] as $h) { $http->Routes[$h] = 'timeout'; }
	plugin($http, ALL_KEYS)->Lookup('3000000000012');
	$all = implode("\n", $logs);
	check($all !== '', 'aucun journal');
	foreach (ALL_KEYS as $secret) { check(!str_contains($all, $secret), "clé exposée : $secret"); }
});

test('T13 image non https ignorée', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name' => 'Produit', 'image_url' => 'http://insecure/x.jpg']);
	check(plugin($http)->Lookup('3000000000013')['__image_url'] === '', 'image http acceptée');
});

test('T14 préférences utilisateur d\'emplacement et d\'unité respectées', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name' => 'Produit']);
	$r = plugin($http, [], ['product_presets_location_id' => 5, 'product_presets_qu_id' => 7])->Lookup('3000000000014');
	check($r['location_id'] === 5 && $r['qu_id_purchase'] === 7 && $r['qu_id_stock'] === 7, 'préférences');
});

test('T15 réponse inattendue : types bizarres, HTML, nom très long', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name' => ['pas', 'une chaîne'], 'brands' => 42]);
	$http->Routes[OBF] = offFound(['product_name' => "<b>Gel</b>\x01 douche   " . str_repeat('x', 400), 'brands' => '  Dove ,Unilever']);
	$r = plugin($http)->Lookup('3000000000015');
	check(str_starts_with($r['name'], 'Gel douche xxx'), 'nettoyage : ' . substr($r['name'], 0, 30));
	check(mb_strlen($r['name']) <= 200, 'longueur ' . mb_strlen($r['name']));
});

test('T16 budget de 12 s : délai de chaque requête borné, sources restantes sautées', function () {
	global $clock;
	$clock = 0.0;
	$timeouts = [];
	$slow = function ($request) use (&$timeouts) { return new Response(404, [], ''); };
	$http = new FakeHttp();
	foreach ([GTIN, UPCDB, USDA, UPCITEM] as $h) {
		$http->Routes[$h] = function ($request, $options = []) use ($h, &$timeouts) { global $clock; $timeouts[$h] = $options['timeout'] ?? null; $clock += 4.5; return new Response(404, [], "error=1\n"); };
	}
	$plugin = plugin($http, ALL_KEYS);
	MultiSourceBarcodeLookupPlugin::$Clock = function () { global $clock; return $clock; };
	check($plugin->Lookup('3000000000016') === null, 'non nul');
	check(!in_array(UPCITEM, $http->Calls), 'UPCitemdb appelée après le budget : ' . implode(',', $http->Calls));
	check(($timeouts[USDA] ?? 99) <= 3.0 + 1e-9, 'délai USDA non borné : ' . var_export($timeouts[USDA] ?? null, true));
	check(($timeouts[GTIN] ?? 99) <= 4.0, 'délai Open EAN : ' . var_export($timeouts[GTIN] ?? null, true));
});

test('T17 images : seules les images des bases Open … Facts (jpg/png/webp/gif) sont transmises', function () {
	$cases = [
		'https://images.openfoodfacts.org/images/products/301/762/042/2003/front_fr.jpg' => true,
		'https://images.openbeautyfacts.org/images/products/x/front.png' => true,
		'https://evil.example/images.openfoodfacts.org/x.jpg' => false,
		'https://images.openfoodfacts.org.evil.example/x.jpg' => false,
		'https://images.openfoodfacts.org/images/x.php' => false,
		'https://images.openfoodfacts.org/images/x.jpg?.php' => false,
	];
	foreach ($cases as $url => $accepted) {
		$http = new FakeHttp();
		$http->Routes[OFF] = offFound(['product_name' => 'Produit', 'image_front_url' => $url]);
		$r = plugin($http)->Lookup('3000000000017');
		check(($r['__image_url'] === $url) === $accepted, "$url : " . $r['__image_url']);
	}
	$http = new FakeHttp();
	$http->Routes[UPCITEM] = json(['code' => 'OK', 'items' => [['title' => 'Objet', 'images' => ['https://images.openfoodfacts.org/x.jpg']]]]);
	check(plugin($http)->Lookup('3000000000018')['__image_url'] === '', 'image UPCitemdb transmise');
});

test('T18 contenance ajoutée au nom (deux formats du même produit = deux noms)', function () {
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name_fr' => 'Nutella', 'brands' => 'Ferrero', 'quantity' => '750 g']);
	check(plugin($http)->Lookup('3017620411991')['name'] === 'Nutella (Ferrero) 750 g', 'nom');
	$http = new FakeHttp();
	$http->Routes[OFF] = offFound(['product_name_fr' => 'Lait demi-écrémé 1 L', 'brands' => 'Lactel', 'quantity' => '1 l']);
	check(plugin($http)->Lookup('3000000000019')['name'] === 'Lait demi-écrémé 1 L (Lactel)', 'contenance déjà dans le nom');
});

test('T19 redirections refusées : option transmise, réponse 301 ignorée', function () {
	$seen = [];
	$http = new FakeHttp();
	foreach ([OFF, OBF, OPFF, OPF, UPCITEM] as $h) {
		$http->Routes[$h] = function ($request, $options = []) use (&$seen) { $seen[] = $options['allow_redirects'] ?? 'absent'; return new Response(301, ['Location' => 'https://ailleurs.example/']); };
	}
	check(plugin($http)->Lookup('3000000000020') === null, 'non nul');
	check(count($seen) === 5 && count(array_filter($seen, fn($v) => $v !== false)) === 0, 'allow_redirects : ' . json_encode($seen));
});

test('T20 extras/auto-external-lookup.html : aucune balise PHP (Grocy l\'inclut avec include)', function () {
	$file = __DIR__ . '/../extras/auto-external-lookup.html';
	check(is_file($file), 'fichier absent');
	$content = file_get_contents($file);
	check(!str_contains($content, '<?') && !str_contains($content, '?>'), 'balise PHP présente');
	check(str_contains($content, 'add-new-product-plugin-dialog-button') && str_contains($content, "Nothing was found for the given barcode"), 'sélecteurs Grocy attendus absents');
});

$failed = 0;
foreach ($tests as $name => $fn)
{
	try { $fn(); echo "PASS $name\n"; }
	catch (Throwable $ex) { $failed++; echo "FAIL $name — " . $ex->getMessage() . "\n"; }
}
$total = count($tests);
echo 'SCORE: ' . ($total - $failed) . "/$total\n";
exit($failed === 0 ? 0 : 1);
