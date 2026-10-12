<?php

/*
 * MultiSourceBarcodeLookupPlugin — recherche de codes-barres multi-sources pour Grocy (4.7+)
 * https://github.com/darkvadehors/grocy-multisource-barcode-lookup — licence MIT
 *
 * Niveau 1, en parallèle : Open Food Facts, Open Beauty Facts, Open Pet Food Facts, Open Products Facts.
 * Niveau 2, l'une après l'autre (si rien au niveau 1) : Open EAN / GTIN Database, UPCDatabase.org,
 * USDA FoodData Central, UPCitemdb. Le premier produit trouvé est renvoyé.
 *
 * Installation : copier ce fichier dans <GROCY_DATAPATH>/plugins/ puis, dans <GROCY_DATAPATH>/config.php :
 *   Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'MultiSourceBarcodeLookupPlugin');
 * Clés facultatives : <GROCY_DATAPATH>/lookup-keys.json (cf. lookup-keys.example.json).
 */

use Grocy\Helpers\BaseBarcodeLookupPlugin;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;

class MultiSourceBarcodeLookupPlugin extends BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'Multi-sources (Open Food Facts + 7)';

	// Points d'injection pour les tests (le constructeur de la classe de base est final)
	public static $HttpHandler = null;
	public static $KeysFile = null;
	public static $Logger = null;

	private const USER_AGENT = 'GrocyMultiSourceLookup/1.0 (+https://github.com/darkvadehors/grocy-multisource-barcode-lookup)';
	private const CONNECT_TIMEOUT = 3;
	private const TIMEOUT = 4;
	private const BUDGET_SECONDS = 12;
	private const MAX_NAME_LENGTH = 200;
	private const KEY_NAMES = ['opengtindb_queryid', 'upcdatabase_key', 'usda_fdc_key'];

	private const OPEN_FACTS_SOURCES = [
		'Open Food Facts' => 'https://world.openfoodfacts.org',
		'Open Beauty Facts' => 'https://world.openbeautyfacts.org',
		'Open Pet Food Facts' => 'https://world.openpetfoodfacts.org',
		'Open Products Facts' => 'https://world.openproductsfacts.org'
	];

	private const SEQUENTIAL_SOURCES = [
		'Open EAN / GTIN Database' => 'LookupOpenGtinDb',
		'UPCDatabase.org' => 'LookupUpcDatabase',
		'USDA FoodData Central' => 'LookupUsda',
		'UPCitemdb' => 'LookupUpcItemDb'
	];

	private $Keys = [];

	protected function ExecuteLookup($barcode)
	{
		$code = preg_replace('/[^0-9]/', '', (string)$barcode);
		if (strlen($code) < 8 || strlen($code) > 14)
		{
			return null;
		}

		$this->Keys = $this->LoadKeys();
		$client = $this->CreateClient();
		$deadline = microtime(true) + self::BUDGET_SECONDS;

		$found = $this->LookupOpenFacts($client, $code);
		if ($found === null)
		{
			foreach (self::SEQUENTIAL_SOURCES as $source => $method)
			{
				if (microtime(true) >= $deadline)
				{
					$this->Log('budget', 'budget de temps épuisé, sources restantes ignorées');
					break;
				}
				try
				{
					$found = $this->$method($client, $code);
				}
				catch (\Throwable $ex)
				{
					$this->Log($source, get_class($ex) . ': ' . $ex->getMessage());
					$found = null;
				}
				if ($found !== null)
				{
					break;
				}
			}
		}

		return $found === null ? null : $this->ToGrocyProduct($found, $barcode);
	}

	private function LookupOpenFacts(Client $client, string $code)
	{
		$lang = $this->Language();
		$fields = implode(',', ['product_name', "product_name_$lang", 'generic_name', "generic_name_$lang", 'brands', 'image_front_url', 'image_url']);

		$promises = [];
		foreach (self::OPEN_FACTS_SOURCES as $source => $baseUrl)
		{
			$promises[$source] = $client->getAsync("$baseUrl/api/v2/product/$code", ['query' => ['fields' => $fields]]);
		}
		$results = Utils::settle($promises)->wait();

		// Parcours dans l'ordre de priorité, quel que soit l'ordre d'arrivée des réponses
		foreach (array_keys(self::OPEN_FACTS_SOURCES) as $source)
		{
			$result = $results[$source];
			if ($result['state'] !== 'fulfilled')
			{
				$reason = $result['reason'];
				$this->Log($source, $reason instanceof \Throwable ? get_class($reason) . ': ' . $reason->getMessage() : 'échec');
				continue;
			}
			$data = $this->DecodeJson($source, $result['value'], [404]);
			if ($data === null || ($data['status'] ?? 0) != 1 || !is_array($data['product'] ?? null))
			{
				continue;
			}
			$product = $data['product'];
			$name = $this->FirstString([$product["product_name_$lang"] ?? null, $product["generic_name_$lang"] ?? null, $product['product_name'] ?? null, $product['generic_name'] ?? null]);
			if ($name === null)
			{
				continue;
			}
			return [
				'source' => $source,
				'name' => $name,
				'brand' => $this->FirstBrand($product['brands'] ?? null),
				'image' => $this->FirstString([$product['image_front_url'] ?? null, $product['image_url'] ?? null])
			];
		}
		return null;
	}

	private function LookupOpenGtinDb(Client $client, string $code)
	{
		$queryId = $this->Keys['opengtindb_queryid'] ?? null;
		if ($queryId === null || strlen($code) > 13)
		{
			return null;
		}
		$response = $client->get('https://opengtindb.org/', ['query' => ['ean' => str_pad($code, 13, '0', STR_PAD_LEFT), 'cmd' => 'query', 'queryid' => $queryId]]);
		if ($response->getStatusCode() != 200)
		{
			$this->Log('Open EAN / GTIN Database', 'HTTP ' . $response->getStatusCode());
			return null;
		}

		$body = (string)$response->getBody();
		if (!mb_check_encoding($body, 'UTF-8'))
		{
			$body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
		}

		// Format clé=valeur ; « --- » sépare l'en-tête et les enregistrements : on garde le premier produit
		$fields = [];
		$records = 0;
		foreach (preg_split('/\r?\n/', $body) as $line)
		{
			if ($line === '---')
			{
				if (++$records > 1) { break; }
				continue;
			}
			$position = strpos($line, '=');
			if ($position !== false && !array_key_exists(substr($line, 0, $position), $fields))
			{
				$fields[substr($line, 0, $position)] = substr($line, $position + 1);
			}
		}

		$error = $fields['error'] ?? '';
		if ($error !== '0')
		{
			if ($error !== '1')
			{
				$this->Log('Open EAN / GTIN Database', "erreur $error");
			}
			return null;
		}
		$name = $this->FirstString([$fields['detailname'] ?? null, $fields['name'] ?? null]);
		return $name === null ? null : ['source' => 'Open EAN / GTIN Database', 'name' => $name, 'brand' => $this->FirstBrand($fields['vendor'] ?? null), 'image' => null];
	}

	private function LookupUpcDatabase(Client $client, string $code)
	{
		$key = $this->Keys['upcdatabase_key'] ?? null;
		if ($key === null)
		{
			return null;
		}
		$data = $this->DecodeJson('UPCDatabase.org', $client->get("https://api.upcdatabase.org/product/$code", ['query' => ['apikey' => $key]]), [404]);
		if ($data === null || (array_key_exists('success', $data) && $data['success'] === false))
		{
			return null;
		}
		$name = $this->FirstString([$data['title'] ?? null, $data['description'] ?? null]);
		$images = is_array($data['images'] ?? null) ? array_values($data['images']) : [];
		return $name === null ? null : ['source' => 'UPCDatabase.org', 'name' => $name, 'brand' => $this->FirstBrand($data['brand'] ?? null), 'image' => $this->FirstString($images)];
	}

	private function LookupUsda(Client $client, string $code)
	{
		$key = $this->Keys['usda_fdc_key'] ?? null;
		if ($key === null)
		{
			return null;
		}
		$data = $this->DecodeJson('USDA FoodData Central', $client->get('https://api.nal.usda.gov/fdc/v1/foods/search', ['query' => ['api_key' => $key, 'dataType' => 'Branded', 'query' => $code, 'pageSize' => 10]]), []);
		if ($data === null || !is_array($data['foods'] ?? null))
		{
			return null;
		}
		foreach ($data['foods'] as $food)
		{
			// La recherche USDA est plein texte : seule une correspondance exacte du code est acceptée
			if (!is_array($food) || !is_string($food['gtinUpc'] ?? null) || ltrim($food['gtinUpc'], '0') !== ltrim($code, '0'))
			{
				continue;
			}
			$name = $this->FirstString([$food['description'] ?? null]);
			if ($name !== null)
			{
				$brand = $this->FirstBrand($food['brandName'] ?? ($food['brandOwner'] ?? null));
				return ['source' => 'USDA FoodData Central', 'name' => $this->TitleCaseIfUpper($name), 'brand' => $brand === null ? null : $this->TitleCaseIfUpper($brand), 'image' => null];
			}
		}
		return null;
	}

	private function LookupUpcItemDb(Client $client, string $code)
	{
		$data = $this->DecodeJson('UPCitemdb', $client->get('https://api.upcitemdb.com/prod/trial/lookup', ['query' => ['upc' => $code]]), [400, 404]);
		if ($data === null || ($data['code'] ?? '') !== 'OK' || !is_array($data['items'][0] ?? null))
		{
			return null;
		}
		$item = $data['items'][0];
		$name = $this->FirstString([$item['title'] ?? null]);
		$images = is_array($item['images'] ?? null) ? array_values($item['images']) : [];
		return $name === null ? null : ['source' => 'UPCitemdb', 'name' => $name, 'brand' => $this->FirstBrand($item['brand'] ?? null), 'image' => $this->FirstString($images)];
	}

	private function ToGrocyProduct(array $found, $barcode)
	{
		// Même logique que le plugin Open Food Facts livré : préférences utilisateur, sinon premier élément
		$locationId = $this->Locations[0]->id;
		if (($this->UserSettings['product_presets_location_id'] ?? -1) != -1)
		{
			$locationId = $this->UserSettings['product_presets_location_id'];
		}
		$quId = $this->QuantityUnits[0]->id;
		if (($this->UserSettings['product_presets_qu_id'] ?? -1) != -1)
		{
			$quId = $this->UserSettings['product_presets_qu_id'];
		}

		$image = $found['image'] ?? null;
		if (!is_string($image) || !preg_match('#^https://#i', $image))
		{
			$image = '';
		}

		return [
			'name' => $this->BuildName($found['name'], $found['brand'] ?? null),
			'location_id' => $locationId,
			'qu_id_purchase' => $quId,
			'qu_id_stock' => $quId,
			'__qu_factor_purchase_to_stock' => 1,
			'__barcode' => $barcode,
			'__image_url' => $image,
			'description' => 'Source : ' . $found['source']
		];
	}

	private function BuildName(string $name, ?string $brand)
	{
		if ($brand !== null && !str_contains($this->Fold($name), $this->Fold($brand)))
		{
			$name = "$name ($brand)";
		}
		return mb_substr($name, 0, self::MAX_NAME_LENGTH);
	}

	private function DecodeJson(string $source, ResponseInterface $response, array $silentStatuses)
	{
		$status = $response->getStatusCode();
		if (in_array($status, $silentStatuses, true))
		{
			return null;
		}
		if ($status != 200)
		{
			$this->Log($source, "HTTP $status");
			return null;
		}
		$data = json_decode(mb_convert_encoding((string)$response->getBody(), 'UTF-8', 'UTF-8'), true);
		if (!is_array($data))
		{
			$this->Log($source, 'réponse JSON invalide');
			return null;
		}
		return $data;
	}

	private function FirstString(array $candidates)
	{
		foreach ($candidates as $candidate)
		{
			$clean = $this->Clean($candidate);
			if ($clean !== null)
			{
				return $clean;
			}
		}
		return null;
	}

	private function FirstBrand($brands)
	{
		return is_string($brands) ? $this->Clean(explode(',', $brands)[0]) : null;
	}

	private function Clean($value)
	{
		if (!is_string($value))
		{
			return null;
		}
		$value = strip_tags($value);
		$value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		return $value === '' ? null : mb_substr($value, 0, self::MAX_NAME_LENGTH);
	}

	private function Fold(string $value)
	{
		$value = mb_strtolower($value);
		if (class_exists('Normalizer'))
		{
			$value = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($value, \Normalizer::FORM_D)) ?? $value;
		}
		return $value;
	}

	private function TitleCaseIfUpper(string $value)
	{
		return mb_strtoupper($value) === $value ? mb_convert_case(mb_strtolower($value), MB_CASE_TITLE) : $value;
	}

	private function Language()
	{
		$lang = defined('GROCY_LOCALE') ? strtolower(substr(GROCY_LOCALE, 0, 2)) : 'en';
		return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
	}

	private function LoadKeys()
	{
		$file = self::$KeysFile ?? (defined('GROCY_DATAPATH') ? GROCY_DATAPATH . '/lookup-keys.json' : null);
		if ($file === null || !is_file($file) || !is_readable($file))
		{
			return [];
		}
		$data = json_decode((string)file_get_contents($file), true);
		$keys = [];
		foreach (self::KEY_NAMES as $name)
		{
			if (is_array($data) && is_string($data[$name] ?? null) && trim($data[$name]) !== '')
			{
				$keys[$name] = trim($data[$name]);
			}
		}
		return $keys;
	}

	private function CreateClient()
	{
		$options = [
			'http_errors' => false,
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'timeout' => self::TIMEOUT,
			'headers' => ['User-Agent' => self::USER_AGENT]
		];
		if (self::$HttpHandler !== null)
		{
			$options['handler'] = self::$HttpHandler;
		}
		return new Client($options);
	}

	private function Log(string $source, string $message)
	{
		// Les messages d'erreur Guzzle contiennent l'URL complète, donc les clés : on les masque
		foreach ($this->Keys as $key)
		{
			$message = str_replace([$key, rawurlencode($key)], '***', $message);
		}
		$message = preg_replace('/((?:api_?key|queryid)=)[^&\s"\']+/i', '$1***', $message) ?? '';
		$line = "[MultiSourceBarcodeLookupPlugin] $source : $message";
		if (is_callable(self::$Logger))
		{
			(self::$Logger)($line);
		}
		else
		{
			error_log($line);
		}
	}
}
