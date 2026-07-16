=== Paibao AI Operations Officer ===
Contributors: paibao
Tags: ai, seo, geo, content-operations, structured-data
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review and approve site-bound content, SEO and GEO operations from WordPress.

== Description ==

Paibao AI Operations Officer is the GPL WordPress bridge and administrator console for the separately hosted Paibao AI Operations service.

It provides:

* A dedicated, site-bound REST bridge for content, SEO and GEO operations.
* Change summaries and risk status inside WordPress admin.
* Explicit administrator approval before execution.
* Transactional audit snapshots and administrator-requested rollback.
* Managed canonical, hreflang, Open Graph, Twitter and approved JSON-LD output.
* Visible direct answers, facts, sources and review dates for GEO evidence.

The plugin does not contain the hosted planning engine, entitlement system or Marketplace billing. The GPL license to use and modify this plugin does not grant authorization to the Paibao SaaS service. A paid or otherwise valid service entitlement may be required by the control plane when a proposal is created or approved.

== External service ==

This plugin connects to https://marketplace.paibao.ai to exchange a server-managed, site-bound credential for a short-lived session and to create, read, approve or roll back AI Operations jobs.

Depending on the administrator's request, the Paibao service can receive the site's tenant/site binding, canonical site origin, task goal and scope, content records and managed SEO/GEO fields needed to plan a change, plus mutation and public-page verification results. The service returns connection data, job state, change summaries and verification evidence. These transfers are required for the managed service to operate.

Long-lived site credentials are installed by the managed host and are not shown in WordPress admin. Short-lived session credentials remain in PHP request memory and are not stored in WordPress or sent to the browser.

Service information, terms and privacy: https://paibao.ai/legal

== Installation ==

1. Confirm WordPress 6.4+, PHP 8.1+, HTTPS and InnoDB are available.
2. Install and activate the plugin through the Paibao managed deployment process.
3. Complete the site binding and service-user provisioning. Customers do not paste a token or Application Password.
4. Open AI 运营官 in WordPress admin. If service authorization is missing, use the fixed Marketplace purchase or renewal entry.

Do not enable another plugin that manages canonical/SEO output for the same content. Paibao fails closed when Yoast, Rank Math, AIOSEO or SEOPress is detected.

== Frequently Asked Questions ==

= Does installing the GPL plugin include the hosted service? =

No. The plugin license covers this WordPress code. Hosted orchestration, Marketplace billing and managed service authorization are separate.

= Can a task publish or roll back automatically from the WordPress admin page? =

No. Approve and rollback each require an administrator POST, a WordPress nonce and an explicit confirmation checkbox. A transport error is reported as “result pending confirmation” so the plugin does not blindly repeat an action.

= Can an expired subscription still view history or request rollback? =

History and rollback remain available for a valid site binding. Creating a proposal and approving execution are checked against current service authorization.

= Where are credentials stored? =

The site credential is supplied through server configuration. Five-minute AI sessions remain only in PHP memory. The dedicated WordPress Application Password is held by the managed control plane and is not displayed in this plugin.

== Changelog ==

= 0.2.3 =

* Aligned managed Open Graph, Twitter and JSON-LD input with the current Paibao planner contract.
* Added a full WordPress mutation, publication, public SEO/GEO and rollback integration gate.

= 0.2.2 =

* Added the GPL clean-room native WordPress bridge.
* Added transactional CAS, idempotency, audit snapshots and rollback.
* Added zero-copy short-lived session exchange and the administrator approval console.
* Added managed SEO/GEO output and deterministic release verification.
