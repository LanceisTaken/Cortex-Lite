---
name: close
description: Use this to close out a Claude Code working session — review what happened, persist decisions/learnings/tasks to open-brain and local memory files, handle git housekeeping (status, diff, commit), and log the session to SESSION_LOG.md. Trigger this whenever the user runs `/close`, or says things like "close out this session", "let's wrap up", "end of session", "save my progress before I go", "log this session", or otherwise signals they're done working and want their context captured before it disappears. Always run the full three-phase workflow rather than just one piece of it, unless the user explicitly asks for a partial close.
---

# Close

A working session accumulates a lot that's only in the conversation: decisions that were made, things that didn't work, tasks that got opened but not finished, references that got pulled in. Once the session ends, all of that is gone unless it's written down somewhere durable. This skill's job is to externalize it — to open-brain, to memory files, to git, to a session log — before the session closes, so the next session (yours or someone else's) doesn't have to reconstruct it from scratch.

Run all three phases in order. Don't skip ahead to Phase 3 just to print the summary — the summary is only useful if Phases 1 and 2 actually happened.

## Phase 1 — Retrospective

### 1. Scan the conversation for what's worth keeping

Read back over the session and pull out anything that falls into these buckets:

- **Decisions** — a choice was made between alternatives, especially ones with a reason attached ("we went with X because Y")
- **Learnings / inefficiencies** — something that didn't work, a gotcha, a correction the user made to your approach, a faster way to do something you stumbled onto
- **Open tasks** — things that were identified but not finished, follow-ups, TODOs
- **References** — external docs, URLs, files, or other artifacts that came up and are worth being able to find again

Be selective. The point is signal, not a transcript — a session with one good architectural decision and nothing else should produce one captured thought, not five mediocre ones. If a session was pure execution with no decisions, surprises, or open threads, say so and move on; an empty Phase 1 is a valid outcome.

### 2. Determine the namespace

Call `open-brain.browse_recent` for the last 30 days and look for a namespace that matches this session — same project, same directory, same topic thread. Match on substance (what the work was actually about) rather than exact string matching, since naming may drift slightly between sessions.

- If you find a clear match, use that namespace.
- If nothing matches, this is a new thread — pick a short, descriptive namespace (project or topic name) and note that you're creating it.
- If it's ambiguous between two candidates, ask the user rather than guessing — a thought filed under the wrong namespace is hard to find later.

### 3. Capture thoughts

For each relevant point from step 1, call `open-brain.capture_thought` with the appropriate type:

| Type | Use for |
|---|---|
| `decision` | A choice that was made and why |
| `insight` | A learning, gotcha, or inefficiency discovered |
| `task` | Something open or unfinished |
| `reference` | An external doc, URL, or artifact worth re-finding |
| `general` | Anything relevant that doesn't fit the above |

Keep a running count of how many thoughts you capture — you'll need it for the closing summary.

### 4. Update memory files

Memory files live in `~/.claude/projects/.../memory/` (resolve `...` to the current project's path). These are the longer-lived, human-readable counterpart to the open-brain thoughts — route content by what kind of memory it is:

- User preferences (how the user likes things done, standing instructions) → `user_*.md`
- Workflow feedback (what worked/didn't in how you approached the session) → `feedback_*.md`
- Project state and deadlines → `project_*.md`
- External references → `reference_*.md`

Append to an existing file of the right kind if one already exists for this topic rather than creating a near-duplicate. If you create a *new* memory file, add a one-line entry for it to `MEMORY.md` (in the same `memory/` directory) so it's discoverable later — a memory file nobody links to is as good as lost.

Keep a count of memory files created or updated for the closing summary.

## Phase 2 — Housekeeping

### 1. Check for a git repo

Run `git rev-parse --show-toplevel`. If this fails, there's no repo in scope — skip the git-specific steps below (status, diff, commit) and note in the closing summary that this step was skipped, rather than silently dropping it.

### 2. Show changes and propose a commit

If there is a repo:

- Run `git status --short` and `git diff --stat HEAD` and show the user what's changed.
- Generate a commit message in the imperative mood, conventional-commit style where it fits the repo's existing convention (e.g. `feat: add /close skill`, `fix: handle missing git repo in close workflow`).
- Show the proposed message and ask before committing. Never commit without explicit confirmation — a session-close workflow that auto-commits is exactly the kind of thing that quietly does the wrong thing once and erodes trust.

Keep a count of commits made (0 if the user declines or there's nothing to commit) for the closing summary.

### 3. Write SESSION_LOG.md

Prepend (don't append — most recent first) a new entry to `SESSION_LOG.md` at the git root. If there's no git root (Phase 2 step 1 found none), fall back to `~/SESSION_LOG.md`.

Entry format:

```markdown
## [YYYY-MM-DD] <Title>

<1–2 sentence summary of what happened this session>

→ <pointer to the main artifact: a file path, PR link, or skill/feature name>

---
```

The summary should be specific enough that reading just this entry (without the rest of the file) tells you what the session accomplished. The pointer should be something concrete and findable — not "made progress" but the actual file, PR, or thing that was built or changed.

## Phase 3 — Close

### 1. Print a rename suggestion

Output a line in this exact format, meant to be copy-pasted as the session/conversation name:

```
[YYYY-MM-DD] <project-or-topic> - <what-was-done>
```

### 2. Print the closing summary

Output one line summarizing the session's counters, in this exact format:

```
<N> thoughts → open-brain · <N> memory updates · <N> commits · SESSION_LOG updated
```

Use the counts accumulated through Phases 1 and 2. If SESSION_LOG.md wasn't written (e.g. truly nothing happened this session), say `SESSION_LOG skipped` instead of `updated`. If git was skipped entirely (no repo), say `no repo` instead of `<N> commits`.

## Edge cases

- **`open-brain` not available**: skip steps that depend on it, note this explicitly in the closing summary (`open-brain unavailable`) rather than silently capturing 0 thoughts, so the user knows it's a tooling gap and not an empty session.
- **Trivial session** (e.g. a single quick question, nothing built or decided): it's fine for Phase 1 to capture nothing and Phase 2 to find nothing to commit. Still run Phase 3 so the user gets a clean close — the counters will just be low or zero.
- **Memory directory doesn't exist yet**: create `~/.claude/projects/.../memory/` and a fresh `MEMORY.md` rather than skipping the step.
