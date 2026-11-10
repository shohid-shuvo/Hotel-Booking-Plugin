# Rule 00: No guessing. Verify or ask. (ALWAYS ON)

## 0. Core principle

A wrong answer is worse than a question. If you are not 100% sure about a fact,
you MUST stop and ask the user. Never fill a gap with a plausible guess.
Asking is the correct behaviour here, not a failure.

## 1. Sources of truth (highest to lowest)

1. `docs/api/hotel-all-Api-End-point.json`: the OpenAPI spec. It defines what the API does TODAY.
2. `.agents/skills/arc-hotel-ecosystem/SKILL.md`: Part A = implemented. Part B = NOT implemented (never code against it). Part C = WordPress rules.
3. `docs/project_master_architecture_rules.md`: project architecture.
4. The user's messages in this conversation.

Everything else (your training memory, blog posts, "typical" hotel APIs) is NOT a source.
If two sources conflict: do not pick one silently. Report the conflict and ask.
For API facts the OpenAPI JSON wins, but you must still tell the user which document needs fixing.
If any of these files is missing from the workspace, stop and tell the user. Do not continue from memory.

## 2. Never invent any of the following

- API endpoints, HTTP methods, query/body fields, headers, auth schemes, error codes
- Enum labels (the JSON only says `1..6`; names like "bKash" are inferred, treat as unconfirmed)
- WordPress / WooCommerce hooks, function names, function signatures, argument order
- Plugin options, meta keys or database columns that are not already in this repo
- File paths, constants or class names you have not seen with your own tools in this workspace

If it is not in a source of truth or in code you have read in this session, it does not exist yet.

## 3. Evidence rule for any API-related code

Before writing code that calls the API, produce an "API evidence" list:
for every endpoint and field you will use, give the method, path, field name and where you found it.
Read the JSON with `jq` or `grep`. Do NOT load the whole file into context and do NOT rely on memory.
Example: `GET /api/v1/public-booking/available-rooms`, params: checkIn, checkOut, roomTypeId, adults, companyCode (JSON path: paths["/api/v1/public-booking/available-rooms"].get.parameters).
If any item has no evidence, stop and ask.

## 4. Evidence rule for WordPress / WooCommerce code

- Before using a hook or function, confirm it exists: grep the installed plugin/core source in this workspace, or read official docs.
- If you cannot verify the exact name and signature, ask. Do not write "this should work".
- Do not use deprecated functions.

## 5. STOP and ask when any of these is true

1. The request can be read in more than one way.
2. A needed field, endpoint, key, credential, file or setting is not in the sources.
3. Two sources disagree.
4. The task needs a Part B feature (see SKILL.md). Tell the user it is not implemented, then offer the interim workaround from SKILL.md C5 and ask which to use.
5. You are about to touch something the user did not mention (extra files, refactors, deletions, config, database).
6. You cannot run or test something and would have to claim it works without proof.
7. A choice affects money, bookings, payments, security or customer data.
8. You catch yourself writing "probably", "should", "I assume", "typically", "most likely".

## 6. How to ask

- Stop only the unclear part. Continue with clearly safe parts if they do not depend on the answer.
- Ask at most 3 questions in one message. Number them.
- For each question: say what you do not know, why it matters, and offer 2-3 options with your recommendation marked. Never decide on the user's behalf.
- Then wait. Do not continue the blocked part until the user answers.

## 7. Label every factual claim

- VERIFIED (source: file / jsonpath / command output)
- INFERRED (what it is based on; needs user confirmation)
- UNKNOWN (needs an answer)

Never present INFERRED or UNKNOWN as VERIFIED.

## 8. Verify before saying "done"

- Run real checks that exist in the workspace: `php -l` on every changed PHP file, phpcs/phpstan if configured, tests if present.
- Run a forbidden-term search: `hb_room`, `WP Hotel Booking`, `wp_hotel_booking` must return nothing in project code.
- Re-check every API field you used against the JSON.
- Never claim a test passed, a page loads or an API call worked unless you ran it and saw the output. If you could not run it, write: NOT VERIFIED: <reason>.

## 9. Scope discipline

- Change only what was asked. No drive-by refactors, renames or formatting sweeps.
- Never delete files, drop data or rewrite git history without explicit approval.
- Never put secrets (API keys, tokens, passwords) into code, logs, commits or chat. Use the constants/config the project already uses.
- For any change touching 3 or more files, show the plan first and wait for approval.

## 10. Final report format (always)

1. DONE: what changed (file list)
2. VERIFIED BY: exact commands/checks and their results
3. NOT VERIFIED: what you could not check, and why
4. ASSUMPTIONS: must be "none". If not none, you should have asked instead.
5. OPEN QUESTIONS: numbered list, or "none"
