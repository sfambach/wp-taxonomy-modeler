<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Port\Users;

/**
 * Die eine Stelle, an der wegen `user_ref` nach WordPress gefragt wird.
 *
 * ⚠️ **Sie steht am Rand, weil sie nirgends sonst stehen darf** (`CD-1`,
 * [D-649](../../docs/NewConcept/90-decision-log.md)): *der Kern beschreibt «Benutzerverweis, Wert
 * 17» und **darf `get_userdata()` nicht rufen**. Der Rand ist der Einzige, der WordPress fragt —
 * und er reicht die Antwort herein, statt hineinzugreifen.*
 *
 * ⚠️ **Die Id fährt als Zeichenkette**, nicht als Zahl ([D-171](../../docs/NewConcept/90-decision-log.md),
 * `P4d`). *Dass WordPress ganzzahlige Ids hat, ist eine Eigenschaft dieses Randes; ein anderer hätte
 * `okta|4711`, und der Kern deutet keines von beidem.*
 *
 * ⚠️ *Der **Anzeigename** und nicht der Anmeldename: er ist das, was ein Mensch auf dem Bildschirm
 * wiedererkennt, und `AR-2` ist nicht berührt — es ist ein vom Benutzer angelegter Name, keine
 * Software-Zeichenkette.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpUsers implements Users
{
    public function signedIn(): ?string
    {
        $id = get_current_user_id();

        // ⚠️ *`0` heisst «niemand angemeldet», und das ist eine echte Antwort — WP-CLI, ein Wächter,
        // ein Besucher. Sie als Id weiterzureichen wäre ein Verweis auf einen Benutzer, den es nicht
        // gibt.*
        return $id === 0 ? null : (string) $id;
    }

    /**
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    public function namesFor(array $ids): array
    {
        $names = [];

        foreach ($ids as $id) {
            // ⚠️ *`get_userdata()` nimmt eine Zahl; die Zeichenkette ist die Form, in der der Kern sie
            // führt, und hier wird sie zurückübersetzt. **Was keine Zahl ist, kann kein WordPress-Benutzer
            // sein** — es fehlt dann, und der Renderer zeigt es als ungelöst.*
            if (! ctype_digit($id)) {
                continue;
            }

            $user = get_userdata((int) $id);

            if ($user === false || $user->display_name === '') {
                continue;
            }

            $names[$id] = (string) $user->display_name;
        }

        return $names;
    }
}
