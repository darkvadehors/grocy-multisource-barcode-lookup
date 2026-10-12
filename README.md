# Grocy multi-source barcode lookup

A [Grocy](https://grocy.info) barcode lookup plugin that queries **8 free product databases** instead of Open Food Facts alone — so cosmetics, pet food, household goods and imported products are recognised too.

*[Version française plus bas.](#français)*

## Sources

| Order | Source | Covers | Key | Limits |
|---|---|---|---|---|
| 1 | [Open Food Facts](https://world.openfoodfacts.org) | food | — | fair use |
| 2 | [Open Beauty Facts](https://world.openbeautyfacts.org) | cosmetics, hygiene | — | fair use |
| 3 | [Open Pet Food Facts](https://world.openpetfoodfacts.org) | pet food | — | fair use |
| 4 | [Open Products Facts](https://world.openproductsfacts.org) | household & other products | — | fair use |
| 5 | [Open EAN / GTIN Database](https://opengtindb.org) | general (mostly Germany) | `opengtindb_queryid` (on request) | see provider |
| 6 | [UPCDatabase.org](https://upcdatabase.org) | general | `upcdatabase_key` (free sign-up) | see provider |
| 7 | [USDA FoodData Central](https://fdc.nal.usda.gov) — Branded Foods | US products | `usda_fdc_key` ([api.data.gov](https://api.data.gov/signup)) | 1,000 req/hour |
| 8 | [UPCitemdb](https://www.upcitemdb.com) (trial endpoint) | general | — | 100 req/day, 6/min |

Sources 1–4 are queried **in parallel**; if none finds the product, sources 5–8 are queried **one after the other** and the search stops at the first hit (to save quotas). Sources without a configured key are skipped. Each source gets 3 s to connect and 4 s in total; the whole lookup is capped at 12 s. A failing, slow or rate-limited source is skipped — the plugin never throws because of a source.

Food composition tables (Ciqual, CoFID, BLS, …) have no barcodes and cannot identify a scanned product, so they are not used here.

## What gets filled in

- **Name** — localised to Grocy's language when the source provides it, followed by the brand in parentheses when the name doesn't already contain it (Grocy refuses two products with the same name).
- **Picture** — `https://` URLs only; Grocy downloads it.
- **Description** — `Source : <database>`, so you know where the data came from.
- Location and quantity unit — your Grocy product presets, otherwise the first ones (same as the bundled Open Food Facts plugin).

## Installation

Requires Grocy 4.7 or later (PHP 8.5).

1. Copy `MultiSourceBarcodeLookupPlugin.php` into `<GROCY_DATAPATH>/plugins/` (usually `data/plugins/`). Recommended: owned by root, readable by the PHP-FPM group (`root:<php group> 0640`).
2. In `<GROCY_DATAPATH>/config.php`:
   ```php
   Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'MultiSourceBarcodeLookupPlugin');
   ```
3. Optional — API keys: copy `lookup-keys.example.json` to `<GROCY_DATAPATH>/lookup-keys.json`, fill in the keys you have, and make it readable by PHP only (`0640`). Keys are never logged.

To roll back, set the plugin back to `OpenFoodFactsBarcodeLookupPlugin`.

Note: the external lookup runs when you scan in Grocy's web UI (e.g. Safari on iPhone). The *Grocy Mobile* iOS app only searches barcodes already stored in Grocy.

## Tests

The tests run against the real Grocy 4.7.1 code (base plugin class and validation) with simulated HTTP responses:

```bash
tests/fetch-grocy.sh   # downloads Grocy 4.7.1 (SHA-256 checked) into .cache/
php tests/run.php
```

## Licence

MIT. Data comes from the respective providers under their own terms (Open Food Facts databases: ODbL). Please respect each provider's quotas.

---

## Français

Plugin de recherche de codes-barres pour [Grocy](https://grocy.info) qui interroge **8 bases de produits gratuites** au lieu du seul Open Food Facts : cosmétiques, nourriture pour animaux, produits ménagers et produits importés sont aussi reconnus.

- **Ordre** : les 4 bases Open … Facts en parallèle, puis Open EAN / GTIN Database, UPCDatabase.org, USDA FoodData Central et UPCitemdb l'une après l'autre ; arrêt au premier résultat. Une source sans clé est sautée.
- **Délais** : 3 s de connexion, 4 s par source, 12 s au total ; une source en panne, lente ou à court de quota est ignorée.
- **Remplit** : le nom dans la langue de Grocy (avec la marque entre parenthèses si absente du nom), la photo (`https://`), et la description « Source : … ».
- **Installation** : copier le fichier dans `<GROCY_DATAPATH>/plugins/`, puis `Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'MultiSourceBarcodeLookupPlugin');` dans `config.php`. Clés facultatives dans `<GROCY_DATAPATH>/lookup-keys.json` (modèle : `lookup-keys.example.json`), jamais journalisées.
- **Tests** : `tests/fetch-grocy.sh && php tests/run.php`.
- Les tables de composition (Ciqual, etc.) n'ont pas de code-barres et ne sont pas utilisées. L'app iOS Grocy Mobile n'utilise pas la recherche externe : scanner depuis l'interface web.
