=== Konform ===
Contributors: ekremtekerek
Tags: woocommerce, e-invoicing, e-rechnung, factur-x, ksef
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 0.2.1
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
* **Poland** — KSeF FA(3), submitted to the national platform
* **Other countries** — EN 16931 CII

The tax category (standard, reverse charge, intra-community supply, export) is
derived from where the seller and buyer are, not guessed from the rate alone.
Where a decision is uncertain, Konform says so instead of silently guessing.

Documents are archived and never overwritten. Regenerating creates a new
version; the old one stays, because quietly replacing an issued invoice is not
something an audit will forgive.

= What this plugin does not do =

**It does not transmit invoices, except to KSeF.** Konform produces the
document and checks it; delivery goes through your own accredited provider — a
PDP in France, a Peppol access point elsewhere. Poland is the exception,
because there an unsent file is not an invoice at all. If you are looking for a
plugin that sends invoices to a network, this is not it, and you should not buy
it expecting that.

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

**KSeF (Poland only, required for Polish stores)**

If your store is based in Poland, each generated FA(3) invoice is submitted to
the Polish Ministry of Finance's National e-Invoice System (KSeF). This is not
optional for Polish stores: an FA(3) file has no legal standing until KSeF
accepts it and assigns a number.

The invoice is encrypted on your site before it leaves it, and sent over HTTPS.
It contains your invoice data: seller and buyer names, addresses, tax
identifiers and line items. You choose the environment; the test environment
has no legal effect.

* Service: KSeF (Krajowy System e-Faktur), Ministerstwo Finansów
* Test: https://api-test.ksef.mf.gov.pl
* Production: https://api.ksef.mf.gov.pl
* Terms: https://ksef.mf.gov.pl

Stores outside Poland never contact KSeF.

The free version performs no other external requests, apart from the language
pack downloads that WordPress itself makes.

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

If your store is in Poland, yes: FA(3) invoices are submitted to KSeF, because
there an invoice does not legally exist until KSeF has accepted it. That is the
whole point of the Polish system.

Everywhere else, no. On Pro, the document is sent to the validation service
described under **External services**, and only if you enable it.

= Which countries are supported? =

France (Factur-X, *facture électronique*), Germany (XRechnung, *E-Rechnung*)
and Poland (KSeF FA(3)) are fully supported. Other EU countries receive
EN 16931 CII output, which is the common semantic standard behind them.

**Poland** is supported, and it works differently from the others. KSeF is not
just a format: an FA(3) invoice does not legally exist until KSeF has accepted
it and assigned a number. So for Poland, Konform does send: it submits each
invoice to KSeF, waits for the number and records it against the order.

This is the one case where the plugin transmits, because producing the file
without sending it would leave you holding something that looks like an
invoice and is not one.

You need a KSeF token from your KSeF account. Start in the test environment —
invoices sent there have no legal effect — and switch to production when you
are satisfied.

One thing to check if you issue VAT-exempt invoices: KSeF requires the legal
basis for the exemption and keeps three separate fields for it — a Polish act,
an EU directive, or another basis. Konform reads your exemption reason and
picks the matching field; if the text names no recognisable provision, it uses
"other". That is a best effort, not a legal opinion, so have your accountant
confirm the basis you record is the right one.

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

= 0.2.1 =
* Poland: the legal basis for a VAT exemption now goes to the field KSeF
  expects — a Polish act, an EU directive, or "other" — instead of always
  "other".

= 0.2.0 =
* Poland: KSeF FA(3) generation and submission. Invoices are sent to the
  national platform, the KSeF number is recorded against the order and shown
  on the order screen.
* Documents that have been sent are never sent twice, even if a retry happens
  after a timeout or a crash.
* Settings for the KSeF token and environment; the test environment is the
  default and invoices sent there have no legal effect.

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

= 0.2.1 =
Polish stores that issue VAT-exempt invoices should reissue any exempt invoice
sent with 0.2.0; the exemption basis was recorded in the wrong field.

= 0.2.0 =
Adds Poland (KSeF). Polish stores must enter a KSeF token; other stores are
unaffected.

= 0.1.0 =
First release.
