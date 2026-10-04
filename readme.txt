=== Konto Checkout for WooCommerce ===
Contributors: kontoreikningar
Tags: invoices, eBank, gateway, checkout, inventory
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Repo: https://github.com/KontoIS/KontoforWooCommerce
Requires Plugins: woocommerce

Konto e-invoices and bank claims for WooCommerce orders in Iceland, with stock synced from Konto inventory (lots).

== Description ==

**Pay with Konto.** At checkout the customer chooses Konto, enters their kennitala and gets an electronic invoice (PDF, or XML for companies) by email. A payment claim appears in their online bank under "Ógreiddir reikningar".

**Invoices for every order.** Orders paid by card or another method can be sent to Konto as a paid invoice, with one click in the order list or automatically when the order is Processing. If the buyer gave no kennitala, the invoice is a cash sale (Staðgreitt) on the shop's own kennitala.

**Stock from Konto inventory.** With the Konto Inventory add-on, WooCommerce stock follows the lots you manage in Konto:

* Products are matched on SKU = Konto item number.
* Every 15 minutes (or on "Sync stock now"), WooCommerce stock is set to the sellable Konto stock (unexpired lots), minus units in orders that are not yet invoiced in Konto.
* When an order is invoiced, Konto draws the stock down from the lots that expire first, and the order note lists the lots used.
* Products without a SKU, or without lots in Konto, are left alone.

**Refunds become credit notes.** A refund in WooCommerce creates a Konto credit note on the original invoice:

* A full refund credits the whole invoice and cancels an unpaid bank claim.
* A partial refund credits the refunded items, shipping and fees, or spreads a refunded amount over the order's VAT rates.
* Items you restock in WooCommerce go back into the Konto lots the invoice drew from. Items you don't restock leave Konto stock unchanged.

VAT is taken from each order line (24%, 11% or 0%). Shipping and fees are invoiced as separate lines with their own VAT.

Works with the block checkout and the classic checkout, and with WooCommerce's High-Performance Order Storage.

Note: Konto users need an API key. **IS: Áskrifendur á konto.is geta virkjað vefþjónustutengingu og fengið úthlutað API lykil sem þarf til að tengja vefverslunina við Konto.**

== Installation ==

1. Install the plugin from the Plugins screen in WordPress, or upload the files to `/wp-content/plugins/woo-konto-checkout`.
2. Activate it.
3. Go to WooCommerce > Settings > Payments > Konto. Enter the username and API key from Konto (Vefþjónustuaðgangur under Áskriftir og viðbætur) and save. The settings page confirms the connection and shows the connected account.
4. Optional: turn on "Stock sync" and make sure each product's SKU equals its Konto item number.

== Frequently Asked Questions ==

= When is a kennitala required? =
Only when the customer pays with Konto, because the claim is created on their kennitala. For other payment methods it is optional. A kennitala that is entered is checked (check digit).

= What happens when there is no kennitala? =
The invoice is a cash sale: the bill-to name is "Staðgreitt" and the bill-to kennitala is the shop's own. The customer still gets the invoice by email.

= Which stock figure wins, WooCommerce or Konto? =
Konto. Change stock in Konto (add or remove lots), not in WooCommerce. The next sync overwrites WooCommerce.

= What happens with a partial refund of an unpaid bank claim? =
No credit note is created, because a credit note cannot shrink a claim that is still open. The order note says so: adjust or cancel the claim in Konto. Once the invoice is paid, "Konto: create credit notes for refunds" on the order screen creates the missing credit notes.

= Does the plugin support test mode? =
Yes. Test mode sends requests to the test server URL in the advanced settings.

== Screenshots ==

1. The checkout screen where the customer can choose an e-invoice and pay in their online bank
2. The plugin settings under WooCommerce > Settings > Payments
3. The message the customer sees when they choose Konto
4. Creating Konto invoices for orders paid by other methods

== Changelog ==

= 2.1.0 =
* New: stock sync from Konto inventory (lots), matched on SKU, every 15 minutes or on demand.
* New: invoices draw Konto lots down, earliest expiry first. The order note lists the lots used.
* New: block checkout support, both the payment method and the kennitala and XML fields.
* New: High-Performance Order Storage (HPOS) support.
* New: refunds create Konto credit notes (full or partial), and restocked items go back into their Konto lots.
* New: "Konto: create credit notes for refunds" on the order screen, for refunds without a credit note.
* New: optional automatic invoices for orders paid by other methods.
* New: "Konto: issue invoice" and "Konto: save as draft" on the order screen.
* New: due days, final due days and invoice language settings.
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
