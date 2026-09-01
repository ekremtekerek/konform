=== Konform – EU E-Invoicing for WooCommerce ===
Contributors: cisoft
Tags: e-invoicing, woocommerce, factur-x, xrechnung, en16931
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out which of your orders would be rejected as e-invoices — before the tax authority does.

== Description ==

Electronic invoicing — *facture électronique* in France, *elektronische
Rechnung* in Germany, *faktura ustrukturyzowana* in Poland — is becoming
mandatory across the EU.

France requires it from September 2026, with small businesses following in
September 2027. Poland's KSeF already covers most VAT-registered businesses.
Germany accepts XRechnung and ZUGFeRD today.

The hard part is not producing XML. The hard part is that **WooCommerce order
data is rarely clean enough** for the EN 16931 standard, and you only find out
when an invoice is rejected — often weeks later.

Konform starts by telling you the truth about your data.

= The pre-flight check =

Install the plugin and open the report. It scans your recent orders and tells
you, in plain language, which ones would be rejected and why:

* Cross-border EU business sales with no VAT and no customer VAT number
* Sales to EU consumers where no VAT was charged at all
* Invoice totals that do not reconcile with the order total, down to the cent
* Missing store VAT number, incomplete store address
* VAT categories that contradict the rate applied

Every finding says three things: what happened, why it matters (with the exact
EN 16931 rule), and where to fix it. No jargon, no "invalid order".

= Generating documents =

Konform maps each order to the EN 16931 semantic model and produces the format
your country requires:

* **France** — Factur-X: a PDF/A-3 file with the XML embedded inside it
* **Germany** — XRechnung 3.0 (pure XML)
* **Other countries** — EN 16931 CII

The tax category (standard, reverse charge, intra-community supply, export) is
derived from where the seller and buyer are, not guessed from the rate alone.
Where a decision is uncertain, Konform says so instead of silently guessing.

Documents are archived and never overwritten. Regenerating creates a new
version; the old one stays, because quietly replacing an issued invoice is not
something an audit will forgive.

= What this plugin does not do =

It does not transmit invoices to a tax authority. Konform produces and validates
the document; delivery goes through your own accredited provider. It also does
not guarantee legal compliance — no software can.

== External services ==

**Official validation service (Pro only, optional)**

The Pro version can validate each document against the official EN 16931
Schematron rule set. This validation cannot run inside WordPress: the rule set
compiles to XSLT 2.0, and PHP's XSL extension only supports XSLT 1.0.

When enabled, the invoice XML is sent over HTTPS to a validation service
operated by the plugin author. The XML contains your invoice data, including
seller and buyer names, addresses, VAT numbers and line items. It is processed
in memory to produce the validation report and is not stored.

This service is **off by default** and only runs on the Pro plan after you enter
an endpoint and licence key.

* Service: Konform validation service
* Terms: https://github.com/ekremtekerek/konform/blob/main/docs/TERMS.md
* Privacy: https://github.com/ekremtekerek/konform/blob/main/docs/PRIVACY.md

The free version performs no external requests other than the language pack
downloads that WordPress itself makes.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Konform.
3. Go to **WooCommerce → Konform** and enter your VAT number.
4. Read the pre-flight report.

== Frequently Asked Questions ==

= Does it work without a PDF invoice plugin? =

Yes. Konform includes a plain fallback template. If you already use a PDF
invoice plugin such as WooCommerce PDF Invoices & Packing Slips, Konform embeds
the XML into that plugin's PDF instead, so your own design and branding are kept.

The built-in fallback template is limited to the Latin-1 character set. If a
customer's name contains characters outside it, Konform refuses to produce the
PDF and tells you which field is affected, rather than printing the name wrongly.
Installing a PDF invoice plugin removes that limit.

= Will my invoices be accepted? =

Konform produces documents that conform to EN 16931 and, on the Pro plan,
verifies them against the official rule set before they leave your site. Whether
a specific tax authority accepts a specific invoice also depends on your
registration, your provider and rules that change over time. No plugin can
promise that, and any that does is not being honest with you.

= Does it send my invoices anywhere? =

Not on the free plan. On Pro, only to the validation service described under
**External services**, and only if you enable it.

= Which countries are supported? =

Version 1 targets France (Factur-X, *facture électronique*) and Germany
(XRechnung, *E-Rechnung*). Poland (KSeF, *faktura ustrukturyzowana*) is next.
Other EU countries receive EN 16931 CII output.

= Is the free version actually usable? =

Yes. The free version scans your last 50 orders, reports every problem it
finds, and generates real Factur-X or XRechnung documents when you click
"Generate document" on an order. Not a crippled demo.

Pro adds three things: unlimited scanning, automatic generation when an order
completes, and verification against the official EN 16931 rule set before the
document is issued.

== Screenshots ==

1. The pre-flight report: how many recent orders would be rejected, and why.
2. Findings grouped by root cause, each with the EN 16931 rule reference.
3. The e-invoice box on the order screen, with document versions and history.

== Changelog ==

= 0.1.0 =
* First release.
* Pre-flight check with five rule groups covering seller identity, customer
  identity, VAT categories, invoice totals and required fields.
* EN 16931 mapping with tax category resolution for domestic, OSS, reverse
  charge, intra-community and export scenarios.
* Factur-X (PDF/A-3) and XRechnung 3.0 output.
* Document archive with SHA-256 integrity checks, versioning and an audit trail.
* Optional validation against the official EN 16931 Schematron rule set.

== Upgrade Notice ==

= 0.1.0 =
First release.
