<?php declare(strict_types=1);

/**
 * Beispieldaten, die ein Wächter sich selbst anlegt — über dieselbe Tür wie jedes Werkzeug, in der Klammer, die sie zurückdreht.
 *
 * ⚠️ *Grenzlauf 2026-11-17 ([D-1427](../../../docs/NewConcept/90-decision-log.md)): neun Wächter suchten ihre Gestalt im Bestand des
 * Eigentümers und meldeten auf jeder anderen Datenbank «der Wächter prüft nichts». Sie suchen weiter zuerst dort; findet sich nichts,
 * legen sie die Gestalt hier an und prüfen sie. Alles mit dem Vorsatz `__`, und `lib/no-write.php` nimmt es am Ende zurück.*
 *
 * @see scripts/dev/lib/no-write.php
 */

/**
 * Die Änderungen über `taxmod/apply` als Administrator; die Antwort `refs` (ref => id).
 *
 * @param list<array<string, mixed>> $changes
 * @return array<string, int>
 */
function taxmodBeispiel(array $changes): array
{
    $vorher = get_current_user_id();
    $admin  = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

    wp_set_current_user((int) ($admin[0] ?? 1));

    $ability = wp_get_ability('taxmod/apply');
    $antwort = $ability === null ? new WP_Error('taxmod_beispiel', 'taxmod/apply ist nicht registriert') : $ability->execute(['changes' => $changes]);

    wp_set_current_user($vorher);

    if (is_wp_error($antwort)) {
        throw new RuntimeException('Beispieldaten: ' . $antwort->get_error_message());
    }

    return array_map('intval', (array) ($antwort['refs'] ?? []));
}

/** Die Id eines Knotens aus einem Ast der Saat, an seinem Namen in der Standardsprache — für Typen wie «Text», die jede Installation hat. */
function taxmodSaatKnoten(\Taxmod\Core\Model\Branch $ast, string $name): int
{
    global $wpdb;

    $wurzel = (int) get_option('taxmod_branch_' . str_replace('-', '_', $ast->value) . '_id', 0);
    $id     = (int) $wpdb->get_var($wpdb->prepare(
        'WITH RECURSIVE unten(id) AS (SELECT %d UNION ALL SELECT n.id FROM ' . \Taxmod\WordPress\Persistence\Schema::table('nodes') . ' n JOIN unten ON n.parent_node_id = unten.id)
         SELECT v.id FROM ' . \Taxmod\WordPress\Persistence\Schema::table('nodes_named') . ' v JOIN unten ON unten.id = v.id WHERE v.name = %s LIMIT 1',
        $wurzel,
        $name
    ));

    if ($id === 0) {
        throw new RuntimeException("Beispieldaten: kein «{$name}» unter {$ast->value}.");
    }

    return $id;
}
