---
name: web-development
description: Build, audit, and improve this project's web interfaces with strong design judgment and maintainable, low-duplication code.
---

# Web development

For web work in this project, work as an experienced web developer. First understand the relevant pages, existing design system, components, and behavior so improvements fit the product and codebase.

## Implementing code

Use an inspect, plan, implement workflow:

1. **Inspect:** Read the relevant files and trace the current behavior and existing patterns before editing.
2. **Plan:** Identify the smallest coherent change, the files it affects, and any likely impact on related pages or components.
3. **Implement:** Make the change in line with the plan, reusing established components and conventions.
4. **Review:** Inspect the resulting diff for correctness, unintended changes, and duplicated code. Use relevant project checks or a browser preview when appropriate and available.

When auditing or improving an interface, look for clear opportunities in visual hierarchy, layout, spacing, typography, responsive behavior, accessibility, and consistency. Make focused improvements that preserve the product's intent and established visual language; avoid unnecessary redesigns.

### Preserve the existing UI

Treat the current interface as the baseline to protect. Make the smallest targeted change that fulfills the request, and preserve unrelated layout, styling, content, navigation, interactions, state, accessibility, and responsive behavior. Do not replace a page or component wholesale, remove existing controls, or alter shared styles in ways that can unexpectedly change other screens. Before changing shared components or styles, trace their other uses and keep their existing behavior intact. Review the affected screen after the change and check for accidental visual or behavioral regressions at relevant viewport sizes when preview is available. Change broader UI only when the user asks for it or the requested feature requires it.

Keep implementation duplication low. Reuse existing components, styles, design tokens, and utilities where they fit. Consolidate repeated patterns when doing so makes the code easier to maintain, and avoid premature abstractions for one-off elements.

Review the rendered result at relevant viewport sizes when a browser preview is available, and follow the project's existing conventions for implementation and verification.

## Git changes

Do not commit or push changes by default. Commit and push only when the user explicitly asks for those actions.

## Route and deployment verification

Before finishing a web change, verify the affected routes against the actual schema and deployment state, especially when code adds database columns or relies on a new migration. A route must keep its existing behavior when an optional feature migration has not run: guard queries and writes that need the new columns, and clearly disable only the dependent controls until the schema is ready. Do not rely on a deploy pipeline to apply migrations unless that behavior has been confirmed.

For Laravel changes, run the relevant route and view checks and inspect the application log for errors from the affected route when available. Review the final diff and repository status before reporting completion. Do not say the work is finished while an affected page is known to return an error.
