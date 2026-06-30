# Project Approach Review: Cortex Lite (v3)

## TL;DR

**v3 is materially stronger than v2** — every prior-review gap is addressed (six Docker services in Phase 0, dishonest live dashboard cut, React/Docker split documented, Makefile, AWS post-July-2025 caveat, Sanctum storage as a deliberate decision). The product pivot to a Steam-connected AI settings optimizer is the right call: it gives the LLM integration an honest role and ties the freemium tier to a recognizable model. **The new risks are concentrated in Phase 4** (the curated dataset's hour count is bigger than the time-box admits, PCGamingWiki's "30 requests/minute" cap should be verified, and PassMark CSV licensing is murkier than the plan implies) and **one design crack in Phase 5** (reverse mode breaks your own "LLM never decides settings" rule).

---

## Evidence Reviewed

- **Files inspected:** `cortex-lite-build-plan.md` (full v3), `cortex-lite-approach-review.md` (prior v2 review, full)
- **Git state:** Two commits — Setup Files + Updated Build Plan. No feature code exists.
- **External references:** Not re-run for v3; prior review verified AWS RDS/ElastiCache pricing and Reverb architecture. Several v3 claims (PCGamingWiki rate limit, PassMark dataset license, Steam `include_appinfo` behavior) are unverified and flagged below.
- **Inspection scope:** Plan document delta-reviewed against v2 review's eight recommendations + new feature surface (Steam, PassMark, PCGamingWiki, AI optimizer, freemium model).

---

## What v3 Got Right (versus v2 review)

| v2 Gap | v3 Resolution |
|---|---|
| Reverb as hidden 5th service | **Excellent.** Scoped Reverb to Phase 7 stretch only; scheduler + queue in Phase 0 instead. |
| "30-day metrics" gate had no write path | **Cut entirely.** Replaced with monthly recommendation quota — a real freemium pattern with no new write path. |
| React/Docker split undocumented | Phase 0 task says it out loud, with the interview answer pre-written. |
| `.env.example` missing | Phase 0 task with "every variable across all phases". |
| Reverb risk flag outdated | Reverb deferred entirely; no longer a risk surface. |
| AWS July-2025 account caveat | **Promoted to mandatory Phase 6 protections** — Budgets alert, no-NAT-Gateway rule, 48-hour live window. Stronger than v2 advice. |
| Makefile | Added to Phase 0. |
| Sanctum token storage | Phase 1 task explicitly asks for a decision + ARCHITECTURE.md entry. |

The dishonest-feature deletion in particular is hard to do — most plans accrete features, they don't lose them.

---

## New Gaps in v3

### Gap 1 — The curated dataset's hours don't fit Phase 4's time-box

