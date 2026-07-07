# Corsivo Focal Point

Seleziona un punto di fuoco per le immagini in evidenza direttamente dall'editor. Il punto scelto viene iniettato come `object-position` nel frontend, così l'inquadratura resta corretta anche quando l'immagine è ritagliata da un `object-fit: cover`.

Parte di **Corsivo**, la famiglia di plugin WordPress di [Salvatore Corsi](https://salvatorecorsi.dev).

![Focal Point in azione](https://www.salvatorecorsi.com/wp-content/uploads/2026/05/capture-20260529-082113.gif)

## Come funziona

Il plugin aggiunge un pannello **Focal Point** nella sidebar del documento (visibile solo dove c'è un'immagine in evidenza). Cliccando o trascinando sul preview si posiziona il punto; le coordinate sono salvate come post meta in percentuale (`0–100`).

Nel frontend il filtro su `post_thumbnail_html` inietta `object-position` nell'`<img>` della featured image. Se il punto coincide con il centro (`50% 50%`) non viene iniettato nulla.

## Post type abilitati

Di default: `post`, `project`, `experiment`. Per aggiungerne altri:

```php
add_filter( 'corsivo_focal_point_post_types', fn( $types ) => [ ...$types, 'product' ] );
```

## Uso nei template

Per applicare il focal point altrove — ad esempio come `background-position`:

```php
$pos = corsivo_focal_point_get_position( $post_id );          // "30% 70%"
$coords = corsivo_focal_point_get_position_array( $post_id ); // [ 'x' => 30, 'y' => 70 ]
```

Entrambe ricadono sul post corrente se `$post_id` è omesso, e sul centro se il punto non è impostato.

## Meta registrati

| Chiave | Tipo | Default |
|---|---|---|
| `_corsivo_focal_point_x` | number | `50` |
| `_corsivo_focal_point_y` | number | `50` |

Esposti in REST (`show_in_rest`), scrivibili da chi ha `edit_posts`.

## Requisiti

WordPress 6.5+ · PHP 8.1+ · standalone.

## Aggiornamenti

Distribuito dal canale privato `plugins.corsivo.dev` tramite **Corsivo Updater**.

## Licenza

Software proprietario — © Salvatore Corsi. Uso e ridistribuzione non consentiti senza autorizzazione.
