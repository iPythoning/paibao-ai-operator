# Paibao AI Operator (WordPress plugin)

Open-core thin client for the **Paibao AI Operator** — generate GEO-optimized, fact-dense
B2B articles (with Article/FAQPage JSON-LD) and stage them as WordPress drafts.

The generation engine (dynamic knowledge base, expert interview, GEO grounding) runs in the
hosted service. This plugin connects with a **license key**, fetches generated content in
*pull mode*, and places it into WordPress. No engine code or proprietary knowledge ships here.

## How it works

```
WP admin "AI Operator" page
  → POST {topic, languages, market} + X-License-Key
  → https://console.paibao.ai/api/operator/generate   (hosted engine, tier-gated)
  → returns drafts: [{ title, slug, html, markdown, jsonld, excerpt, locale }]
  → wp_insert_post(draft) + post_meta(_paibao_jsonld)
  → wp_head injects <script type="application/ld+json">
```

- Content body uses the returned HTML (the embedded JSON-LD `<script>` is stripped by
  `wp_kses_post`; JSON-LD is re-emitted in `<head>` from post meta).
- Everything is staged as **draft** — nothing publishes without review.

## Install (dev)

Copy this folder to `wp-content/plugins/paibao-ai-operator/`, activate, then set your
license key under **AI Operator → Connection**.

## Settings

| Setting | Default | Notes |
|---|---|---|
| License key | — | from https://paibao.ai/operator |
| Service URL | `https://console.paibao.ai` | the hosted operator endpoint |

## License

GPL-2.0-or-later. See `LICENSE`.
