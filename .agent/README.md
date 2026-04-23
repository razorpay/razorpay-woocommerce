# .agent/ — Agentic Development Infrastructure for razorpay-woocommerce

This folder contains the configuration, skills, and prompts that power AI-assisted development for the Razorpay WooCommerce plugin. It is designed to work with Claude Code, Gemini CLI, Kimi, and any other LLM-based development tools.

---

## Overview

The `.agent/` system has three components:

| Component | Path | Purpose |
|---|---|---|
| Model configs | `.agent/config/` | LLM-specific tuning for optimal output |
| Repo skills | `.agent/skills/` | Step-by-step runbooks for common tasks |
| Prompts | `.agent/prompts/` | Reusable prompt templates |

---

## Supported LLMs

| LLM | Config file | Primary context file |
|---|---|---|
| Claude (all versions) | `.agent/config/claude.yaml` | `CLAUDE.md` |
| Gemini (all versions) | `.agent/config/gemini.yaml` | `.gemini/GEMINI.md` |
| Kimi | `.agent/config/kimi.yaml` | `.kimi/KIMI.md` |
| All models | `.agent/config/models.yaml` | `AGENTS.md` |

> **Claude users:** Start with `CLAUDE.md`. It references all skills and model selection guidance.
> **Other LLM users:** Start with `AGENTS.md` for a universal overview.

---

## Skill System

Skills are step-by-step guides that tell an AI agent exactly how to accomplish a specific task within this codebase. Each skill lives in `.agent/skills/` and covers a real workflow that comes up during development or debugging of this plugin.

### Available Skills

| Skill | File | Use when... |
|---|---|---|
| Debug a failed payment | `skills/debug-payment.md` | A merchant reports a payment not reflecting |
| Add a new webhook handler | `skills/add-webhook-handler.md` | A new Razorpay event type needs handling |
| Add a payment method variant | `skills/add-payment-method-variant.md` | Adding UPI Autopay, EMI type, etc. |
| Investigate a refund | `skills/refund-investigation.md` | A refund hasn't processed or is stuck |
| Debug subscription failures | `skills/subscription-debug.md` | Recurring payments failing |
| Sync order status | `skills/order-status-sync.md` | WC order out of sync with Razorpay dashboard |
| Generate test webhook payloads | `skills/generate-test-payload.md` | Writing tests or debugging locally |
| Audit all API calls | `skills/api-endpoint-audit.md` | Security review or rate limit analysis |

### How to Invoke a Skill

When starting a session, tell the agent:

```
Use the skill at .agent/skills/debug-payment.md to investigate order #1234.
```

Or in Claude Code:

```
/skill debug-payment — order ID 1234, payment rzp_pay_ABC123
```

---

## Config Files

- **`config/models.yaml`** — Master model list with task mappings for all supported LLMs
- **`config/claude.yaml`** — Claude-specific model settings (haiku → opus, temperatures, max_tokens)
- **`config/gemini.yaml`** — Gemini-specific settings (Flash vs Pro vs Ultra)
- **`config/kimi.yaml`** — Kimi model settings

---

## Prompt Templates

Templates in `.agent/prompts/` are ready-to-use prompts you can paste into any LLM:

| Prompt | File |
|---|---|
| Code review | `prompts/code-review.md` |
| Bug report analysis | `prompts/bug-report-analysis.md` |
| Feature planning | `prompts/feature-planning.md` |
| Security audit | `prompts/security-audit.md` |

---

## Related Files

- `AGENTS.md` — Universal AI context (all LLMs)
- `CLAUDE.md` — Claude-specific patterns, gotchas, and skill references
- `.gemini/GEMINI.md` — Gemini-specific context
- `.kimi/KIMI.md` — Kimi-specific context
- `.ai/context/` — Deep-dive context files (architecture, flows, DB schema)
- `.ai/diagrams/` — System and sequence diagrams
