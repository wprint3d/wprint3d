# WPrint 3D Design Language

## Purpose

This file is the shared visual and interaction contract for WPrint 3D. It records the design language already present in the product so frontend, plugin, startup, and other auxiliary interfaces continue to look and behave like parts of the same application.

This is a living document, not a proposal for a replacement design system. Changes should extend the current interface in place. A new visual direction, parallel component language, or wholesale layout requires an explicit redesign request.

Last visually verified: **2026-07-31**.

## Source-of-truth order

When documentation, a screenshot, and implementation disagree, use this order:

1. The current rendered application and the behavior of the affected flow.
2. Shared theme tokens in `frontend/assets/themes/light.json` and `frontend/assets/themes/dark.json`.
3. Shared React Native Paper components and WPrint 3D primitives.
4. The rules in this document.

If code has intentionally changed the design language, update this file in the same change. Screenshots are audit evidence, not a substitute for tokens or components; runtime data and device state make them age quickly.

## Product character

WPrint 3D is an operational device-control interface. Its visual character is:

- **Functional and compact:** prioritize printer state, controls, files, logs, and recovery actions over decoration.
- **Material 3 and mostly flat:** use tonal surfaces, defined outlines, restrained elevation, rounded controls, and Material state behavior.
- **Neutral with semantic color:** cool grayscale establishes hierarchy; green, amber, and red communicate state and risk.
- **Information-dense but readable:** related controls stay close together while panes and sections remain clearly bounded.
- **Consistent across form factors:** mobile reflows and changes navigation, but remains recognizably the same interface.
- **Operational in imperfect conditions:** loading, offline, empty, unavailable, and retry states are first-class views.

WPrint 3D is not a marketing site. Avoid glassmorphism, gradients, neon effects, oversized hero treatments, ornamental bento layouts, custom web fonts, emoji used as controls, or decorative motion that competes with live status.

## Canonical implementation sources

| Concern | Canonical source |
| --- | --- |
| Theme selection and Material 3 integration | `frontend/includes/Theme.js` |
| Light and dark semantic colors | `frontend/assets/themes/light.json`, `frontend/assets/themes/dark.json` |
| Branded page background | `frontend/components/Background.js`, `frontend/assets/background.png`, `frontend/assets/background.svg` |
| Main shell and responsive mode | `frontend/components/Main.js`, `frontend/components/UserLayout.js`, `frontend/components/UserMobileLayout.js` |
| Pane geometry | `frontend/components/UserPane.js`, `frontend/utils/userLayout.js` |
| Navigation | `frontend/components/NavBar.js`, `frontend/components/NavBarMenu.js` |
| Modal behavior | `frontend/utils/modalLayout.js`, `frontend/components/NavBarMenuProfileModal.js`, `frontend/components/NavBarMenuSettingsModal.js` |
| Compact actions | `frontend/components/SmallButton.js`, React Native Paper `Button`, `IconButton`, and `TouchableRipple` |
| Plugin UI token bridge | `frontend/components/PluginHostRenderer.js` |
| Standalone startup surface | `internal/startup/index.html` |

Prefer these primitives over page-local substitutes. When a missing primitive would otherwise be duplicated, add or improve a shared component first.

## Foundations

### Color

