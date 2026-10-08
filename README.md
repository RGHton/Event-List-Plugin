# Events List

A simple WordPress events plugin: an **Events** custom post type, an importer
for event data from a WordPress export file, and an `[eventslist]` shortcode
that lists events in a two-column date / details layout.

| | |
|---|---|
| **Version** | 1.0.5 |
| **Requires WordPress** | 5.8 or later (tested up to 7.1) |
| **Requires PHP** | 7.4 or later |
| **License** | [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) |

Events List replaces an abandoned event plugin without losing any of its data.
It provides:

- An **Events** custom post type (`eventslist`) with fields for start date, end
  date, start time, location and a link.
- An **importer** that reads a WordPress export (WXR) file and converts the old
  `el_events` entries into Events List entries, including lifting the
  registration link out of the old description text and into its own field.
- An **`[eventslist]` shortcode** that displays events newest first, with a
  switch for upcoming, all or past events.

## Installation

1. Zip the `eventslist` folder so that `eventslist/` is at the top of the zip
   (GitHub's **Download ZIP** wraps everything in an extra folder, so re-zip
   just the `eventslist` folder from it).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the zip.
   When updating, choose **Replace current with uploaded**.
3. Activate **Events List**.

## Event fields

| Field | Notes |
|---|---|
| **Start date** | Required. Decides the sort order and whether the event is upcoming or past. |
| **End date** | Only needed for multi-day events. Left blank it matches the start date, and the date column collapses a range to "June 28th - 30th". |
| **Start time** | Free text. A clock time such as `17:00` is displayed as "5:00 pm"; anything else (`9 am PT`, `8:30 AM PT / 11:30 AM ET`) is shown exactly as typed. |
| **Location** | A city, a venue, or "Online" / "Virtual". Omitted when blank. |
| **Link URL** / **Link text** | Optional. The link only appears when a URL is set; the text defaults to "Learn more". |
| **Description** | The main editor, typically the session or keynote title. |

## Shortcode

```
[eventslist]
```

| Attribute | Values | Default | Description |
|---|---|---|---|
| `show` | `all`, `upcoming`, `past` | `all` | Which events to list. Upcoming and past are decided on the end date, so a multi-day conference still counts as upcoming while it is running. |
| `order` | `desc`, `asc` | `desc` | `desc` is newest first. For an upcoming list, `asc` puts the soonest event first. |
| `limit` | number | `0` | Maximum number of events; `0` shows all of them. |
| `description` | `yes`, `no` | `yes` | Show the description under the title. |
| `year` | `always`, `auto`, `never` | `always` | `auto` shows the year only for events outside the current year. |
| `from` | `YYYYMMDD` or `YYYY-MM-DD` | none | Cutoff date: only events starting on or after it are shown. Invalid dates are ignored. |
| `empty` | text | built-in message | Text shown when no events match. |
| `class` | CSS class names | none | Extra classes on the wrapper. |

Examples:

```
[eventslist]
[eventslist show="upcoming" order="asc"]
[eventslist show="past" limit="10"]
[eventslist show="all" description="no" year="auto"]
[eventslist from="20201210"]
```

`from="20201210"` hides every event that starts before December 10th 2020.

## Importing

1. Activate the plugin.
2. Go to **Events > Import**.
3. Upload the `.xml` export file, or paste the path to a copy already on the
   server (useful when the file is too big for a browser upload).
4. Press **Dry run** first. Nothing is written; you get a table of every event
   with the date, time, location and link exactly as they would be saved.
5. When the preview looks right, press **Run import**.

Events are matched on their original ID, so the import can be run again safely:
existing events are updated rather than duplicated. Choose "Leave them alone and
skip" to protect events you have since edited by hand.

The original, unmodified description from the export is kept in the
`eventslist_source_content` meta field, so nothing from the file is lost.

### WP-CLI

```sh
wp eventslist import export.xml --dry-run
wp eventslist import export.xml
wp eventslist import export.xml --skip-existing --status=draft
```

## Styling

Colours and spacing are CSS custom properties, so a theme can restyle the list
without fighting specificity:

```css
.eventslist {
    --eventslist-date-width: 9rem;
    --eventslist-accent: #3c8396; /* link colour (default) */
    --eventslist-muted: #555;
    --eventslist-rule: #e5e7eb;
}
```

Useful classes:

- `.eventslist-event`: each row, plus `.is-past`, `.is-upcoming` and `.is-online`
- `.eventslist-date`: the first column, holding `.eventslist-date-block`
  (`.eventslist-date-day`, `.eventslist-date-year`)
- `.eventslist-details`: the second column (`.eventslist-title`,
  `.eventslist-meta`, `.eventslist-description`, `.eventslist-link`)

Below 600px the two columns stay side by side, with a narrower (100px) date
block and a smaller gap.

## Notes

- Events have no public URLs of their own; they are managed in the admin and
  displayed through the shortcode. To give each event its own page, set
  `public` and `publicly_queryable` to true with the
  `eventslist_post_type_args` filter.
- Featured images are supported by the post type but are not downloaded by the
  importer, since the two-column layout has no image column.

## Development

The `tests` folder runs without WordPress (PHP CLI only):

```sh
php tests/unit.php
php tests/preview.php path/to/export.xml > preview.html
```

`preview.php` renders the real shortcode output for the events in an export
file, so the layout can be checked in a browser before installing the plugin.

## Changelog

### 1.0.5
- On screens below 600px the date and details columns no longer stack: they
  stay side by side, with a 100px date block, a 112px date column and a
  0.75rem gap.

### 1.0.4
- The date block is now a fixed 120px wide (was at least 80px), with 6px
  padding on all sides.

### 1.0.3
- The day and year are wrapped in a new `.eventslist-date-block`: a grey
  (#e6e6e6) box, at least 80px wide, with centred text in #666666.
- The year is now 1.125rem, the same size as the day.
- Below 600px the day and year stay stacked inside the block.

### 1.0.2
- Title is 22px in #3c8396; no top margin on the description or link, and no
  margin on description paragraphs. These rules are prefixed with
  `.eventslist` so theme heading and paragraph styles cannot override them.
- Version bump also makes browsers load the new stylesheet instead of a cached
  copy.

### 1.0.1
- The year is now shown on every event by default (`year="always"`); use
  `year="auto"` for the old behaviour.
- Links use the accent colour #3c8396 instead of inheriting the theme's link
  colour.
- New `from` attribute sets a cutoff date, e.g. `from="20201210"`.
- Removed the top and bottom margins inside the details column for a tighter
  layout.

### 1.0.0
- First release.
