<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\OptionIdentityAllocator;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

/**
 * The boundary. It wires WordPress to the core and decides nothing (D-170).
 *
 * ```mermaid
 * flowchart LR
 *   H["hooks · admin screens"] -->|call inward| C["Taxmod\Core"]
 *   C -->|declares what it needs| I["repository interfaces"]
 *   B["this package fulfils them"] --> I
 * ```
 *
 * Every arrow points inward: WordPress is not underneath the core but around it, which is what
 * lets a second boundary be placed beside this one later without the core noticing.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Plugin
{
    public const VERSION     = '0.0.1';
    public const TEXT_DOMAIN = 'taxmod';

    /** What a person must be able to do before they may shape the model. */
    public const CAPABILITY = 'manage_options';

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): void
    {
        $plugin = new self($file);

        register_activation_hook($file, $plugin->activate(...));

        add_action('admin_menu', $plugin->registerMenu(...));
        add_action('admin_post_taxmod_node', $plugin->handleNodeAction(...));
    }

    public function activate(): void
    {
        Schema::install();
        update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

        $this->frameworkNodes()->seed();
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Taxonomy Modeller', 'taxmod'),
            __('Taxonomy Modeller', 'taxmod'),
            self::CAPABILITY,
            'taxmod',
            fn () => print $this->screen()->render(),
            'dashicons-networking',
            30
        );
    }

    public function handleNodeAction(): void
    {
        $this->screen()->handlePost();
    }

    public function editor(): ModelEditor
    {
        $nodes = new WpdbNodeRepository();

        return new ModelEditor(
            $nodes,
            new OptionIdentityAllocator(),
            $this->frameworkNodes(),
            new WpdbChangelog(new SystemClock())
        );
    }

    private function frameworkNodes(): SeededFrameworkNodes
    {
        return new SeededFrameworkNodes(
            new WpdbNodeRepository(),
            new OptionIdentityAllocator(),
            new WpdbChangelog(new SystemClock())
        );
    }

    private function screen(): NodesScreen
    {
        return new NodesScreen($this->editor(), $this->frameworkNodes());
    }
}
