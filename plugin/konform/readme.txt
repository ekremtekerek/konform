=== Konform ===
Contributors: ekremtekerek
Tags: woocommerce, e-invoicing, e-rechnung, factur-x, xrechnung
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out which of your orders would be rejected as e-invoices — before the tax authority does.

== Description ==

**Producing e-invoice XML is the easy part. The hard part is that WooCommerce
order data is rarely clean enough for it** — and you find out weeks later, when
an invoice comes back rejected and you have to work out which of two hundred
business rules you broke.

Konform starts at that problem, not at the XML.

Install it, open the report, and it tells you in plain language which of your
recent orders would be rejected and why. Then it produces the document your
country requires — and, on the Pro plan, checks it against the **official**
EN 16931 rule set before it is issued.

= The pre-flight check =

This is what the plugin is for. It scans your orders and reports, for each
problem: what happened, why it matters, the exact rule reference, and where to
fix it. No jargon, no "invalid order".

Real examples from the report:

* *"This is a cross-border EU business sale with no VAT, but the customer VAT
  number is missing."* — `BT-48 / BR-AE-09`. Without it the exemption cannot be
  justified and the invoice is rejected.
* *"The invoice lines add up to €120.00 but the order total is €112.50, a
  difference of €7.50."* — `BT-112 / BR-CO-15`. Validators reject totals that
  do not reconcile, even by one cent.

Findings are grouped by root cause, so a store-wide problem is reported once —
not repeated on every order.

Other things it catches:

* Sales to EU consumers where no VAT was charged at all
* Missing store VAT number, incomplete store address
* VAT categories that contradict the rate applied
* Orders with no customer name or no billing country

When Konform has to make a judgement call — is this a service or goods? — it
says so and marks the order for review instead of guessing silently. You can
override it with a filter.

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

**It does not transmit invoices.** Konform produces the document and checks it;
delivery goes through your own accredited provider — a PDP in France, a Peppol
access point elsewhere. If you are looking for a plugin that sends invoices to
a network, this is not it, and you should not buy it expecting that.

It also **does not guarantee legal compliance**. No software can. Whether a
specific invoice is accepted depends on your registration, your provider and
rules that change over time. What Konform can honestly promise is narrower and
more useful: it tells you when your data will fail the standard, and it checks
the finished document against the official rule set before you issue it.

= Who this is for =

Shops that already know they need e-invoicing and want to find out, now,
whether their order data is ready — rather than discovering it one rejection
at a time.

France requires e-invoicing from September 2026, small businesses from
September 2027. Poland's KSeF already covers most VAT-registered businesses.
Germany accepts XRechnung and ZUGFeRD today.

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

France (Factur-X, *facture électronique*) and Germany (XRechnung,
*E-Rechnung*) are fully supported. Other EU countries receive EN 16931 CII
output, which is the common semantic standard behind both.

**Poland is not supported**, and it is worth being clear why. KSeF is not just
a format: an FA(3) invoice does not legally exist until it has been submitted
to the KSeF platform and given a KSeF number. Producing the file without
sending it would leave you holding something that looks like an invoice and
is not one. Since this plugin deliberately does not transmit, supporting
Poland properly means more than adding a format, and it is not something to
promise before it is built.

= Is the free version actually usable? =

Yes, and not in the "crippled demo" sense. The free version does everything
the plugin itself is capable of: it scans your orders, reports every problem
it finds, generates real Factur-X and XRechnung documents, produces credit
notes for refunds, archives every version with a hash, and generates a
document automatically when an order completes. Nothing in the code is
switched off by a licence.

Pro adds one thing, because it is the one thing the plugin cannot do on its
own: validation against the **official** EN 16931 rule set before a document
is issued. That rule set compiles to XSLT 2.0 and PHP's XSL extension only
supports XSLT 1.0, so the check runs on a hosted service instead of on your
site. See **External services** above.

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
