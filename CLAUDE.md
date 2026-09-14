# Development Instructions

## Project Location

* When I say **"mobile"**, I mean the Flutter project located at:
  `C:\Users\Russel\OneDrive\Documents\BUYTHEWAY FOLDERS\BuyTheWay - Mobile`
* Treat this as the default mobile project directory unless I specify otherwise.

## Code Quality & Performance

* Write **clean, maintainable, production-ready code**.
* Prioritize both **actual performance** and **perceived performance**.
* Avoid unnecessary rebuilds, API calls, database queries, network requests, animations, and expensive operations.
* Prefer efficient data handling, lazy loading, pagination, caching, debouncing, and selective updates when appropriate.
* Avoid premature optimization when it makes the code significantly harder to maintain.
* Reuse existing components, utilities, services, and logic instead of duplicating them.
* Keep widgets/components reasonably small and focused.
* Do not introduce unnecessary dependencies.
* Avoid over-engineering simple features.
* Consider performance implications before implementing a solution.

## Perceived Performance

Always consider how fast the application **feels**, not only its measured speed.

Use appropriate:

* Skeleton loaders
* Progressive loading
* Optimistic UI updates when safe
* Immediate visual feedback
* Lazy loading
* Placeholder content
* Smooth transitions
* Empty states
* Error states
* Retry actions

Never use loading animations simply to hide slow code. Fix the underlying performance issue when possible.

## UI/UX

Always act as a **professional UI/UX designer** in addition to being a developer.

When modifying or creating UI:

* Prioritize clarity, hierarchy, readability, consistency, and usability.
* Create a modern, polished, production-quality interface.
* Use appropriate icons throughout the interface.
* Prefer recognizable icons over unnecessary text when the meaning is obvious.
* Use consistent iconography, sizing, alignment, and spacing.
* Make touch targets comfortable for mobile users.
* Group related information logically.
* Remove redundant or unnecessary UI elements.
* Avoid clutter and excessive cards, borders, colors, and decorative elements.
* Use proper loading, empty, error, success, and confirmation states.
* Design for different screen sizes and orientations where appropriate.
* Consider accessibility, contrast, text readability, and touch usability.

## Redesign Permission

Do not assume the existing UI must be preserved.

If the current design or information architecture is poor, you may:

* Redesign entire sections.
* Reorganize information.
* Combine redundant components.
* Remove unnecessary elements.
* Introduce tabs, modals, bottom sheets, dialogs, or sub-pages.
* Change navigation patterns.
* Simplify complicated workflows.

Do not merely change colors, padding, or fonts when a larger redesign would provide a substantially better UX.

## Existing Functionality

* Preserve existing business logic and working functionality unless a change is necessary.
* Before changing existing behavior, understand how the feature currently works.
* Avoid breaking unrelated features.
* Check dependencies between components before removing or modifying code.
* Do not rewrite working code unnecessarily.

## Code Changes

Before implementing:

1. Inspect the relevant existing code.
2. Understand the current architecture and data flow.
3. Identify the simplest appropriate solution.
4. Consider performance and UX implications.
5. Implement only the necessary changes.

When modifying code:

* Prefer targeted changes over unnecessary rewrites.
* Follow the project's existing architecture and conventions.
* Keep naming clear and consistent.
* Remove dead or redundant code when encountered.
* Do not create duplicate implementations of existing functionality.

## Token Efficiency

Optimize responses and development workflow for **minimal unnecessary token usage**.

* Do not explain obvious code line-by-line.
* Do not repeat my requirements back to me.
* Do not provide lengthy summaries unless requested.
* Keep progress updates concise.
* Avoid showing large unchanged sections of code.
* When modifying a file, focus on the relevant changes.
* Do not generate multiple alternative implementations unless there is a meaningful tradeoff.
* Do not ask for confirmation for routine, low-risk implementation decisions.
* Make reasonable decisions independently.
* Only ask questions when missing information would materially affect the implementation.
* Prefer inspecting the codebase over asking me for information that can be determined from the project.
* Do not restate information already available in the conversation.

## Verification

After making changes:

* Check for syntax, compile, type, and import errors where possible.
* Verify that changed references still exist.
* Check for obvious runtime issues.
* Review the implementation for unnecessary rebuilds, requests, or duplicated logic.
* Ensure the UI remains responsive and usable.
* If you cannot run a required check, state that briefly instead of pretending it was verified.

## Communication

Keep responses concise and action-oriented.

Default format:

* **What changed:** 1–3 short bullets.
* **Issues:** Only mention problems that require attention.
* **Next step:** Only if necessary.

Do not provide a lengthy explanation unless I explicitly ask for one.

## Performance & Loading Speed (only perform this when i ask you to optimize performance)

Always prioritize fast perceived and actual loading performance without sacrificing existing functionality or UI.

Database

- Avoid N+1 queries. Use eager loading ("with()") when relationships are needed.
- Do not fetch unnecessary columns or records.
- Always paginate large datasets such as products, orders, messages, deliveries, and users.
- Add appropriate database indexes for frequently queried, filtered, sorted, and foreign-key columns.
- Avoid duplicate database queries within the same request.
- Prefer efficient Eloquent/query-builder queries over unnecessary loops containing database queries.
- Do not use raw SQL unless it provides a clear performance or functionality benefit.
- When modifying database-related code, check for potential performance regressions.

API / Backend

- Avoid unnecessary API requests.
- Do not make sequential requests when independent requests can safely run in parallel.
- Return only the data required by the frontend.
- Avoid loading large datasets when only a summary or small subset is needed.
- Cache data that is expensive to calculate and does not change frequently.
- Keep expensive processing out of normal page/request loading whenever possible.

Frontend

- Lazy-load images and non-critical components.
- Use optimized image sizes rather than loading full-resolution images for thumbnails/cards.
- Prefer WebP/AVIF where supported.
- Always provide explicit image dimensions to reduce layout shifting.
- Lazy-load images below the initial viewport.
- Avoid unnecessary Vue re-renders and reactive state.
- Do not repeatedly fetch the same data if it can be reused from existing state.
- Debounce search inputs and other frequently triggered API requests.
- Use pagination or infinite scrolling for large lists.
- Avoid loading entire datasets into the browser just to display a small portion.

Images

- Never load original/full-resolution product images when a smaller version is sufficient.
- Product listings should use thumbnails.
- Product detail pages may use larger optimized images.
- Use placeholders while images are loading.
- Do not block the entire page while non-critical images load.

General Rule

Before implementing a feature, consider its impact on:

1. Number of database queries
2. Amount of data transferred
3. Number and size of images loaded
4. Number of API requests
5. Frontend rendering/re-rendering
6. Initial page load time

When fixing or improving a feature, preserve existing functionality and UI unless explicitly asked to change them.

Do not perform unnecessary large-scale refactors solely for optimization. Prefer targeted, measurable improvements.
### Performance Investigation
- Before making performance-related changes, identify the actual bottleneck.
- Check browser Network requests, API response times, database query count, query duration, and image sizes when relevant.
- Do not assume PostgreSQL/MySQL, Vue, Laravel, or the network is the bottleneck without evidence.
- Fix the highest-impact bottleneck first.
- After optimization, verify that functionality remains unchanged.