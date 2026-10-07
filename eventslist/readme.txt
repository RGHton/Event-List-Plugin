=== Events List ===
Contributors: brittandreatta
Tags: events, calendar, shortcode
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple events manager: an eventslist custom post type, an importer for event
data from a WordPress export file, and a shortcode that lists events in a
two-column date / details layout.

== Description ==

Events List replaces an abandoned event plugin without losing any of its data.
It provides:

* An **Events** custom post type (`eventslist`) with fields for start date, end
  date, start time, location and a link.
* An **importer** that reads a WordPress export (WXR) file and converts the old
  `el_events` entries into Events List entries, including lifting the
  registration link out of the old description text and into its own field.
* An **`[eventslist]` shortcode** that displays events in reverse chronological
  order, newest first, with a switch for upcoming, all or past events.

= Event fields =

Each event has:

* **Start date** -- required; decides the sort order and whether the event is
  upcoming or past.
* **End date** -- only needed for multi-day events. Left blank it matches the
  start date, and the date column collapses a range to "June 28th - 30th".
* **Start time** -- free text. A clock time such as `17:00` is displayed as
  "5:00 pm", while anything else (`9 am PT`, `8:30 AM PT / 11:30 AM ET`) is
  shown exactly as typed.
* **Location** -- a city, a venue, or "Online" / "Virtual". Omitted when blank.
* **Link URL** and **Link text** -- optional; the link only appears when a URL
  is set, and the text defaults to "Learn more".
* **Description** -- the main editor, typically the session or keynote title.

== Shortcode ==

`[eventslist]`

Attributes:

* `show` -- `all` (default), `upcoming` or `past`. Upcoming and past are decided
  on the end date, so a multi-day conference still counts as upcoming while it
  is running.
* `order` -- `desc` (default, newest first) or `asc` (oldest first). For an
  upcoming list, `order="asc"` puts the soonest event first.
* `limit` -- maximum number of events; `0` (default) shows all of them.
* `description` -- `yes` (default) or `no`.
* `year` -- `always` (default), `auto` (shown only for events outside the
  current year) or `never`.
* `from` -- cutoff date as `YYYYMMDD` (or `YYYY-MM-DD`). Only events starting
  on or after this date are shown; e.g. `from="20201210"` hides everything
  before December 10th 2020. Invalid dates are ignored.
* `empty` -- text shown when no events match.
* `class` -- extra CSS class on the wrapper.

Examples:

    [eventslist]
    [eventslist show="upcoming" order="asc"]
    [eventslist show="past" limit="10"]
    [eventslist show="all" description="no" year="always"]
    [eventslist from="20201210"]

== Importing ==

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

= WP-CLI =

    wp eventslist import export.xml --dry-run
    wp eventslist import export.xml
    wp eventslist import export.xml --skip-existing --status=draft

== Styling ==

The list is styled with CSS custom properties, so a theme can restyle it
without fighting specificity:

    .eventslist {
        --eventslist-date-width: 9rem;
        --eventslist-accent: #8a1f61;
        --eventslist-muted: #555;
        --eventslist-rule: #e5e7eb;
    }

Useful classes: `.eventslist-event` on each row (plus `.is-past`,
`.is-upcoming` and `.is-online`), `.eventslist-date` for the first column and
`.eventslist-details` for the second.

Below 600px the two columns stack.

== Notes ==

* Events have no public URLs of their own; they are managed in the admin and
  displayed through the shortcode. To give each event its own page, set
  `public` and `publicly_queryable` to true with the
  `eventslist_post_type_args` filter.
* Featured images are supported by the post type but are not downloaded by the
  importer, since the two-column layout has no image column.

== Changelog ==

= 1.0.2 =
* Title is 22px in #3c8396; no top margin on the description or link, and
  no margin on description paragraphs. These rules are now prefixed with
  `.eventslist` so theme heading and paragraph styles cannot override them.
* Version bump also makes browsers load the new stylesheet instead of a
  cached copy.

= 1.0.1 =
* The year is now shown on every event by default (`year="always"`); use
  `year="auto"` for the old behaviour.
* Links use the accent colour #3c8396 instead of inheriting the theme's link
  colour.
* New `from` attribute sets a cutoff date, e.g. `from="20201210"`.
* Removed the top and bottom margins inside the details column for a tighter
  layout.

= 1.0.0 =
* First release.
