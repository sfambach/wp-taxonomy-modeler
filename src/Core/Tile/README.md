# `src/Core/Tile/` — tiles and key figures

One look for every tile in the modeller and its modules, and the arithmetic of a key figure per month (D-1149).

| File | What |
|---|---|
| [`Tile.php`](Tile.php), [`TileTone.php`](TileTone.php) | one tile: label, figure, lines, bars, actions, where a click leads |
| [`TileGrid.php`](TileGrid.php) | the markup of a row of tiles, with its style once per page |
| [`MonthlySeries.php`](MonthlySeries.php) | values per month over a window ending now; months without records count 0 |

The key figure itself is an add-on function on a node: [`../Addon/MetricAddon.php`](../Addon/MetricAddon.php). Its query lives at the
boundary (`src/WordPress/Admin/Tiles.php`), because it needs the database.

**Must not depend on:** WordPress (`CD-1`). Every text arrives in the reader's words and number format.

**Concept:** [`docs/pakete/kacheln/konzept.md`](../../../docs/pakete/kacheln/konzept.md).
