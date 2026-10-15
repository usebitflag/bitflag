---
paths:
  - "resources/js/**"
  - "resources/css/**"
---

# Frontend

## Theme

- The Tailwind theme is generated from Nocturne (`npm run tokens`). Use theme classes (`bg-surface`, `text-text`,
  `border-divider`). Never write raw hex colors or Tailwind palette colors; lint blocks them.
- Dark and light themes both ship. `data-theme` is set before first paint. Every new component must look right in both.
- Inter for headings and body text. IBM Plex Mono for code, keys, IDs and monospace numbers such as revisions and counts.
- Icons come from `@phosphor-icons/vue`: 16 in controls, 20 in navigation.

## Components

- Build on `components/ui` (Reka UI primitives). Each component has typed props, `data-test` hooks and a gallery entry
  at `/_dev/ui`.
- Primary buttons are outlined, never filled. Destructive actions use neutral buttons and confirm in a dialog that
  names the object. Type-to-confirm keeps the button disabled until the typed key matches.
- Color never carries meaning alone: status tags pair a tint with a label; runtime states add a dot. There is no red or green.
- Toasts appear bottom right for 4 seconds and name the object and environment.
- Menus render in a portal and are never clipped by a table.

## Data and state

- Shared props contain IDs, names and booleans only. Never pass an Eloquent model to a page.
- Draft editing state lives in composables (`useFlagDraft` and similar). Dirty editors register with `useNavigationGuard`.
- Use `useCan()` for capability checks; the server rechecks everything.

## Accessibility

- Keyboard operable with a visible `:focus-visible` outline. Dialogs trap focus and restore it on close.
- Count pills and icons need an accessible label. Target is WCAG 2.2 AA.
