<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Which surface is asking — a **circumstance**, not a purpose (R15).
 *
 * ⚠️ **An option inside one renderer, never a renderer of its own.** Keeping this off the
 * registry key is what stops the renderer count from multiplying: three variants × three levels
 * × two edit modes would be eighteen classes instead of three.
 *
 * The three have different jobs (D-253): the admin says what a thing **is**, the block says what
 * this page **shows**, and the front end has nothing of its own — it draws what the block names.
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum Level: string
{
    /** What a thing is — attributes, types, which renderers, defaults. Stored in our tables. */
    case Admin = 'admin';

    /** What this page shows — which node, which record, which fields. Stored in the post. */
    case Block = 'block';

    /** Nothing of its own; it draws what the block names. */
    case FrontEnd = 'front-end';

    /**
     * Wie eine **Einstellung** des Modells gezeichnet wird.
     *
     * ⚠️ **Seine Entscheidung** ([D-547](../../../docs/NewConcept/90-decision-log.md)), und er hat sie
     * gegen meinen Widerspruch getroffen: *«und da du dich wehrst, entscheide ich jetzt: es gibt eine
     * dritte Form neben Admin und Show, machen wir jetzt Settings. Eine dritte Ansicht in der Preview —
     * im Grunde brauche ich sie nur im Settings-Ast, aber wir können sie einfach überall anzeigen,
     * vielleicht haben wir ja irgendwann benutzerdefinierte Settings.»*
     *
     * ⚠️ **Was mich widerlegt hat, war seine Messung an der Seite, nicht ein Argument.** *Ich hatte die
     * Einstellungen in die Admin-Seite gelegt, weil «Admin» doch das Modell zeige — und auf `Adresse`
     * standen sie danach **zwischen** den Feldern: Street, Display Option, No., validator, Post Code …
     * **Zwei Dinge in einer Liste, die nichts miteinander zu tun haben.** Er: «man sieht den Render in
     * Settings, aber auch in der Preview vom Modell, und das darf nicht sein.»*
     *
     * ⚠️ *«Überall anzeigen, nicht nur im Settings-Ast» ist Absicht und keine Bequemlichkeit: solange
     * die Ansicht nur dort erscheint, wo heute Einstellungen liegen, merkt niemand, wenn morgen woanders
     * welche entstehen.*
     */
    case Settings = 'settings';
}
