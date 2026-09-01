# Privacy Policy

**Konform – EU E-Invoicing for WooCommerce**

Last updated: 1 September 2026

This policy explains what data the plugin handles, what leaves your server, and
what does not. It is written to be checkable against the source code: every
claim below corresponds to code you can read in this repository.

---

## The short version

| | Free | Pro |
|---|---|---|
| Invoice data leaves your server | **No** | Only if you enable validation |
| Site data sent to Freemius | Only if you opt in | Only if you opt in |
| Customer data sold or shared for advertising | **Never** | **Never** |

---

## 1. Data the plugin stores on your own server

Konform reads your WooCommerce orders and produces e-invoice documents. Those
documents contain what any invoice contains: your business details, your
customer's name, address and VAT number, the items sold and the amounts.

All of it stays on your own server:

- Generated documents are written to a directory inside `wp-content/uploads`
  whose name contains a random key, protected from direct web access.
- An index of documents and an audit trail are stored in two database tables
  in your own WordPress database.

Konform never transmits this data anywhere on the free plan.

**Retention.** Documents are kept for as long as you keep them. Automatic
deletion after the retention period (ten years by default) is **off unless you
switch it on**, because a plugin should not delete financial records on its own
initiative.

**Uninstalling.** Deleting the plugin does not delete your archive. That only
happens if you explicitly tick "delete all data on uninstall" first.

---

## 2. The validation service (Pro only, optional, off by default)

The Pro plan can check each document against the official EN 16931 rule set
before it is issued.

This check cannot run inside WordPress. The official rules compile to XSLT 2.0,
and PHP's XSL extension only supports XSLT 1.0. So the check runs on a service
operated by the plugin author.

**What is sent.** The invoice XML, over HTTPS. It contains seller and buyer
names, addresses, VAT numbers, line items and amounts — the same content as the
invoice itself.

**What happens to it.** It is parsed in memory, checked against the rule set,
and a list of findings is returned. **The XML is not written to disk and not
retained.** Request logs record timing and status codes, not invoice content.

**When it happens.** Only when all of the following are true: you are on the Pro
plan, you have entered a service address and licence key, and a document is
being generated. If any of these is missing, no request is made.

**Turning it off.** Clear the service address under **WooCommerce → Konform**.
Generation continues without validation.

If the service cannot be reached, Konform records why and generates the document
anyway. A network problem should never stop you invoicing.

---

## 3. Freemius (licensing and updates)

Licensing, payments and update delivery are handled by
[Freemius](https://freemius.com/), a third-party service.

Freemius asks for your consent the first time you activate the plugin. If you
**skip** that screen, no data is sent and the free plugin keeps working.

If you **allow** it, Freemius receives your site URL, WordPress and PHP
versions, plugin version, and the name and email address of the administrator
who opted in. This is what makes licence activation and automatic updates work.

Freemius is the data controller for that data. See the
[Freemius privacy policy](https://freemius.com/privacy/).

You can revoke this at any time from the plugin's Account screen.

---

## 4. Language packs

When an invoice needs a language that is not installed, Konform downloads the
translation from **WordPress.org**, using WordPress's own update mechanism. This
is the same request WordPress makes when you change a site's language. No
invoice data is involved.

---

## 5. What Konform never does

- It does not send your customer data to any advertising or analytics service.
- It does not sell, rent or share your data.
- It does not phone home on the free plan.
- It does not transmit invoices to any tax authority. Konform produces and
  validates the document; sending it is your own provider's job.

---

## 6. Your customers' rights

Under the GDPR you are the data controller for your customers' data. Konform is
a tool you run on your own infrastructure.

One point deserves attention: **an invoice cannot simply be deleted on request.**
Tax law requires invoices to be retained, and that obligation overrides a
deletion request for the invoice itself. This is a legal constraint, not a
limitation of the plugin.

---

## 7. Contact

Open an issue at
[github.com/ekremtekerek/konform/issues](https://github.com/ekremtekerek/konform/issues),
or email the address listed on the plugin's Freemius page.

---

> **Note.** This document describes how the software behaves. It is not legal
> advice. If you sell into the EU, have your own privacy notice reviewed by
> someone qualified in your jurisdiction.
