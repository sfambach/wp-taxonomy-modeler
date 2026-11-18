# `Taxmod\Core\Printing` — what the modeller keeps of printing

What lives here: the rules of a print job's status (`PrintJobStatus`), the print service's token (`PrintServiceToken`), the label
code prefixes (`LabelPrefix`) and what a scanner hands in (`ScannedCode`). Labels themselves — formats, layouts, the QR encoder and
the PDF writer — are drawn by the labels module (wp_tax_label) only ([D-1296](../../../docs/NewConcept/90-decision-log.md),
[D-1439](../../../docs/NewConcept/90-decision-log.md)); the modeller takes its finished PDF as a print job ([D-1294](../../../docs/NewConcept/90-decision-log.md)).

Must not depend on: WordPress, the database, or any stored model.

Concept: [D-1032](../../../docs/NewConcept/90-decision-log.md) (labels with QR, prefixes O/M/E/K),
[D-1113](../../../docs/NewConcept/90-decision-log.md) (the print service, stage 2), [D-1292](../../../docs/NewConcept/90-decision-log.md)
(what stays in the modeller).