**Math:** 20 games × 4 GPU tiers × 3 goals = 240 records. Each record needs sources (Tom's Hardware, Digital Foundry, PCGamingWiki) cited in `notes`. Realistic per-record time with cross-referencing: 3–6 minutes. That's **12–24 hours of curation alone**, on top of PassMark ingestion, PCGamingWiki client, heuristic engine, and 5–7 days total.

**Why this matters:** Phase 4's stated fallback is "skip PCGamingWiki, hand-curate the top 20." But the math says even *just* curating 20 games consumes most of the budget. If Phase 4 overruns, Phase 5 (your headline JD feature — AI integration) gets squeezed.

**Recommendation:** Halve the curated set to 10 games × 4 × 3 = 120 records for v1. Add the other 10 as "post-Phase 6 expansion." Pick the 10 to span genres and engines (Cyberpunk, CS2, Elden Ring, Valorant, BG3, Fortnite, Minecraft, GTA V, RDR2, Helldivers 2) so the demo still feels broad. The resume bullet ("curated 120 settings records for top titles across genres") reads identically.

### Gap 2 — PCGamingWiki "30 requests/minute" is unverified

**Evidence:** The plan asserts a specific 30/min cap as "per their published API policy." PCGamingWiki runs MediaWiki; MediaWiki's actual policy is closer to "be reasonable, identify yourself" — there's a published *etiquette* (one connection, sensible UA), not necessarily a hard 30/min number. The 30 figure may be conflated with another wiki's cap.

**Why this matters:** If you cite this number in `ARCHITECTURE.md` and an interviewer Googles it during your interview, finding nothing supports it is a credibility hit. Worse, the rate-limit budget drives your "100-game library takes ~3.5 minutes" claim.

**Recommendation:** Before Phase 4 starts, do one read-through of [https://www.pcgamingwiki.com/wiki/PCGamingWiki:API](https://www.pcgamingwiki.com/wiki/PCGamingWiki:API) and record the *actual* stated limit (or "no published per-minute limit; we throttled to 30/min conservatively"). Use Cargo queries (`?action=cargoquery&format=json&tables=...`) rather than scraping article markup — Cargo gives you the structured graphics-options table directly. Mention Cargo in the plan; not all MediaWiki consumers know about it.

### Gap 3 — PassMark CSV license is murkier than "public" implies

**Evidence:** The plan says "public PassMark GPU benchmark dataset from Kaggle (CSV)." PassMark's own benchmark scores are proprietary; community-uploaded Kaggle CSVs that mirror them sit in a grey area. Embedding a derivative in a public portfolio repo could draw a takedown email.

**Why this matters:** Low probability of action against a portfolio repo, but a known concern.

**Recommendation:** Two clean options:
- **Hand-curated GPU tier table** (~60 GPUs spanning RTX 4090 → GTX 1060, plus integrated). Tier assignment is *explicit* — defensible in an interview ("I picked these cutoffs because…"), no licensing question. Same "data engineering" signal.
- **TechPowerUp GPU database** scrape via their public pages, citing source per row. Still grey but the data's more accurate than community Kaggle dumps.

Pick one and document the choice in `ARCHITECTURE.md`. The hand-curated route is actually stronger interview material — *"I made the tier judgments and can defend each one"* beats *"I ingested someone else's CSV."*

### Gap 4 — Reverse mode breaks your own LLM-safety pattern

**Evidence:** Phase 5 sells a clean separation: rule-based engine produces settings, LLM writes prose only. That's a strong interview answer. But the premium "reverse mode" (paste current settings → get feedback) **requires the LLM to evaluate settings and make judgments** about them — it's no longer just explaining a deterministic recommendation.

**Why this matters:** An interviewer who liked your "LLM hallucination can't affect the recommendation" story will ask how reverse mode preserves it. Currently it doesn't.

**Recommendation:** Two options, pick before Phase 5:
- **Reverse mode is rule-based too.** Compare pasted settings against the recommended preset for `(game, gpu_tier, goal)`; produce a structured diff (`{texture_quality: "high → medium", ray_tracing: "on → off"}`). LLM explains the diff in prose. Same architecture, no hallucination risk. **This is the right answer.**
- **Acknowledge the asymmetry openly** in `ARCHITECTURE.md`: *"Forward mode is deterministic; reverse mode delegates judgment to the LLM. Acceptable for a portfolio because the worst case is bad advice, not bad settings on disk."* Honest, but weaker.

### Gap 5 — Steam `getOwnedGames` shape isn't specified

**Evidence:** Phase 2 says `getOwnedGames($steamId)` returns owned games + playtime. Default response gives only `appid` + `playtime_forever`. To get names, icons, and last-played, you need `include_appinfo=1&include_played_free_games=1`. Without those flags, your "title" and "cover_url" columns are empty after sync.

**Why this matters:** Phase 2 spec lists `title`, `cover_url`, `last_played_at` on the `games` schema — none of those are populated without the right query params. Easy to miss until you're staring at a row of `null`s in MySQL.

**Recommendation:** Add to Phase 2: *"`getOwnedGames` is called with `include_appinfo=1&include_played_free_games=1`. Cover art uses `https://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{img_icon_url}.jpg` — no extra API call needed."* One line, saves an evening of debugging.

### Gap 6 — ARCHITECTURE.md has no write schedule

**Evidence:** ARCHITECTURE.md is referenced ~15 times across phases ("document the tradeoff in ARCHITECTURE.md…", "the GPU tier thresholds belong here…"). The plan doesn't say *when* to write any of it.

**Why this matters:** Written at the end, it becomes retrofitted justification. Written during each phase, it's actual decision log — far better artifact.

**Recommendation:** Add a final task to every phase: *"Update ARCHITECTURE.md with this phase's decisions before opening the merge PR."* Fifteen minutes per phase; by Phase 6 the doc writes itself.

---

## Recommended Changes (Ordered by Impact)

### High Priority

1. **Cut the curated dataset to 10 games × 4 tiers × 3 goals = 120 records.** Pick titles spanning engines and genres so the demo still reads as broad. Defer the other 10 as a "post-portfolio expansion" line in README. Frees ~10 hours back into Phase 4.

2. **Make reverse mode rule-based.** Build a `SettingsDiffEngine` that compares pasted JSON to the canonical recommendation; LLM explains the diff. Preserves your hallucination-safety story across both modes.

3. **Verify PCGamingWiki's actual rate-limit policy and switch to Cargo queries.** Read their API page once before Phase 4 starts; note the verified policy in ARCHITECTURE.md. Use `action=cargoquery` to pull the structured graphics-settings table directly.

4. **Replace the PassMark Kaggle CSV with a hand-curated GPU tier table.** Stronger interview material, no licensing question, same data-engineering signal.

5. **Specify `include_appinfo=1&include_played_free_games=1` in the Steam sync plan**, plus the cover-art URL pattern.

### Medium Priority

6. **Add an end-of-phase ARCHITECTURE.md update task.** Each phase's PR gate.

7. **Pre-write the demo video script during Phase 5, not Phase 6.** The optimizer's demo punch is the *decision flow* (hardware + goal → tuned settings + explanation), and the script forces you to test whether that punch actually lands before AWS is on the meter.

8. **Pin the Anthropic model ID in `.env.example`** (e.g., `ANTHROPIC_MODEL=claude-haiku-4-5-20251001`). Haiku gets versioned; "Haiku" in code rots.

### Low Priority

9. **Add a Stripe test-mode walkthrough to TROUBLESHOOTING.md.** Stripe CLI webhook forwarding (`stripe listen --forward-to localhost/api/stripe/webhook`) is the failure mode that eats half a day if you haven't seen it.

10. **Drop the "5 RESTful endpoints" framing** in Phase 2 — `show` on a per-user resource is rarely useful and adds an authorization-test row no one needs. Four endpoints is fine.

---

## Stack and Architecture Verdict

**Keep the stack.** Laravel + MySQL + Redis + React + Stripe + Docker + AWS + Anthropic + Steam + PCGamingWiki is well-matched. The only stack-shaped concern is the PassMark CSV (replace per Gap 3), which is a *data source* change, not a *stack* change.

**Keep the phase ordering.** Auth → Library → Sessions → Data Pipeline → AI/Stripe → Deploy is correct. Phase 4 is the riskiest phase by far; the dataset-cut recommendation is the most important change in this review.

---

## Cost and Vendor Reality (deltas vs v2)

| New service in v3 | Cost reality |
|---|---|
| Steam Web API | Free; key required; 100k calls/day per key. Trivial. |
| PCGamingWiki | Free; community wiki; rate limit unverified (see Gap 2). |
| Anthropic Claude Haiku | ~$0.0008/M input + $4/M output for current Haiku. At ~500 in + 200 out tokens, ~$0.001/request. With Redis caching keyed on `(game, gpu_tier, cpu_tier, ram_bucket, goal)` you'll see >90% cache hit rate. **Steady-state cost: pennies/month.** |
| Stripe | Test-mode free. Live mode: 2.9% + 30¢ per real transaction — irrelevant for a portfolio in test mode. |

**AWS estimates from v2 review still hold.** The 48-hour live-deployment rule + $20 Budgets alert is the right protection.

---

## Risks, Assumptions, and Unknowns

- **PCGamingWiki rate-limit number is unverified.** Verify before Phase 4 starts (15 minutes of reading their API page).
- **The curated dataset's quality is the project's load-bearing artifact.** If it's thin or wrong, the demo is thin or wrong. Cite sources per record in the `notes` field — that single discipline turns "I made up numbers" into "I curated against published guides."
- **Phase 5 AI integration is the headline JD feature.** Protect its time by cutting Phase 4 scope at Gap 1's recommendation, not by hoping Phase 4 finishes on time.
- **No code exists yet.** This review is plan-only. File-level findings start being possible after Phase 0 lands.

---

## References

- Prior review: `cortex-lite-approach-review.md` (verified AWS pricing, Reverb architecture)
- PCGamingWiki API: https://www.pcgamingwiki.com/wiki/PCGamingWiki:API (verify rate-limit claim before Phase 4)
- Steam Web API `GetOwnedGames`: https://developer.valvesoftware.com/wiki/Steam_Web_API#GetOwnedGames_.28v0001.29
- Anthropic Claude pricing: https://www.anthropic.com/pricing (Haiku tier; verify model ID at build time)

---

**Bottom line:** v3 is ready to execute if you adopt the dataset cut (Gap 1) and the reverse-mode fix (Gap 4). Everything else is polish. The product story is now defensible end-to-end, which v2 couldn't claim.
