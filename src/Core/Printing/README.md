# `Taxmod\Core\Printing` — labels and printouts

What lives here: everything a printout needs that is not WordPress — the QR encoder, a small PDF
writer, the label formats and the label layout. Output is PDF bytes; who prints them (the browser
today, a print service in the home network later) is the boundary's business.

Must not depend on: WordPress, the database, or any stored model. A label takes a code and two
lines of text, so it works whatever a place or box turns out to be in the storage model.

Concept: [D-1032](../../../docs/NewConcept/90-decision-log.md) (labels with QR, prefixes O/M/E/K),
[D-1096](../../../docs/NewConcept/90-decision-log.md) (every printout has a test print).
