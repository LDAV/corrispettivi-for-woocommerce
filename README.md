# Corrispettivi for WooCommerce

Un aiuto per la compilazione del Registro dei Corrispettivi derivanti da vendite WooCommerce.

## Plugin Info

- Contributors: labdav
- Tags: WooCommerce, Corrispettivi, registro dei corrispettivi, ldav
- Requires at least: 4.4
- Requires PHP: 8.0
- Tested up to: 6.9
- Stable tag: 0.8.2
- License: GPLv3 or later
- License URI: http://www.gnu.org/licenses/gpl.html
- Donate link: https://ldav.it/plugin/corrispettivi-for-woocommerce/

## Description

Il registro dei corrispettivi è il registro contabile dove bisogna annotare le operazioni attive giornaliere (con e senza IVA).

Corrispettivi per WooCommerce consente di generare automaticamente un elenco dei corrispettivi giornalieri distinto per mese e anno a partire dagli ordini di WooCommerce che hanno ricevuto un pagamento.
Sono inclusi nel calcolo gli ordini completati, e opzionalmente quelli in lavorazione, in sospeso o rimborsati.

L'elenco dei corrispettivi è distinto per mese e anno, e riporta per ciascuna giornata:

- il totale dei corrispettivi giornalieri;
- i totali distinti per aliquota IVA;
- i totali delle operazioni non imponibili o esenti;
- i totali delle operazioni non soggette a registrazione IVA;
- il numero (da/a) delle fatture emesse.

Il plugin ricava i dati degli ordini fatturati da [PDF Invoices & Packing Slips for WooCommerce](https://it.wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) oppure da [WooCommerce Italian Add-on Plus](https://ldav.it/shop/plugin/woocommerce-italian-add-on/), se presenti.

Gli elenchi sono esportabili in formato Excel (`.xlsx`) o CSV.

### Translations in your language

- English
- Italian (it_IT)
- Our plugin is fully [WPML Compatible](https://wpml.org/documentation/getting-started-guide/string-translation/).

## Installation

Steps to install this plugin.

Be sure you're running WooCommerce 4.4+

1. In the downloaded zip file, there is a folder with name `corrispettivi-for-woocommerce`
2. Upload the `corrispettivi-for-woocommerce` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the `Plugins` menu in WordPress
4. Read the usage instructions in the description.

## Frequently Asked Questions

### Posso inviare automaticamente il Registro Corrispettivi all'Agenzia delle Entrate?

Non ci risulta al momento un meccanismo codificato che consenta di effettuare questo invio in modo automatico.

### Come aggiungo altri stati ordine da includere nel filtro?

Puoi usare il filtro `corrispettivi_for_woocommerce_default_wc_status` nel file `functions.php` del tema (meglio child theme):

```php
add_filter('corrispettivi_for_woocommerce_default_wc_status', function($statuses) {
	$statuses[] = 'wc-custom-status';
	$statuses[] = 'wc-shipped';
	return array_values(array_unique($statuses));
});
```

Note:

- Usa gli slug WooCommerce completi, ad esempio `wc-completed`.
- Il plugin mantiene sempre `wc-completed` tra gli stati selezionati.

## Screenshots

1. La tabella dei corrispettivi del mese.

## Changelog

### 0.8.4 - 2026/02/22

- Aggiunto filtro `corrispettivi_for_woocommerce_default_wc_status` per personalizzare gli stati ordine disponibili
- Migliorata la gestione degli status ordine selezionabili (`wc-completed` sempre incluso)
- Migliorata la sicurezza su input utente e query SQL
- Migliorato escaping output HTML/JS
- Migliorata internazionalizzazione (text domain e placeholder ordinati nelle stringhe traducibili)
- Migliorata esportazione in Excel o csv
- Verifica compatibilità con WooCommerce 10.5.2

### 0.7.1 - 2023/08/10

- Verifica compatibilità con WooCommerce 8.0.1
- Verifica compatibilità con WooCommerce High-Performance Order Storage (HPOS)

### 0.6 - 2023/06/09

- Verifica compatibilità con WooCommerce 7.7.2
- Verifica compatibilità con WooCommerce High-Performance Order Storage (HPOS)

### 0.5 - 2023/05/05

- Possibilità di selezionare lo status degli ordini da includere nel calcolo

### 0.4 - 0.4.1 - 2022/09/19 - 2022/12/07

- small bug fixes
- Verifica funzionalità in assenza di WooCommerce PDF Invoices Italian Add-on o WooCommerce Italian Add-on
- Possibilità di esportare giorni senza pagamenti

### 0.3 - 2021/09/21 - 2022/11/05

- small bug fixes
- Verifica compatibilità con WooCommerce

### 0.2 - 2021/09/02

- small bug fixes

### 0.1 - 2021/07/27

- Initial version of plugin

## Upgrade Notice

### 0.8 - 2026/02/20

- Aggiunto filtro `corrispettivi_for_woocommerce_default_wc_status` per personalizzare gli stati ordine disponibili
- Verifica compatibilità con WooCommerce 10.5.2
- È consigliato l'upgrade.

## Support

If you find any issue, [let us know here!](https://wordpress.org/support/plugin/corrispettivi-for-woocommerce)

## Contributions

Help us to translate the plugin in your language. You can [do it here](https://translate.wordpress.org/projects/wp-plugins/corrispettivi-for-woocommerce).
