# Native Agent Contract (Hypothetical)

> Cortex Lite is the web/cloud companion layer of a Cortex-style product. The
> native agent that would collect real hardware telemetry and detect game
> launches is out of scope because the browser security model makes it
> impossible to build in the web layer. This document is the contract the web
> layer would expose to that agent: the half we did not build, made as legible as
> the half we did.

## 1. Scope

| Concern | Owner |
|---|---|
| Exact GPU/CPU model, driver version, VRAM, live clocks/temps | Native agent |
| Running-process / game-launch detection | Native agent |
| Wall-clock session start/stop from the OS | Native agent |
| Library, manual sessions, recommendations, billing, auth | Web layer (this app) |
| Settings recommendation logic + LLM prose | Web layer (deterministic engine + Gemini) |

The agent observes and reports. It never decides settings and never renders UI;
the web layer owns all product logic.

## 2. Authentication & Transport

- Transport: mTLS. The agent holds a device certificate issued at pairing; the
  web layer pins the issuing CA.
- Message integrity: each payload is a signed JWT (EdDSA), `sub` = device id,
  `iat`/`exp` set, signed with the device key registered at pairing.
- The web layer never trusts an unsigned or expired payload and rejects any
  device id not in the paired-devices table (HTTP 401).

## 3. Payload Schema

### 3.1 Hardware Snapshot

Sent on change and daily.

```json
{
  "type": "hardware_snapshot",
  "device_id": "d_9f3...",
  "captured_at": "2026-07-09T10:00:00Z",
  "gpu": { "model": "NVIDIA GeForce RTX 4070", "vram_mb": 12288, "driver": "556.12" },
  "cpu": { "model": "AMD Ryzen 5 7600X", "cores": 6, "threads": 12 },
  "ram_mb": 32768
}
```

### 3.2 Running-Game Detection

```json
{
  "type": "game_detected",
  "device_id": "d_9f3...",
  "observed_at": "2026-07-09T20:14:03Z",
  "steam_app_id": 1091500,
  "state": "launched"
}
```

`state` is one of `launched` or `closed`.

### 3.3 Session Event

```json
{
  "type": "session_event",
  "device_id": "d_9f3...",
  "steam_app_id": 1091500,
  "started_at": "2026-07-09T20:14:03Z",
  "ended_at": "2026-07-09T21:02:51Z"
}
```

## 4. Update Cadence

- Hardware snapshot: on detected change, plus a daily heartbeat.
- Game/session events: pushed within 5 seconds of the OS event; buffered locally
  and replayed if the web layer is unreachable.

## 5. Privacy

- Opt-in per telemetry category; the agent ships collecting nothing.
- No PII to the LLM: only hardware tiers and settings structures reach Gemini,
  never device ids or account identifiers.
- Local-first caching: the agent keeps its own buffer and uploads aggregates;
  raw process lists never leave the device.

## 6. Security Boundaries

- The agent never executes web-layer-supplied code; the contract is data-only.
- The web layer treats every field as untrusted input: validates the signature,
  then validates the payload against this schema before persisting.
- Steam app ids from the agent are reconciled against the user's owned library;
  an app id the user does not own is dropped, not auto-added.
