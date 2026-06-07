=== Paibao AI Operator ===
Contributors: paibao
Tags: ai, seo, content, geo, structured-data
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate GEO-optimized, fact-dense B2B articles with Article/FAQPage JSON-LD and stage them as drafts. Connects to the Paibao AI Operator service.

== Description ==

Paibao AI Operator is an open-core thin client. It turns a topic into a publish-ready,
GEO-optimized article — inverted-pyramid lead, a comparison table, buyer FAQs, and
auto-generated Article + FAQPage JSON-LD — and stages it as a draft for your review.

The generation engine (dynamic knowledge base, expert interview, fact grounding) runs in
the hosted Paibao service. This plugin connects to it with a license key, fetches the
generated content, and places it into WordPress. Your content stays in your WordPress.

= What it does =

* Generate multilingual drafts from a topic (or auto-suggested from your knowledge base)
* Inject Article / FAQPage JSON-LD into the post `<head>` for AI-search visibility
* Everything is staged as a **draft** — nothing is published without your review

= Account =

A Paibao AI Operator license key is required to generate content. Visit
https://paibao.ai/operator to get one. The plugin sends your topic/market/language
choices to the service and receives the generated article back.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate it.
3. Open **AI Operator** in the admin menu, paste your license key, and Save.
4. Enter a topic and click **Generate draft**.

== Frequently Asked Questions ==

= Is my content sent to a third party? =

The plugin sends the topic, target market, and language selection you enter to the Paibao
AI Operator service ( https://paibao.ai ) to generate the article, and receives the article
back. No post content is sent except what you ask it to generate. See the privacy section.

= Do I need an account? =

Yes — a license key from https://paibao.ai/operator is required.

== Privacy ==

This plugin connects to the external Paibao AI Operator service to generate content. The
topic, target market, and language values you submit are sent to that service over HTTPS,
authenticated with your license key. The generated article is returned and stored as a
draft in your WordPress. Service: https://paibao.ai — Terms/Privacy: https://paibao.ai/legal

== Changelog ==

= 0.1.0 =
* Initial release: license-key connection, multilingual draft generation, JSON-LD injection.
