# Corsivo Focal Point

Seleziona un punto focale per l’immagine in evidenza direttamente dall’editor e applica il relativo `object-position` al markup frontend.

Il plugin è autonomo: usa esclusivamente API e componenti WordPress core. Le integrazioni esterne sono disattivate per default e non introducono dipendenze obbligatorie.

![Focal Point in azione](https://www.salvatorecorsi.com/wp-content/uploads/2026/05/capture-20260529-082113.gif)

## Configurazione

La pagina **Impostazioni → Focal Point** permette di scegliere i post type abilitati. Sono disponibili solo quelli con interfaccia amministrativa e supporto all’immagine in evidenza; il default per una nuova installazione è `post`.

Il filtro pubblico resta disponibile:

```php
add_filter( 'corsivo_focal_point_post_types', function ( $post_types ) {
	$post_types[] = 'portfolio';
	return $post_types;
} );
```

I post type selezionati funzionano sia con l’editor a blocchi sia con l’editor classico, anche quando la scelta dell’editor è specifica del singolo contenuto. Il picker usa il componente nativo `FocalPointPicker` di WordPress. Per esporre i meta nell’editor a blocchi viene abilitato il supporto core `custom-fields` sui soli post type selezionati.

## Persistenza

| Chiave | Tipo | Dominio | Default |
|---|---|---|---|
| `_corsivo_focal_point_x` | integer | `0–100` | `50` |
| `_corsivo_focal_point_y` | integer | `0–100` | `50` |
| `_corsivo_focal_point_attachment_id` | integer | ID media | `0` |

Le coordinate sono validate anche fuori REST e sono autorizzate tramite la capability dinamica `edit_post` del singolo contenuto. Il riferimento all’attachment impedisce che il focal point di una vecchia featured image venga applicato a quella nuova.

Ogni aggiornamento dei tre meta è coordinato: i valori singoli vengono deduplicati e, in caso di errore parziale, viene tentato e verificato il ripristino dello stato precedente.

## Rendering e API

Il filtro `post_thumbnail_html` modifica esclusivamente il primo tag `img` tramite `WP_HTML_Tag_Processor`. Un valore centrale non aggiunge markup.

Per template con immagini di sfondo:

```php
$position = corsivo_focal_point_get_position( $post_id );
$coordinates = corsivo_focal_point_get_position_array( $post_id );
```

Il risultato è rispettivamente una stringa come `0% 75%` e un array come `[ 'x' => 0, 'y' => 75 ]`.

Dopo un aggiornamento viene emesso `corsivo_focal_point_position_updated` con ID del post, nuovo stato e stato precedente.

## Integrazioni opzionali

Il modulo WooCommerce abilita `product` e applica il focal point alle immagini di catalogo e alla sola immagine principale delle gallery classiche e a blocchi. Le immagini secondarie non ereditano coordinate non proprie. Sui prodotti variabili la gallery non viene modificata, perché WooCommerce sostituisce la sorgente dell’immagine mantenendone gli stili inline.

Il modulo WPML usa una policy copy-once: inizializza una traduzione manuale o automatica priva di coordinate usando la lingua originale, quindi la lascia modificabile in modo indipendente e la associa alla propria featured image. `wpml-config.xml` impedisce alle policy generiche di WPML di risincronizzare in seguito i tre meta gestiti dal plugin.

Yoast SEO non richiede un modulo. `object-position` è CSS e non può modificare il crop fisico dell’immagine dichiarata nei meta Open Graph o Twitter.

## Storico

I meta seguono revisioni e autosave nativi sui post type che li supportano, incluso il valore `0`. Il confronto mostra coordinate e attachment; il ripristino riporta insieme i tre meta ma non modifica la featured image, che WordPress non include nelle revisioni. Se il media non coincide, la posizione restaurata resta inattiva. Ogni snapshot usa un checksum di schema `1`; snapshot incompleti o alterati vengono rifiutati.

Non viene creata una tabella log separata: autore, data, confronto e ripristino sono già coperti dalle revisioni, senza duplicare dati né introdurre retention aggiuntiva.

## Disinstallazione

I dati sono conservati per default. L’opzione **Elimina coordinate e impostazioni quando il plugin viene disinstallato** abilita la pulizia esplicita e cache-safe. In multisite il flag è intenzionalmente per-sito e l’attivazione network inizializza ogni sito in modo indipendente.

## Requisiti

WordPress 6.5+ · PHP 8.1+

## Aggiornamenti

Distribuito dal canale privato `plugins.corsivo.dev`. L’header `Update URI` impedisce associazioni accidentali con plugin omonimi su WordPress.org; Corsivo Updater resta facoltativo.

## Licenza

Software proprietario — © Salvatore Corsi. Uso e ridistribuzione non consentiti senza autorizzazione.
