---
paths:
  - "resources/js/**"
  - "resources/css/**"
---

# Frontend

## Theme

- Read `docs/frontend-and-design.md` for the canonical visual, shell and reporting contracts before UI work.
- The Tailwind theme is generated from the unchanged Nocturne base plus reviewed BitFlag overrides in
  `design/bitflag/` (`npm run tokens`). Use theme classes (`bg-surface`, `text-text`,
  `border-divider`). Never write raw hex colors or Tailwind palette colors; lint blocks them.
- Light is the initial theme; Light, Dark and System are offered in the top bar. Preserve saved preferences and set
  `data-theme` before first paint. Every new component must work in both themes.
- Inter for headings, body and dashboard figures (tabular numbers where needed); Azeret Mono for code, keys and IDs.
  Self-host fonts. Do not make every count or shell revision label monospace.
- Icons come from `@phosphor-icons/vue`: 16px for standard controls and sidebar navigation; component-specific
  shell sizes follow the design contract. Icon size is not the interactive hit-area size.

## Components

- Build on `components/ui` (Reka UI primitives). Each component has typed props, `data-test` hooks and a gallery entry
  at `/_dev/ui`.
- Primary buttons are filled violet; secondary buttons are bordered. Cards/tables are flat with explicit borders;
  overlays own elevation. Use the documented component dimensions and radii, not an inherited base-only scale.
- Destructive actions confirm in a dialog that names the object. Type-to-confirm keeps the button disabled until
  the typed key matches, regardless of the action's color.
- Semantic success, warning and danger colors are allowed throughout. Use named tokens, labels/icons and contrast
  checks in both themes. A trend's direction alone does not determine whether it is good or bad.
- Toasts appear bottom right for 4 seconds and name the object and environment.
- Menus render in a portal and are never clipped by a table.

## Data and state

- Use the 256px desktop sidebar, documented navigation groups, top-bar theme control and name/email footer.
  Hide empty groups and unregistered or inaccessible destinations; preserve the responsive drawer and role notices.
- Overview fixtures do not define reporting semantics. Require scope, windows, baseline, units, freshness and
  unavailable states; do not invent distribution precedence, alert thresholds or evaluation-error categories.

- Shared props contain IDs, names and booleans only. Never pass an Eloquent model to a page.
- Draft editing state lives in composables (`useFlagDraft` and similar). Dirty editors register with `useNavigationGuard`.
- Use `useCan()` for capability checks; the server rechecks everything.

## Accessibility

- Keyboard operable with a visible `:focus-visible` outline. Dialogs trap focus and restore it on close.
- Count pills and icons need an accessible label. Target is WCAG 2.2 AA.
