# Audit pred zverejnením – BTCPay Satoshi Tickets for WooCommerce

**Dátum:** 2025-03-06

## Zhrnutie

Plugin je v dobrom stave na zverejnenie. Níže sú nálezy a odporúčania.

---

## Bezpečnosť

### Dobré
- **Webhook:** Overenie `BTCPay-Sig` cez `hash_equals()`, odmietnutie pri neplatnom podpise
- **Admin:** `current_user_can('manage_woocommerce')` na všetkých admin akciách
- **Nastavenia:** `wp_verify_nonce` pri ukladaní, `check_admin_referer` pri registrácii webhooku
- **Sanitizácia:** `sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `esc_attr`, `esc_html` používané správne
- **Satflux callback:** Kontrola `current_user_can` pred zápisom credentials

### Poznámky
- **REST `/cart-tickets`:** `permission_callback: __return_true` – endpoint je verejný. Vracia len štruktúru košíka (keys, names, quantities), žiadne citlivé údaje. Prijateľné pre checkout.
- **Satflux redirect:** Žiadny nonce – pri redirecte z externej služby to nie je možné. Ochrana je cez `current_user_can` (užívateľ musí byť prihlásený).

---

## Chýbajúce / odporúčania

### 1. `.gitignore`
Odporúčané doplniť, napr.:
```
.DS_Store
Thumbs.db
*.log
.idea/
.vscode/
node_modules/
```

### 2. `index.php` v priečinkoch
Bezpečnostná best practice: prázdne `index.php` v `assets/`, `includes/`, `assets/js/`, `assets/css/` zabráni zobrazeniu výpisu súborov. Nie kritické, ale odporúčané.

### 3. Uninstall – order item meta
V `uninstall.php` sú v `$item_meta_keys` len `_satoshi_event_id`. Order items majú aj `_satoshi_ticket_type_id` a `_satoshi_recipients`. Odporúčané doplniť do zoznamu meta kľúčov na vymazanie.

### 4. WooCommerce Blocks `canMakePayment`
Implementácia `canPayWithSatoshi(context)` predpokladá, že `context` obsahuje `cart` s `items` a `extensions`. Rôzne verzie WooCommerce Blocks môžu mať inú štruktúru. Ak `context` nemá cart, platba sa skryje (`return false`), čo je bezpečné správanie.

---

## Konzistentnosť

- Text domain `btcpay-satoshi-tickets` – používa sa konzistentne
- Verzia `1.0.2` – v súbore pluginu aj v konštante
- Pravidlá PHP – `declare(strict_types=1)` všade, namespace konzistentné

---

## Dokumentácia

- README je prehľadný, inštalácia a použitie popísané
- API endpointy sú zdokumentované
- Satflux flow je opísaný

---

## Odporúčané úpravy pred zverejnením (voliteľné)

1. Pridať `.gitignore`
2. V `uninstall.php` rozšíriť `$item_meta_keys` o `_satoshi_ticket_type_id` a `_satoshi_recipients`

---

## Záver

Plugin je pripravený na zverejnenie. Bezpečnostné kontroly sú na mieste, kód je konzistentný. Voliteľné úpravy môžu byť urobené v ďalšom release.
