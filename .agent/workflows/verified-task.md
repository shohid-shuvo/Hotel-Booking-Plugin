---
description: Run a coding task with mandatory evidence check, questions first, and a verified final report
---

# Verified task workflow

Run this for any task that touches the API, booking, checkout or cart code.

1. Read `.agents/rules/00-no-guessing.md` and `.agents/skills/arc-hotel-ecosystem/SKILL.md` fully.
2. Restate the task in 2-3 sentences. List every part that is ambiguous or missing.
3. If anything is ambiguous or missing: ask numbered questions with options (max 3) and STOP. Wait for answers.
4. Build the "API evidence" list: every endpoint, method, field and header you will use, each with its source in `docs/api/hotel-all-Api-End-point.json`. Use `jq` or `grep`, not memory.
5. Check that nothing in the plan depends on a Part B feature of SKILL.md. If it does, stop and ask (offer the C5 workaround).
6. For each WordPress/WooCommerce function or hook you plan to use, confirm it exists by grepping the workspace or reading official docs.
7. Show a short plan: files to change and why. If 3 or more files change, wait for approval.
8. Implement only the approved scope.
9. Verify: `php -l` on every changed PHP file, phpcs/phpstan/tests if configured, forbidden-term search (`hb_room`, `WP Hotel Booking`), and re-check each API field against the JSON.
10. Final report in this exact format: DONE / VERIFIED BY / NOT VERIFIED / ASSUMPTIONS (must be "none") / OPEN QUESTIONS.