Always consume colors by semantic role. The JSON theme files remain canonical; the table below is a compact reference for the roles most often needed.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `background` | `rgb(255, 251, 255)` | `rgb(0, 0, 0)` | Application canvas and pane background |
| `onBackground` | `rgb(31, 31, 31)` | `rgb(231, 225, 229)` | Primary text on the canvas |
| `surface` | `rgb(255, 251, 255)` | `rgb(29, 27, 30)` | Navigation and component surfaces |
| `onSurface` | `rgb(30, 30, 30)` | `rgb(231, 225, 229)` | Primary surface content |
| `primary` | `rgb(79, 88, 99)` | `rgb(203, 203, 203)` | Main actions, active controls, slider tracks |
| `onPrimary` | `rgb(255, 255, 255)` | `rgb(34, 34, 34)` | Content on primary controls |
| `surfaceVariant` | `rgb(230, 230, 230)` | `rgb(63, 70, 79)` | Secondary containers and muted regions |
| `onSurfaceVariant` | `rgb(63, 70, 79)` | `rgb(204, 196, 206)` | Secondary text and metadata |
| `outline` | `rgb(142, 152, 164)` | `rgb(150, 142, 152)` | Input and prominent component outlines |
| `outlineVariant` | `rgb(205, 205, 205)` | `rgb(63, 70, 79)` | Subtle separators and card borders |
| `elevation.level1` | `rgb(252, 252, 252)` | `rgb(18, 16, 19)` | Cards, forms, and modal surfaces |
| `elevation.level4` | `rgb(214, 214, 214)` | `rgb(61, 61, 61)` | Pane borders and emphasized separation |
| `success` | `rgb(10, 153, 0)` | `rgb(10, 153, 0)` | Online, connected, completed |
| `warning` | `rgb(153, 126, 0)` | `rgb(153, 126, 0)` | Starting, degraded, needs attention |
| `error` | `rgb(186, 26, 26)` | `rgb(181, 19, 0)` | Offline, unavailable, destructive actions |

Rules:

- Do not add raw color literals in components when a semantic token exists.
- Use container/on-container pairs for badges and panels. Use explicit readable foregrounds for destructive buttons.
- Never rely on color alone. Pair status color with an icon and a short label such as “online”, “offline”, or “starting”.
- Verify foreground, border, focus, disabled, and pressed states independently in both themes.
- Standalone HTML and plugin surfaces must map back to the same semantic roles rather than inventing a new palette.

### Typography

- Use the native/system sans-serif stack supplied by React Native and React Native Paper. Do not add web fonts or CDN dependencies.
- Prefer Paper type variants (`bodyMedium`, `titleMedium`, `headlineSmall`, and related variants) over one-off font declarations.
- Normal interface copy is approximately 16 px; supporting text and metadata are generally 12–14 px.
- The application-bar title is 18 px and bold. The login title is an intentional exception at 48 px on larger screens and 32 px on small screens.
- Use weight to establish hierarchy sparingly: regular body, medium/semibold labels, bold product or section titles.
- Logs, terminal output, identifiers, and code-like values may use a monospace face. General UI copy should not.
- Allow for translated strings to expand. Do not size a control around the current English label alone.

### Spacing and density

The implementation follows a 4 px base rhythm.

- **4 px:** icon/text adjustment and tight inline spacing.
- **8 px:** standard control gap, pane-to-pane gap, compact padding.
- **12 px:** common card/pane padding and related-group gap.
- **16 px:** normal section padding, modal content padding, section separation.
- **24 px:** spacious form or high-level section padding.

`UserPane` establishes the common shell with an 8 px radius, 12 px vertical padding, and 16 px horizontal padding. Preserve the current medium-high density; do not expand routine dashboard content into oversized cards.

### Shape, borders, and elevation

- Main panes use a 1 px semantic border and an 8 px radius.
- Forms and prominent cards generally use 8–12 px radii.
- Buttons, chips, and compact state badges may use pill geometry (approximately 18–22 px or fully rounded).
- Use tonal elevation and borders before adding shadows. Shadows should be soft and functional, primarily for overlays and transient feedback.
- Do not combine unrelated flat, glass, skeuomorphic, or high-depth treatments on the same screen.

### Background

The repeated cross pattern is part of the WPrint 3D shell. `Background.js` renders the 64 × 64 PNG over the semantic background color; the SVG source expresses the same low-opacity motif.

- Use it on top-level application and standalone system canvases.
- Keep cards and panes mostly opaque so content remains readable.
- Do not repeat the motif inside cards or use it as decorative noise on controls.

### Icons

