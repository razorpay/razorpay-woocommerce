# Skills — razorpay-woocommerce

Skills are step-by-step runbooks for common development and debugging tasks in this repository. Each skill is designed to be followed by an AI agent with access to the codebase.

---

## How Skills Work

A skill file tells the AI agent:
1. **What** the task is (Purpose)
2. **When** to use it (triggers)
3. **What to read first** (Prerequisites)
4. **How to do it** (Steps)
5. **Which files** are relevant (Key files)
6. **What patterns** the code follows (Common patterns)
7. **Sample prompts** to invoke the skill
8. **What to produce** (Output)

---

## Available Skills

| Skill | File | Category |
|---|---|---|
| Debug a failed payment | `debug-payment.md` | Debugging |
| Add a webhook event handler | `add-webhook-handler.md` | Feature |
| Add a payment method variant | `add-payment-method-variant.md` | Feature |
| Investigate a refund | `refund-investigation.md` | Debugging |
| Debug subscription failures | `subscription-debug.md` | Debugging |
| Sync order status with Razorpay | `order-status-sync.md` | Operations |
| Generate test webhook payloads | `generate-test-payload.md` | Testing |
| Audit all API endpoint calls | `api-endpoint-audit.md` | Audit |

---

## Invoking a Skill

### In Claude Code (CLI)
```
Use the skill at .agent/skills/debug-payment.md
Context: order ID 1234, merchant reports payment not reflecting
```

### As a prompt prefix
```
Follow the steps in .agent/skills/add-webhook-handler.md to add support
for the payment.dispute.created event.
```

### In Gemini CLI
```
@.agent/skills/refund-investigation.md
Investigate why refund for order #456 hasn't processed after 2 days.
```

---

## Recommended Model per Skill

| Skill | Recommended Claude Model |
|---|---|
| `debug-payment.md` | claude-3-5-sonnet-20241022 |
| `add-webhook-handler.md` | claude-3-5-sonnet-20241022 |
| `add-payment-method-variant.md` | claude-sonnet-4-5 |
| `refund-investigation.md` | claude-3-5-sonnet-20241022 |
| `subscription-debug.md` | claude-sonnet-4-5 |
| `order-status-sync.md` | claude-3-5-sonnet-20241022 |
| `generate-test-payload.md` | claude-3-5-haiku-20241022 |
| `api-endpoint-audit.md` | claude-3-opus-20240229 |

See `.agent/config/claude.yaml` for full model selection guidance.
