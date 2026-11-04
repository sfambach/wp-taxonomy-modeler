# `Taxmod\Core\Printing` — labels and printouts

What lives here: everything a printout needs that is not WordPress — the QR encoder, a small PDF
writer, the label formats, the label layout, and the rules of a print job's status. Output is PDF bytes; who prints them (the browser,
or the print service at home in `tools/print-agent`) is the boundary's business.

Must not depend on: WordPress, the database, or any stored model. A label takes a code and two
lines of text, so it works whatever a place or box turns out to be in the storage model.

Concept: [D-1032](../../../docs/NewConcept/90-decision-log.md) (labels with QR, prefixes O/M/E/K),
[D-1096](../../../docs/NewConcept/90-decision-log.md) (every printout has a test print),
[D-1113](../../../docs/NewConcept/90-decision-log.md) (the print service, stage 2).

Since [D-1153](../../../docs/NewConcept/90-decision-log.md) a label can also be drawn from a **layout written as JSON** (`LabelLayout`, `LabelElement`) filled from a record's values (`LabelDocument::renderLayout`); `LabelTemplate::shipped()` holds today's labels as such layouts, one per purpose.