- Use React Native Paper/Material Community vector icons.
- Typical sizes are 16 px inline, 24 px for normal actions/navigation, and 48 px for empty or unavailable states.
- Keep icon style and visual weight consistent within a navigation or control group.
- Icon-only actions require an accessible label and a minimum 44 × 44 px interaction area.
- Do not use emoji as structural icons.

## Layout and responsive behavior

### Application shell

- The app bar is compact, with a minimum height of 46 px, product name on the left, live device/status widgets toward the right, then notifications and account actions.
- The desktop workspace uses two bordered panes separated by an 8 px gap. The left pane contains printer selection, status, material, camera, and files. The right pane contains Terminal, Preview, Control, Recordings, and plugin pages.
- At widths of 1600 px and above, the workspace gains larger outer gutters; at 1920 px it uses the existing 128 px horizontal gutter.
- Scrolling belongs to the pane or content region that needs it. Fixed navigation must not cover scrollable content.

### Current breakpoint contract

| Width | Expected behavior |
| --- | --- |
| `< 768 px` | Single mobile scene with bottom navigation. Home contains the former left pane. |
| `768 px` | Transition boundary; verify explicitly because the shell and left-pane width rules meet here. |
| `769–1024 px` | Two-pane compact desktop layout; left pane is 45%. |
| `1025–1440 px` | Two-pane layout; left pane is 40%. |
| `> 1440 px` | Two-pane layout; left pane is 35%. |
| `>= 1600 px` | Add 32 px horizontal workspace gutters. |
| `>= 1920 px` | Increase horizontal workspace gutters to 128 px. |

The core mobile navigation has five destinations: Home, Terminal, Preview, Control, and Recordings. Use the existing filled/outline icon treatment for active/inactive destinations. Plugin pages extend the same navigation model and use the puzzle icon; validate the result when extensions increase destination count.

Responsive design means reflowing the same hierarchy, not creating a visually unrelated mobile product. Preserve terminology, icon meaning, action priority, and semantic grouping.

### Modals

- Settings occupies 95% of the viewport on desktop and becomes full-screen below the small-laptop breakpoint.
- Settings uses horizontally scrollable icon-and-label tabs. Its content remains a bounded list/grid of settings cards rather than becoming a separate site layout.
- Profile is a centered surface up to 500 px wide and 75% high on larger screens, with sections constrained to a readable 350 px measure. It becomes full-screen on small tablets/mobile.
- Full-screen modals provide an explicit back action. Desktop overlays use a scrim strong enough to separate foreground and background.

## Component patterns

### Navigation and tabs

- Use the compact app bar, Paper tabs on desktop, and Paper bottom navigation on mobile.
- Tabs combine a Material icon and a concise text label. Keep active state visible through more than color alone when possible.
- Preserve destination order unless the underlying task flow changes.

### Panes, cards, and lists

- Use `UserPane` for primary workspace regions.
- Cards group one entity or one configuration topic. Keep actions aligned and predictable; editing is neutral/primary and deletion is red/destructive.
- Live status cards use compact chips in a corner and retain the same geometry whether online, offline, or pending.
- Lists and tables should remain dense, scannable, and scroll within their owning surface.

### Buttons and controls

- Primary actions use the semantic primary/on-primary pair and a contained Material treatment.
- Destructive actions use error semantics and include both icon and label.
- Closely related utility controls may form compact segmented groups, as in file and terminal actions.
- Movement controls are circular, symmetric, and spatially arranged; sliders expose the current value and unit.
- Disabled and loading controls remain stable in size, become visibly de-emphasized, and cannot submit twice.

### Forms

- Use outlined Paper inputs with persistent labels.
- Put helper and validation text next to the relevant field. Errors must include text and be announced accessibly, not just change an outline color.
- Group related fields within one tonal surface. Keep desktop forms bounded; allow them to fill available width on mobile.
- Preserve keyboard order, Enter/submit behavior, and visible focus.

