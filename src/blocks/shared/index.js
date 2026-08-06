/**
 * Shared block bind helpers (Model table / Object view).
 *
 * Chooser modes (type specialization children):
 * - max choice depth ≤ 1 → flat list
 * - max choice depth ≥ 2 → tree
 * Taxonomy browse (deep Fallstudie tree) should pass mode="tree".
 */
export { default as ModelTreeChooser } from './model-tree-chooser';
export { default as ModelInstancePicker } from './model-instance-picker';
export {
	buildPathTree,
	expandKeysForSelection,
	maxChoiceDepth,
	resolveChooserMode,
} from './build-path-tree';
