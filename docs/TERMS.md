# Terms of Use

**Konform – EU E-Invoicing for WooCommerce**

Last updated: 1 September 2026

---

## 1. The software

Konform is a WordPress plugin that converts WooCommerce orders into e-invoice
documents conforming to EN 16931, and archives them.

The free version is distributed under the **GPL v2 or later**. You may use,
study, modify and redistribute it under that licence.

The Pro version adds features listed on the plugin's sales page. Buying it gives
you a licence to use those features on the number of sites your plan covers, for
as long as your licence is active.

---

## 2. What this software does not promise

This matters more than anything else on this page, so it is stated plainly.

**Konform does not guarantee legal compliance.** No software can. Whether a
specific invoice is accepted depends on your VAT registration, your accredited
provider, the rules of the country you are invoicing in, and how those rules
change over time. Konform produces documents that conform to the EN 16931
standard and, on the Pro plan, verifies them against the official rule set
before they are issued. That is a strong check. It is not a guarantee.

**Konform does not transmit invoices to any tax authority.** It produces and
validates the document. Delivery goes through your own accredited provider
(a PDP in France, a Peppol access point elsewhere). Choosing and contracting
with that provider is your responsibility.

**Konform is not tax advice.** The pre-flight check reports where your order
data does not satisfy the standard. It does not tell you what your tax
obligations are. For that, talk to an accountant.

---

## 3. Your responsibilities

- Keeping your business details, VAT number and tax settings correct.
- Choosing and paying for an accredited e-invoicing provider where the law
  requires one.
- Retaining invoices for the period your national law requires.
- Keeping backups. Konform archives documents on your server; it is not a
  backup service.

---

## 4. The validation service

The Pro plan may send invoice XML to a validation service operated by the
plugin author. This is **off by default** and only runs once you configure it.
See the [privacy policy](PRIVACY.md) for exactly what is sent and what happens
to it.

The service is provided on a best-effort basis. It may be unavailable for
maintenance or for reasons outside our control. **If it is unreachable, Konform
generates the document anyway** and records that validation did not run — a
network problem must never stop you invoicing.

We may update the rule set when the European Commission publishes a new
version. A document that validated yesterday may report findings tomorrow if the
rules changed; that reflects the rules, not a defect.

---

## 5. Payments, renewals and refunds

Payments are processed by [Freemius](https://freemius.com/), who act as merchant
of record. Their terms govern the transaction, including VAT handling, renewals
and cancellation.

Refunds follow the refund policy shown at checkout.

Licences renew annually unless cancelled. You can cancel at any time from your
Freemius account; access continues until the end of the paid period.

If a licence lapses, previously generated documents remain in your archive and
remain yours. Pro features stop working.

---

## 6. Liability

The software is provided **as is**, without warranty of any kind, as stated in
the GPL.

To the maximum extent permitted by law, the author is not liable for indirect or
consequential loss, including lost profits, penalties assessed by a tax
authority, or rejected invoices.

Nothing here limits liability that cannot be limited by law.

---

## 7. Changes

These terms may change as the product develops. Material changes will be noted
in the plugin's changelog. The version in effect is the one published here at
the time of your purchase or renewal.

---

## 8. Contact

Open an issue at
[github.com/ekremtekerek/konform/issues](https://github.com/ekremtekerek/konform/issues).

---

> **Note.** This is a plain-language description of the terms on which the
> software is offered. It is not legal advice and it has not been reviewed by a
> lawyer. Before selling commercially into the EU, have it checked by someone
> qualified in your jurisdiction — particularly the liability section, which is
> the part that matters if something goes wrong.
