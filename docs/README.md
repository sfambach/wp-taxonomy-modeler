# Documentation

**Current mode: re-planning.** The project restarted its concept phase on **2026-08-22**.

| Where | What | Status |
|---|---|---|
| [`NewConcept/`](NewConcept/README.md) | The concept being written now. **Single source of truth.** | active |
| [`legacy/`](legacy/README.md) | The pre-2026-08-22 planning round. **Frozen quarry, read-only.** | frozen |

## Rules

1. Only files under `NewConcept/` carry authority. Nothing is implemented from `legacy/`.
2. `legacy/` is never edited. Content moves out of it only through a reviewed
   **harvest sheet** — see [`NewConcept/_harvest/README.md`](NewConcept/_harvest/README.md).
3. No production code until the domain core is `locked`.
