# Menu photographs — where they came from

The public menu shows nine signature dishes with a photograph. None of these are
Marle Hotel's own pictures: they are freely-licensed photographs of the same
Filipino dishes, cropped to 640×480 and re-encoded (JPEG quality 80, WebP
quality 76). **CC BY and CC BY-SA require the photographer to be credited**, so
the page prints the credit line under each photograph from the table in
`homepage/includes/menu_public.php`, and every credit links to the Commons page.

Replacing any of these with a real photograph from the Marle kitchen is a
drop-in: keep the file name and both formats, and delete that dish's entry from
`$publicMenuPhotos` in `includes/menu_public.php` so the credit line goes away.

| Dish | File | Photographer | Licence | Source |
| --- | --- | --- | --- | --- |
| Adobo | `adobo.jpg` / `.webp` | dbgg1979 on flickr | CC BY 2.0 | [Chicken adobo.jpg](https://commons.wikimedia.org/wiki/File:Chicken_adobo.jpg) |
| Kare-Kare | `kare-kare.jpg` / `.webp` | Ymirazudis | CC BY-SA 4.0 | [Kare-kare at La Herencia Comida.jpg](https://commons.wikimedia.org/wiki/File:Kare-kare_at_La_Herencia_Comida.jpg) |
| Prawn Sinigang | `sinigang.jpg` / `.webp` | Ralff Nestor Nacor | CC BY-SA 4.0 | [Sinigang na Hipon in bowl, Aug 2024.jpg](https://commons.wikimedia.org/wiki/File:Sinigang_na_Hipon_in_bowl,_Aug_2024.jpg) |
| Lumpiang Shanghai | `lumpiang-shanghai.jpg` / `.webp` | Ralff Nestor Nacor | CC BY-SA 4.0 | [Lumpiang Shanghai, Aug 2024.jpg](https://commons.wikimedia.org/wiki/File:Lumpiang_Shanghai,_Aug_2024.jpg) |
| Chicken Inasal | `chicken-inasal.jpg` / `.webp` | Ralff Nestor Nacor | CC BY-SA 4.0 | [Chicken Inasal, Mar 2024.jpg](https://commons.wikimedia.org/wiki/File:Chicken_Inasal,_Mar_2024.jpg) |
| Sisig | `sisig.jpg` / `.webp` | Ej Afable | CC BY-SA 4.0 | [Sizzling Sisig.jpg](https://commons.wikimedia.org/wiki/File:Sizzling_Sisig.jpg) |
| Bulalo | `bulalo.jpg` / `.webp` | LReformado | CC BY 4.0 | [BULALO.jpg](https://commons.wikimedia.org/wiki/File:BULALO.jpg) |
| Halo-Halo | `halo-halo.jpg` / `.webp` | Chammy17 | CC BY-SA 4.0 | [Halo-halo at mama nang's halo-halo.jpg](https://commons.wikimedia.org/wiki/File:Halo-halo_at_mama_nang%27s_halo-halo.jpg) |
| Classic Leche Flan | `leche-flan.jpg` / `.webp` | Obsidian Soul | CC0 | [Leche flan (Philippines) 01.jpg](https://commons.wikimedia.org/wiki/File:Leche_flan_(Philippines)_01.jpg) |

Total weight: 546 KB across the nine JPEGs, 361 KB across the WebP set, both
lazy-loaded with fixed dimensions so the page never reflows while they arrive.
