=== Konto Checkout for WooCommerce ===
Contributors: kontoreikningar
Tags: invoice, iceland, e-invoice, inventory, payment gateway
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Repo: https://github.com/Konto-ehf/KontoforWooCommerce
Requires Plugins: woocommerce

Icelandic e-invoices, online-bank claims and stock sync for WooCommerce. Konto invoices your orders and keeps your stock in step.

== Description ==

Sell the way Iceland pays. Konto Checkout connects your WooCommerce store to [Konto](https://konto.is), the Icelandic e-invoicing platform. Your customers can pay by invoice in their online bank, every order can get a proper Icelandic invoice, and your stock follows what you actually have on the shelf.

= Pay with Konto =

Customers choose "Reikningur í netbanka" at checkout and enter their kennitala. Konto issues the invoice, emails the PDF (or sends an XML e-invoice to companies) and creates a claim that shows up in the customer's online bank under "Ógreiddir reikningar". The kennitala is checked before the order goes through.

= An invoice for every order =

Orders paid by card or any other method can go to Konto too: one click in the order list, or automatically when the order is Processing. No kennitala? The invoice becomes a cash sale (Staðgreitt) on your own kennitala, and the customer still gets the receipt by email.

= Stock that matches Konto =

With the Konto Inventory add-on, Konto is where you manage stock and lots, and WooCommerce follows:

* Products are matched on SKU = Konto item number.
* Stock updates every 15 minutes, or right away with "Sync stock now".
* Orders that are not invoiced yet are held back, so you never sell the same item twice.
* Invoices draw stock from the lots that expire first. The order note shows which lots were used.

= Refunds become credit notes =

Refund an order in WooCommerce and Konto creates the credit note. A full refund closes the invoice and cancels an unpaid bank claim. Items you restock go straight back into the Konto lots they came from.

= Built for Icelandic VAT =

VAT is read from each order line: 24%, 11% or 0%. Shipping and fees keep their own VAT, and a rounding line takes care of the last króna.

= Works with your store as it is =

* Block checkout and classic checkout.
* WooCommerce High-Performance Order Storage (HPOS).
* Test mode for trying things out before going live.

= What you need =

* A Konto plan with API access (the "API Access & Web Services" add-on, Vefþjónusta), which gives you a username and an API key.
* For stock sync: the Konto Inventory add-on.

= Step-by-step guides =

Guides with screenshots and video, in Icelandic, English and Polish:

* [Set up Konto for WooCommerce](https://heim.konto.is/konto-fyrir-woocommerce/)
* [Stock sync from Konto](https://heim.konto.is/konto-woocommerce-lagerstada/)
* [Refunds become credit notes](https://heim.konto.is/konto-woocommerce-endurgreidslur-kreditreikningar/)

= Á íslensku =

Konto Checkout tengir WooCommerce-vefverslunina þína við Konto. Viðskiptavinir geta greitt með rafrænum reikningi og kröfu í netbanka. Pantanir fá rafrænan reikning úr Konto og lagerstaða verslunarinnar fylgir lagerstöðu í Konto, svo þú seljir aldrei það sem ekki er til. Endurgreiðslur verða sjálfkrafa að kreditreikningum.

Þú finnur notandanafn og API-lykil í Konto undir Stillingar > API Access & Web Services (Vefþjónusta). Á heim.konto.is má finna leiðbeiningar með skjámyndum og myndbandi um [uppsetningu](https://heim.konto.is/konto-fyrir-woocommerce/), [samstillingu lagerstöðu](https://heim.konto.is/konto-woocommerce-lagerstada/) og [kreditreikninga vegna endurgreiðslna](https://heim.konto.is/konto-woocommerce-endurgreidslur-kreditreikningar/).

== Installation ==

1. Install "Konto Checkout for WooCommerce" from Plugins > Add New, and activate it.
2. Go to WooCommerce > Settings > Payments > Konto.
3. Enter your Konto username and API key and save. In Konto you find them under Stillingar > API Access & Web Services (Vefþjónusta). The page confirms the connection and shows your Konto account.
4. Optional: turn on "Stock sync", and give each product the same SKU as its item number in Konto.
5. Optional: turn on automatic invoices for orders paid by other methods.

The full walkthrough, with screenshots and video: [Set up Konto for WooCommerce](https://heim.konto.is/konto-fyrir-woocommerce/).

== Frequently Asked Questions ==

= Do customers need a kennitala? =

Only when they pay with Konto, because the bank claim is created on their kennitala. With other payment methods it is optional. Any kennitala that is entered is checked.

= What happens when there is no kennitala? =

The invoice is a cash sale: billed to "Staðgreitt" on your own kennitala. The customer still gets the invoice by email.

= Can I use Konto next to card payments? =

Yes. Konto is one payment method among the others, and orders paid by card can still be invoiced in Konto, by hand or automatically.

= Which stock figure wins, WooCommerce or Konto? =

Konto. Add or remove stock in Konto (as lots), not in WooCommerce. The next sync overwrites the WooCommerce number.

= What about a partial refund of an unpaid bank claim? =

A credit note can't shrink a claim that is still open, so the plugin doesn't create one. The order note tells you to adjust or cancel the claim in Konto. Once the invoice is paid, "Konto: create credit notes for refunds" on the order screen creates the missing credit notes.

= Which VAT rates are supported? =

24%, 11% and 0%, the rates Konto invoices with. An order line with any other rate stops with a clear message instead of creating a wrong invoice.

= Is there a test mode? =

Yes. Test mode sends requests to the test server set under Advanced in the plugin settings.

== Screenshots ==

1. Checkout: the customer picks "Reikningur í netbanka" and enters a kennitala.
2. The order: the Konto invoice number on the order, and the stock lots it used in the order notes.
3. Settings: connect your Konto account and choose how invoices are made.
4. Stock sync: WooCommerce stock follows Konto, with a status report and "Sync stock now".
5. Products: stock levels kept in step with Konto inventory.
6. Refunds: a WooCommerce refund becomes a Konto credit note, with restocked items back in their lots.
7. Orders paid by other methods: send them to Konto as an invoice or a draft with one click.

== Changelog ==

= 2.1.0 =
* New: stock sync from Konto inventory (lots), matched on SKU, every 15 minutes or on demand.
* New: invoices draw Konto lots down, earliest expiry first. The order note lists the lots used.
* New: block checkout support, both the payment method and the kennitala and XML fields.
* New: High-Performance Order Storage (HPOS) support.
* New: refunds create Konto credit notes (full or partial), and restocked items go back into their Konto lots.
* New: "Konto: create credit notes for refunds" on the order screen, for refunds without a credit note.
* New: the Konto invoice and credit note numbers are shown on the order screen and in order notes.
* New: optional automatic invoices for orders paid by other methods.
* New: "Konto: issue invoice" and "Konto: save as draft" on the order screen.
* New: due days, final due days and invoice language settings.
* New: step-by-step guides on heim.konto.is, linked from the settings page.
* Changed: kennitala is required only when paying with Konto, and its check digit is validated.
* Changed: without a kennitala the invoice is a cash sale (Staðgreitt) on the shop's kennitala.
* Changed: VAT comes from the order's tax lines. Shipping keeps its VAT, fees get their own lines, and a rounding line absorbs differences of up to 1 kr.
* Changed: a Konto checkout that waits for payment is On hold (stock reserved) instead of Pending payment.
* Fix: the classic checkout rejected every order where a kennitala was entered.
* Security: invoice buttons check a nonce and the user's permission. Requests verify the SSL certificate. The API key is never logged, and the settings field hides it.

= 2.0.1 =
* Previous release.

== Upgrade Notice ==

= 2.1.0 =
Fixes the classic checkout rejecting orders with a kennitala, and fixes a permission check on the invoice buttons. Adds stock sync from Konto inventory, credit notes for refunds and block checkout support.
