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
| 5 | [Open EAN / GTIN Database](https://opengtindb.org) | general (mostly Germany) | `opengtindb_queryid` — private use requires a donation of at least €35, then an e-mail request ([details](https://opengtindb.org/userid.php)) | 500 req/day |
| 6 | [UPCDatabase.org](https://upcdatabase.org) | general | `upcdatabase_key` (free sign-up) | see provider |
| 7 | [USDA FoodData Central](https://fdc.nal.usda.gov) — Branded Foods | US products | `usda_fdc_key` ([api.data.gov](https://api.data.gov/signup)) | 1,000 req/hour |
| 8 | [UPCitemdb](https://www.upcitemdb.com) (trial endpoint) | general | — | 100 req/day, 6/min |

Sources 1–4 are queried **in parallel**; if none finds the product, sources 5–8 are queried **one after the other** and the search stops at the first hit (to save quotas). Sources without a configured key are skipped (so Open EAN is simply unused unless you obtain a query ID). Each request gets at most 3 s to connect and 4 s in total, never more than the time left in the 12 s budget (so a lookup ends after roughly 12 s at worst); redirects are not followed. A failing, slow, redirecting or rate-limited source is skipped — the plugin never throws because of a source.

Food composition tables (Ciqual, CoFID, BLS, …) have no barcodes and cannot identify a scanned product, so they are not used here.

## What gets filled in

- **Name** — localised to Grocy's language when the source provides it, followed by the brand in parentheses and the package size (Open … Facts) when the name doesn't already contain them, e.g. `Nutella (Ferrero) 750 g`. This *reduces* name collisions; Grocy still refuses a name that already exists, in which case create the product manually.
- **Picture** — only from the Open … Facts image servers (`https://images.open…facts.org/…`, jpg/png/webp/gif); Grocy downloads it. Pictures from the other sources are ignored, since Grocy would download arbitrary third-party URLs.
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

MIT. Data comes from the respective providers under their own terms (Open … Facts databases: ODbL; their pictures: CC BY-SA). Please respect each provider's quotas.

**Privacy** — every barcode unknown to your Grocy is sent to up to 8 third-party services (UPCitemdb is used without any key). Remove sources you don't want by not configuring their key, or fork the plugin.

---

## Français

Plugin de recherche de codes-barres pour [Grocy](https://grocy.info) qui interroge **8 bases de produits gratuites** au lieu du seul Open Food Facts : cosmétiques, nourriture pour animaux, produits ménagers et produits importés sont aussi reconnus.

- **Ordre** : les 4 bases Open … Facts en parallèle, puis Open EAN / GTIN Database, UPCDatabase.org, USDA FoodData Central et UPCitemdb l'une après l'autre ; arrêt au premier résultat. Une source sans clé est sautée.
- **Délais** : 3 s de connexion, 4 s par requête, jamais plus que le temps restant sur un budget de 12 s ; pas de redirection. Une source en panne, lente, qui redirige ou à court de quota est ignorée.
- **Remplit** : le nom dans la langue de Grocy, avec la marque entre parenthèses et la contenance si elles n'y sont pas (« Nutella (Ferrero) 750 g ») — cela *réduit* les doublons de noms, que Grocy refuse ; la photo, uniquement depuis les serveurs d'images Open … Facts ; la description « Source : … ».
- **Confidentialité** : chaque code-barres inconnu de votre Grocy est envoyé à jusqu'à 8 services tiers (UPCitemdb sans clé).
- **Installation** : copier le fichier dans `<GROCY_DATAPATH>/plugins/`, puis `Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'MultiSourceBarcodeLookupPlugin');` dans `config.php`. Clés facultatives dans `<GROCY_DATAPATH>/lookup-keys.json` (modèle : `lookup-keys.example.json`), jamais journalisées.
- **Tests** : `tests/fetch-grocy.sh && php tests/run.php`.
- Les tables de composition (Ciqual, etc.) n'ont pas de code-barres et ne sont pas utilisées. L'app iOS Grocy Mobile n'utilise pas la recherche externe : scanner depuis l'interface web.
