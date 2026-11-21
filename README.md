# UBN Speed Shipping for WooCommerce (Livraison Plume)

Extension WooCommerce sur-mesure pour l'intégration des expéditions par colis express avec le transporteur **UBN Speed** (La Réunion).

---

## Fonctionnalités Principales

* **Calcul Dynamique des Frais de Port** : Interroge l'API UBN Hub pour proposer la livraison rapide par colis aux clients éligibles.
* **Validation des Critères d'Éligibilité** : Vérification en temps réel des catégories autorisées (Décoration, Petit électroménager), des stocks d'exposition magasin (Saint-Denis ID `105`, Saint-Pierre ID `106`), et des codes postaux éligibles à La Réunion.
* **Création Automatique des Expéditions** : Génère automatiquement l'expédition et le bordereau auprès d'UBN Speed dès le paiement de la commande (`processing`).
* **Compatibilité HPOS Native** : Enregistre les identifiants et numéros de suivi (`_ubn_tracking_number`, `_ubn_shipment_id`, `_ubn_shipment_response`) directement sur l'objet `WC_Order` (`wp_wc_orders_meta`) et dans `wp_postmeta`.
* **Disambiguation des Codes Postaux** : Résolution automatique des communes partagées (ex: code postal `97434` entre *La Saline Les Bains* et *Saint Gilles Les Bains*).
* **Impression des Bons de Livraison** : Génération sécurisée de bordereaux scannables avec QR Code intégré (`ubn-delivery-note.php`).
* **Mises à Jour Automatisées** : Intégration native de `PluginUpdateChecker` connecté à GitHub.

---

## Configuration

Les paramètres peuvent être définis soit via l'administration WordPress (**UBN Speed > Réglages**), soit directement dans `wp-config.php` pour une sécurité accrue :

```php
define('UBN_API_BASE', 'https://ubn-speed.re/wp-json/ubn-api-hub-re/v1/distant');
define('UBN_API_KEY', 'UBN-PARTNER-XXXX');
define('UBN_HMAC_SECRET', 'UBN-SECRET-XXXX');
define('UBN_PARTNER_ID', '37');
define('UBN_SOURCE_SITE', 'https://conforama.re');
```

---

## Historique des Versions

* **v1.4.4** :
  * **Création d'expédition asynchrone & Retries automatiques intelligents** : passage du déclenchement `processing` sur Action Scheduler (`ubn_process_shipment_async` + repli WP-Cron) avec jusqu'à 4 tentatives automatiques sur erreurs réseau / timeouts / HTTP 5xx / `receiver_error` (**immédiat -> +5 min -> +30 min -> +2 h**), verrou anti-doublon (`transient` 60s) et arrêt automatique si la commande est annulée/remboursée.
  * **Anti-bruit sur les alertes e-mail admin** : l'alerte `[UBN] Shipment Creation Failed` n'est envoyée qu'en cas d'échec définitif des 4 tentatives automatiques ou immédiatement dès le 1er essai s'il s'agit d'une erreur bloquante non retentable (`4xx` validation / code postal / wallet).
  * **Correction de la conservation d'erreur `_ubn_shipment_error`** : remplacement du hook trop large `woocommerce_update_order` (qui effaçait silencieusement l'erreur lors des `$order->save()` en tâche de fond : IPN Alma, CAPI, sync ERP) par `woocommerce_process_shop_order_meta` restreint aux sauvegardes manuelles dans l'admin.
  * **Compatibilité HPOS intégrale & Traçabilité** : déclaration officielle `custom_order_tables`, lecture hybride HPOS/Legacy sur l'ensemble des écrans/rapports/BL, notes privées WooCommerce sur chaque tentative et affichage de la prochaine tentative programmée dans *UBN Orders* et sur la fiche commande.
* **v1.4.3** :
  * Ajout du fallback `_woocommerce_multi_inventory_inventory` dans `ubn_stock_ok()` pour le mode multi-magasins.
* **v1.4.2** :
  * Boutons d'action directe de relance et d'effacement d'erreur sur la fiche commande et bannières de notification admin.
* **v1.4.1** :
  * Ajout de la compatibilité native HPOS (*High-Performance Order Storage*).
  * Prise en charge des constantes d'environnement `wp-config.php` comme fallback automatique.
  * Levée d'ambiguïté pour le code postal `97434` (*La Saline Les Bains* / *Saint Gilles Les Bains*).
* **v1.4** : Normalisation stricte des couples ville / code postal selon le référentiel UBN Réunion.
* **v1.3** : Intégration du moteur `PluginUpdateChecker` via GitHub.
* **v1.2** : Version initiale avec support multi-magasins et synchronisation des stocks d'exposition.