### Feedback and operational states

- Any operation taking longer than roughly 300 ms needs visible feedback.
- Use centered spinners with short action-oriented text for local loading and the existing snackbar stack for transient global feedback.
- Empty states explain what is missing and, when possible, the next action (“No files uploaded yet. Try uploading something.”).
- Offline and unavailable states preserve the surrounding layout to avoid reflow and show a recovery path when one exists.
- Success, warning, and failure should combine semantic color, icon, and text.
- Terminal and log surfaces keep a compact monospace presentation, preserve line structure, and provide controls without obscuring content.

### Motion

- Existing screen and form entrances use subtle `FadeIn` transitions, commonly 500 ms. Reuse that established pattern for equivalent screen-level transitions.
- Keep smaller state and press transitions near 150–300 ms with native-feeling easing.
- Motion should explain a state change, not decorate idle operational data.
- Respect reduced-motion preferences and avoid layout-shifting press effects.

## Accessibility requirements

- Meet WCAG AA contrast: 4.5:1 for normal text and 3:1 for large text and meaningful UI graphics.
- Make every action keyboard reachable on web; focus order must follow visual order and focus must remain visible.
- Give icon-only controls descriptive accessibility labels. Do not depend on a tooltip as the only name.
- Use sequential heading semantics and landmarks where the web renderer permits them.
- Announce errors and important asynchronous changes with the appropriate live-region or alert semantics.
- Keep touch targets at least 44 × 44 px and separated enough to avoid accidental activation.
- Support zoom, translated strings, dynamic text, safe areas, and reduced motion without clipping or hidden actions.

## Standalone and plugin surfaces

Pages outside the main React Native Paper tree are still WPrint 3D interfaces.

- `internal/startup/index.html` must mirror the semantic palette, cross-pattern canvas, compact density, typography, border treatment, themes, and reduced-motion behavior without external dependencies.
- Plugin UI should consume the token bridge supplied by `PluginHostRenderer.js` and use the host navigation, card, field, badge, and modal patterns.
- Do not create a plugin-specific or startup-specific design system that visually competes with the host.
- If a technical constraint prevents use of a shared component, reproduce its semantic role and geometry, then document the exception.

## Visual verification matrix

Every material UI change must be compared with the current application, not reviewed in isolation.

Minimum viewport set:

- 375 × 812 — small phone, portrait.
- 812 × 375 — small phone, landscape.
- 768 px wide — layout transition boundary.
- 1024 × 768 — tablet/small laptop.
- 1440 × 900 — standard desktop.
- 1920 × 1080 — large desktop and outer-gutter behavior.

For each affected flow, verify:

- Light and dark themes.
- Loading, success, empty, offline/unavailable, and error states that apply.
- Long translated copy, long identifiers, logs, and scrollable content.
- Keyboard navigation, visible focus, touch targets, and screen-reader names.
- No document-level horizontal overflow and no content hidden behind fixed bars.
- Modal open/close behavior and restoration of focus.
- Reduced motion and enlarged text where supported.

The 2026-07-31 browser audit covered public login; authenticated main workspace at 375, 1024, and 1440 px; light and dark themes; terminal; mobile Home and Control; Profile; Settings/Printers; Settings/About; plugin loading/ready feedback; online/offline printer chips; disconnected camera; and empty file states. The reviewed viewports had no document-level horizontal overflow.

## Change workflow

Before implementing UI work:

1. Read this file and the applicable sources in the canonical-source table.
2. Run the current application and capture the affected flow plus at least one adjacent screen.
3. Identify the existing token, primitive, layout, and interaction pattern to extend.
4. Implement the smallest coherent change without replacing the surrounding visual language.
5. Capture the result at the relevant sizes and in both themes, then run the verification matrix.
6. Update shared primitives and this document if the accepted design language has intentionally changed.

A change is synchronized only when its rendered behavior, semantic tokens, shared components, responsive states, accessibility behavior, and this document agree.
