# Focal Point

Seleziona un punto di fuoco per le immagini in evidenza direttamente dall'editor di Gutenberg. Il punto scelto viene iniettato come `object-position` nel rendering frontend, così l'inquadratura resta corretta anche quando l'immagine viene ritagliata da un `object-fit: cover`.

![Martino with Focal Point](https://www.salvatorecorsi.com/wp-content/uploads/2026/05/capture-20260529-082113.gif)

## Come funziona

Il plugin aggiunge un pannello **Focal Point** nella sidebar del documento (visibile solo dove c'è un'immagine in evidenza). Cliccando o trascinando sul preview si posiziona il punto di fuoco; le coordinate vengono salvate come post meta in percentuale (`0–100`).

Nel frontend il filtro su `post_thumbnail_html` inietta `object-position` nell'`<img>` della featured image. Se il punto coincide con il centro (`50% 50%`) non viene iniettato nulla, per non sporcare il markup quando non serve.

## Requisiti

- WordPress con editor a blocchi
- PHP 8.0+

## Installazione

Copia la cartella in `wp-content/plugins/` e attiva **Focal Point** dalla bacheca.

## Post type abilitati

Di default: `post`, `project`, `experiment`. Per aggiungerne altri usa il filtro `focal_point_post_types`:

```php
add_filter( 'focal_point_post_types', fn( $types ) => [ ...$types, 'product' ] );
```

## Uso nei template

Il rendering automatico copre solo l'`object-position` della featured image generata da `the_post_thumbnail()` / `post_thumbnail_html`. Per applicare il focal point altrove — ad esempio come `background-position` di un elemento con immagine di sfondo — usa le funzioni di utility:

```php
// Stringa CSS pronta: es. "30% 70%"
$pos = fp_get_position( $post_id );
echo '<div style="background-image:url(...);background-position:' . esc_attr( $pos ) . '"></div>';

// Coordinate come array: [ 'x' => 30, 'y' => 70 ]
$coords = fp_get_position_array( $post_id );
```

Entrambe accettano un `$post_id` opzionale e ricadono sul post corrente (`get_the_ID()`) se omesso. Se il focal point non è impostato tornano al centro (`50% 50%`).

## Meta registrati

| Chiave             | Tipo   | Default |
|--------------------|--------|---------|
| `_focal_point_x`   | number | `50`    |
| `_focal_point_y`   | number | `50`    |

Esposti in REST (`show_in_rest`), scrivibili da chi ha `edit_posts`.
