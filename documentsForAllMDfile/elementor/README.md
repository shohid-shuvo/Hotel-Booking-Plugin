# Aristocrats dynamic room card

The card uses a square photograph, white background, deep green text and a gold accent. Every corner has a zero radius. The image, title, description, capacity, amenities, price and room link come from the current `arc_room` post. It does not create bookings or change checkout.

## Files and requirements

- `arc-room-card-section.json`: Saved **Full Width Section** for a loop widget that explicitly accepts saved section templates and preserves the current post context.
- `arc-room-card-loop-item.json`: The same legacy Section/Column design as a native Elementor **Loop Item** for Elementor Pro **Loop Grid**, when available in your installed version.
- `preview/room-card-preview.html`: Illustrative visual preview with sample content, not a live Elementor rendering. Sample photos and rates are absent from the imported JSON files.

Both templates use only legacy **Section > Column > Widget** elements. **Keep Elementor Flexbox Containers disabled.** They need Elementor Pro dynamic tags and Custom CSS, and the active `arc-hotel-core` plugin. Import only the variant required by your widget. The ordinary Elementor Posts widget does not accept this custom Loop Item.

Every outer and inner section uses **Content Width: Full Width**, with no default column gutter. Full width fills the current loop cell or parent, not the entire screen; **Stretch Section remains off**. The legacy `.elementor-container` HTML wrapper is part of a Section and is not a Flexbox Container element. Scoped CSS handles alignment without enabling the Elementor Containers feature.

## Import and use

1. Go to **WordPress Admin > Templates > Saved Templates > Import Templates**, select the JSON and import it.
2. Edit the imported template with Elementor. In **Preview Settings**, choose **Hotel Room (`arc_room`)** and select a published synced room. Apply the preview. The export deliberately contains no site-specific preview post ID.
3. For a Loop Post widget that accepts saved sections, choose **Aristocrats - Room Card (Full Width Section)** and query **Hotel Rooms**. Inserting the section once on a page does not create a loop.
4. Recommended grid: **3 desktop / 2 tablet / 1 mobile** columns, with **24 / 20 / 16 px** gaps. Enable equal item heights if that control is available in your Elementor version.
5. If using native **Loop Grid**, choose **Aristocrats - Room Card (Full Width Loop Item)** and set **Query > Source** to **Hotel Rooms**. For a room archive template, use **Current Query** when the archive query already contains `arc_room` posts. Loop Builder availability depends on your Elementor Pro version and enabled features.
6. Check two rooms with different prices and amenities on the actual page. Verify that the title, photo, capacities, price and links all change per room. Also check one room with zero children, one with a long title, and mobile layout.

## Field mapping

| Card content | Dynamic source |
| --- | --- |
| Photograph | Post Featured Image, synced to WordPress by the plugin |
| Name | Post Title |
| Description | Post Excerpt, with post-content fallback and an 18-word limit |
| Adults | Post Custom Field, manual key `_arc_max_adults` |
| Children | Post Custom Field, manual key `_arc_max_children`; zero displays as `0 Children` |
| Amenities | `[arc_room_amenities]`, showing the first three real entries |
| Starting rate | `[arc_room_meta]`, displaying only its existing formatted price block |
| Photo, title and button links | Post URL |

The price is the API-synced category starting rate, not a date-specific quote. Actual stay pricing and availability remain in the room booking flow. No ratings, room dimensions, inclusions or scarcity claims are invented.

## Editing and troubleshooting

- Edit content widgets normally in Elementor. **Outer Section > Advanced > Custom CSS** holds the scoped card styles and controls final typography, spacing, colors, hover, focus and square corners. The body and footer are sibling inner sections, with no nested inner section inside another inner section.
- To show more amenities, change `.arc-amenities-grid li:nth-child(n+4)` in the root Custom CSS. Removing that rule shows all real amenities.
- If the photo is blank, check that the synced room has a WordPress featured image and run room sync after fixing media download errors.
- If the price is blank, confirm the plugin shortcode is registered and `_arc_from_price` is greater than zero.
- If every card repeats the same price or amenities, the loop widget is not preserving the current room post. Use native Loop Grid or fix that widget's post context.
- This revision replaces the earlier Container-based exports. Import the revised file and select its new **Full Width** template name in your loop widget. The earlier imported template does not update automatically when a local JSON file changes.
- If the Loop Item variant is listed as a generic section after import, confirm Loop Builder is available and enabled in your Elementor Pro installation, or use the Section variant in your saved-section loop widget.
- After publishing, regenerate Elementor CSS/data and clear the page cache if old styling remains.

The JSON structure and dynamic tag settings have been checked locally against Elementor source. Import and rendering in the live WordPress site still need to be verified.
