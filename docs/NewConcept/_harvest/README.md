# Harvest sheets

Working area for pulling content out of [`../../legacy/`](../../legacy/README.md).

**Not started yet.** Current priority is to write the concept from the owner's own statements
first ([D-006](../90-decision-log.md)). Legacy harvesting happens afterwards, as a
cross-check — so that the old model cannot steer the new one by simply being written down
already.

## How a sheet works

One sheet per topic, named `<nn>-<topic>.md` to match the target document. Each row is one
candidate:

| Source | Summary | Recommendation | Reason | Owner |
|---|---|---|---|---|
| `legacy/plans/x.md:120` | one or two sentences | take / rework / drop | why | ☐ |

- **take** — moves over essentially unchanged.
- **rework** — the idea survives, the wording or shape does not.
- **drop** — with the reason recorded, so it does not come back a third time.

The owner ticks or overrules. Only then is the result written into the numbered document, and
every decision gets an entry in [the decision log](../90-decision-log.md).

A finished sheet stays here as the record of what was considered and rejected.
