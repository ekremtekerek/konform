# Tanıtım metinleri

Bu metinler **sizin ağzınızdan** yazıldı. Gönderen siz olmalısınız; kendi
hesabınızdan, kendi sözünüzle. Aşağıdakiler taslak — kendinize göre
kısaltın, uzatın, üslubu değiştirin.

## Üç kural

**1. Geliştirici olduğunuzu her yerde açıkça söyleyin.** Metinlerin hepsinde
var. Gizlemek, tek bir yorumda ortaya çıktığında tüm güveni bitirir — ve
uyumluluk ürününde satılan şey güven.

**2. Her topluluğun kendi tanıtım kurallarını önce okuyun.** Bazıları kendi
ürününüzü paylaşmayı tamamen yasaklıyor, bazıları haftanın belirli bir gününe
sınırlıyor. Kural ihlali, ürünü orada kalıcı olarak yakar.

**3. Ücretli planı öne çıkarmayın.** Bu metinlerin işi ücretsiz aracı ve
demoyu göstermek. Fiyattan söz eden tek cümle bile paylaşımı reklama çevirir.

## Bağlantılar

- Demo: `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ekremtekerek/konform/main/demo/blueprint.json`
- Kaynak: `https://github.com/ekremtekerek/konform`

---

## 1. Reddit — r/woocommerce, r/Wordpress (İngilizce)

> **I built a free tool that tells you which of your WooCommerce orders would be rejected as e-invoices**
>
> I'm the developer, so treat this as a self-promotion post — but the tool is
> free and there is a browser demo, so you can judge it without installing
> anything.
>
> The background: EU e-invoicing is landing on a lot of shops. France from
> September 2026, Poland's KSeF is already live, Germany accepts XRechnung
> today. Most of the discussion is about producing the XML, which is honestly
> the easy part.
>
> The hard part is that WooCommerce order data is usually not clean enough for
> the standard, and you find that out weeks later when an invoice bounces and
> you have to work out which of two hundred business rules you broke.
>
> So the plugin starts there instead. It scans your orders and tells you, in
> plain language, which ones would be rejected and why — with the exact rule
> reference and where to fix it. Things like a cross-border EU business sale
> with no VAT and no customer VAT number, or invoice lines that add up to
> €120.00 against an order total of €112.50.
>
> Browser demo, nothing to install, loads a store with orders that are
> deliberately not ready:
> [link]
>
> It also generates Factur-X for France and XRechnung for Germany, but the
> pre-flight report is the part I actually care about.
>
> What it does not do: it does not transmit invoices to a network. That still
> goes through your own provider.
>
> Happy to hear where the checks are wrong — that is the useful feedback for
> me right now.

---

## 2. Kısa biçim — Facebook grupları, LinkedIn, forum yanıtları (İngilizce)

> I built a free WooCommerce plugin that answers one question: which of your
> orders would be rejected if you had to issue them as EU e-invoices?
>
> It reads your orders and reports each problem in plain language with the
> exact EN 16931 rule — missing customer VAT number on an intra-community
> sale, totals that do not reconcile, that kind of thing.
>
> Browser demo, no install: [link]
>
> I'm the developer. It's free; there is a paid tier for official validation,
> but the report is the part worth looking at.

---

## 3. Fransa — *facture électronique* (Fransızca)

Hedef: WooCommerce/WordPress Fransız grupları, e-ticaret forumları.

> **Un outil gratuit pour savoir quelles commandes seraient rejetées en
> facture électronique**
>
> Je suis le développeur, donc c'est bien une publication promotionnelle —
> mais l'outil est gratuit et il y a une démo dans le navigateur, sans rien
> installer.
>
> La facturation électronique arrive en septembre 2026 pour la réception, et
> l'essentiel des discussions porte sur la génération du XML. Ce n'est
> pourtant pas le plus difficile.
>
> Le vrai problème : les données de commande WooCommerce sont rarement assez
> propres pour la norme EN 16931, et on ne s'en aperçoit que des semaines plus
> tard, quand une facture est rejetée.
>
> L'extension commence donc par là. Elle analyse vos commandes et vous dit, en
> langage clair, lesquelles seraient rejetées et pourquoi, avec la règle exacte
> et l'endroit où corriger. Par exemple : une vente intracommunautaire sans TVA
> et sans numéro de TVA client, ou des lignes qui totalisent 120,00 € alors que
> la commande en affiche 112,50 €.
>
> Démo dans le navigateur, rien à installer : [lien]
>
> Elle génère aussi du Factur-X (PDF/A-3 avec le XML intégré). En revanche elle
> **ne transmet pas** les factures : cela passe toujours par votre PDP.
>
> Je suis preneur de retours, surtout si un contrôle vous semble faux.

---

## 4. Almanya — *E-Rechnung* (Almanca)

Hedef: WooCommerce/WordPress Alman grupları, e-ticaret forumları.

> **Kostenloses Werkzeug: welche Bestellungen würden als E-Rechnung abgelehnt?**
>
> Ich bin der Entwickler, das hier ist also Eigenwerbung — aber das Werkzeug
> ist kostenlos und es gibt eine Demo im Browser, ganz ohne Installation.
>
> Bei der E-Rechnung dreht sich fast alles um das Erzeugen der XML. Das ist
> aber der einfache Teil.
>
> Schwierig ist, dass WooCommerce-Bestelldaten für EN 16931 meist nicht sauber
> genug sind — und man merkt es erst Wochen später, wenn eine Rechnung
> abgelehnt wird.
>
> Das Plugin fängt deshalb dort an. Es prüft Ihre Bestellungen und sagt
> verständlich, welche abgelehnt würden und warum, mit der genauen Regel und
> der Stelle zum Korrigieren. Zum Beispiel: eine innergemeinschaftliche
> Lieferung ohne USt-IdNr. des Kunden, oder Positionen, die auf 120,00 €
> kommen, während die Bestellung 112,50 € ausweist.
>
> Demo im Browser, nichts zu installieren: [Link]
>
> Es erzeugt auch XRechnung 3.0. **Versendet** wird nichts — das läuft weiter
> über Ihren eigenen Dienstleister.
>
> Über Rückmeldungen freue ich mich, besonders wenn eine Prüfung falsch liegt.

---

## 5. Tek cümlelik biçim — X, Mastodon

> Which of your WooCommerce orders would be rejected as an EU e-invoice? Free
> plugin, browser demo, nothing to install: [link]

---

## Yapılmaması gerekenler

- **WordPress.org destek forumlarında kendi eklentinizi tanıtmayın.** Orası
  yardım içindir; tanıtım hem kural ihlali hem de eklenti dizinindeki
  itibarınızı riske atar.
- **Aynı metni birden çok yere kopyalamayın.** Her topluluğun tonu farklı ve
  kopyala-yapıştır anlaşılıyor.
- **Rakipleri kötülemeyin.** Özellikle POP gibi bizden fazlasını yapan
  (fatura ileten) ürünleri. Farkı anlatın, kusur aramayın.
- **Yorumlara cevap vermeyi göze alamayacağınız gün paylaşmayın.** Cevapsız
  bırakılmış bir tanıtım paylaşımı, hiç paylaşmamaktan kötüdür.

## Not

Fransızca ve Almanca metinler benim yazdığım taslaklardır. Yayınlamadan önce
ana dili o dil olan birinin gözden geçirmesi iyi olur — özellikle Almanca
metindeki resmî ton, o topluluklarda hassas bir konudur.
