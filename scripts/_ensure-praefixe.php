<?php
declare(strict_types=1);
require 'C:/devel/wordpress/wp-load.php';

use WTT\Case_Data;
use WTT\Taxonomy;

Case_Data::ensure_praefixe_catalog( Taxonomy::FS );
echo "ensure_praefixe_catalog done\n";

require __DIR__ . '/_dump-praefixe-clean.php';
