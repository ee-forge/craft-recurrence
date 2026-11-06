# Recurrence for Craft CMS 5

Recurring dates for **ordinary entries**. Add a Recurrence field to any entry type and the entry gets a schedule: one date, a repeat rule, or a list of dates, with per-date cancellations, reschedules and notes. Entries stay entries, so Matrix, multi-site, drafts, SEOmatic, Feed Me and Craft upgrades work as usual.

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Settings](#settings)
- [Templating](#templating)
- [Dated URLs](#dated-urls)
- [Calendar files (ICS)](#calendar-files-ics)
- [Importing](#importing)
- [Daylight saving time](#daylight-saving-time)
- [Gotchas](#gotchas)
- [Console commands](#console-commands)

## Features

- **A Recurrence field type** with a rule builder and a live preview of upcoming dates in the control panel. A schedule has:
  - a start and end time, or all-day dates (multi-day events are fine);
  - an optional repeat rule: daily, weekly on chosen weekdays, monthly on day N / the Nth weekday / the last weekday, yearly, every N days/weeks/months/years, or a list of specific dates;
  - an end: never, on a date, or after N times;
  - extra dates and skipped dates.
- **Per-date overrides**: cancel a date, reschedule it, or attach a note to it. Cancelled dates are left out of listings by default, and can be included and labelled when you want them.
- **An occurrences table** with one row per date, so listing, paging and counting dates across entries is plain SQL, and works with `{% paginate %}` and Sprig.
- **`occursBetween()`** on entry queries, to find entries with a date in a range.
- **A URL per date**, `{entry-uri}/{yyyy-mm-dd}`, rendered with the entry's own template, once you add a route.
- **ICS calendar files**: a download per entry, and a subscribable feed per section, with time zones, RRULEs, exceptions and overrides written so calendar apps treat each entry as one series.
- **Time-zone-safe**: schedules store wall-clock times with their IANA zone, so a 7 pm event stays at 7 pm across DST changes.
- **Import-friendly**: the field accepts RFC 5545 rule strings (e.g. from Feed Me).

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- A running queue (settings changes rebuild the occurrences table in a queue job)
- A daily cron job (see step 4 below)

## Installation

1. Require the plugin and install it:

   ```sh
   composer require ee-forge/craft-recurrence
   php craft plugin/install recurrence
   ```

   Or install it from **Settings → Plugins** after the `composer require`.

2. Create a **Recurrence** field and add it to an entry type's field layout. The field has no settings of its own; its defaults live in the [plugin settings](#settings). A layout can hold **only one** Recurrence field.

3. If you're adding the field to a layout that already has entries, or importing entries directly into the database, write their dates to the occurrences table:

   ```sh
   php craft recurrence/occurrences/rebuild [--section=events]
   ```

   Entries saved through Craft afterwards keep their rows up to date on their own.

4. Add a daily cron job so open-ended series keep extending to the horizon, and old dates fall out of the window:

   ```cron
   0 3 * * * php /path/to/craft recurrence/occurrences/extend
   ```

5. Optionally, [route dated URLs](#dated-urls) and link to the [calendar files](#calendar-files-ics).

## Settings

Settings are shared by all Recurrence fields and live in **Settings → Plugins → Recurrence**. They're stored in project config, so they deploy with `craft up`.

### Occurrences

| Setting | Key | Default | What it does |
| --- | --- | --- | --- |
| Horizon | `horizonMonths` | `18` | How many months ahead open-ended series are stored in the occurrences table. Series with an end date or count are stored to their end. |
| Pruning | `pastMonths` | `12` | How many months of past dates stay in the occurrences table. |
| Maximum Occurrences Per Entry | `maxOccurrences` | `500` | Hard cap on dates per entry (1–10,000), whether stored or expanded on the fly. Upcoming dates take priority; see [Limits and windows](#limits-and-windows). |

Changing any of these queues a job that rebuilds the whole occurrences table. That happens both on a CP save and when `craft up` applies a deployed change.

### Calendar files

| Setting | Key | Default | What it does |
| --- | --- | --- | --- |
| Calendar description | `icsDescriptionSource` | none | Where each event's `DESCRIPTION` comes from: blank (none), a field UID, or `template`. |
| Calendar description template | `icsDescriptionTemplate` | | Object template used when the source is `template`. |
| Calendar location | `icsLocationSource` | none | Where each event's `LOCATION` comes from: blank (none), a field UID, or `template`. |
| Calendar location template | `icsLocationTemplate` | | Object template used when the source is `template`. |
| Feed name | `icsFeedName` | `{section}` | Calendar name of section feeds; `{section}` becomes the section's name. |

Supported field sources:

- **Description**: Plain Text, or CKEditor (paragraphs, line breaks and list items carry over as plain text).
- **Location**: Plain Text; Addresses (the formatted address, plus `GEO` coordinates so calendar apps can pin it); or Entries (the related entry's title).

The field list only shows fields in layouts that also have a Recurrence field. Entries whose layout lacks the chosen field get a blank.

**Custom template…** renders an object template against each entry, for anything else (e.g. an address on a related venue). HTML is converted to plain text. A template error leaves the value blank and logs a warning rather than breaking the feed.

### Config file

Any setting can be pinned in `config/recurrence.php`, which locks it in the CP. Field sources are field **UIDs**, not handles:

```php
<?php

return [
    'horizonMonths' => 36,
    'icsDescriptionSource' => '7cb2ace3-0114-4231-a527-143318b9641d',
    'icsLocationSource' => 'template',
    'icsLocationTemplate' => "{{ object.venue.one().title ?? '' }}",
];
```

Like any Craft config file, it can be multi-environment (`'*' => [...]`, `'production' => [...]`).

## Templating

### An entry's schedule

On an entry, the field value is a `Schedule` (or `null` when empty):

```twig
{% set schedule = entry.eventSchedule %}

{{ schedule }}                       {# same as schedule.summary: "Weekly on Wed at 7:00 PM" #}
{{ schedule.isRecurring }}           {# more than one date #}
{{ schedule.isOpenEnded }}           {# repeats with no end #}

{% set next = schedule.next() %}     {# next date that hasn't ended #}
{% set last = schedule.previous() %} {# latest date that has started, for finished series #}

{% for occurrence in schedule.upcoming(6) %}…{% endfor %}
{% for occurrence in schedule.upcoming(6, true) %}…{% endfor %}         {# cancelled dates included #}
{% for occurrence in schedule.between(now, now|date_modify('+1 month')) %}…{% endfor %}
```

Cancelled dates are left out everywhere by default. `next(includeCancelled)`, `previous(includeCancelled)`, `upcoming(limit = 10, includeCancelled)` and `between(from, to, limit, includeCancelled)` all take `includeCancelled` (default `false`) to bring them back, e.g. to show "Cancelled" in a list.

If you don't know the field's handle (e.g. in a shared partial), use `craft.recurrence.schedule(entry)`.

### An occurrence

Every date is an `Occurrence`:

| Property | |
| --- | --- |
| `start`, `end` | Actual start and end (after any reschedule), as dates in the schedule's time zone. |
| `originalStart` | Start before any reschedule. |
| `allDay` | Whether it's an all-day date. |
| `cancelled` | Won't happen. Cancelled dates are only returned when you include them, and on their own dated URL. |
| `rescheduled` | Whether an override moved it. |
| `note` | The editor's note for this date, or `null`. |
| `entry` / `element` | The entry it belongs to. |
| `url` | `{entry-url}/{urlKey}`, for routes of the [default shape](#dated-urls). |
| `urlKey` | The key used in its URL, from its actual date. |
| `key` | The key overrides are stored under, from its original date (the RFC 5545 RECURRENCE-ID). |
| `isPast`, `isOngoing`, `isMultiDay` | Convenience checks against the current time. |

```twig
{{ occurrence.start|date('D, M j') }}{% if not occurrence.allDay %} {{ occurrence.start|time('short') }}–{{ occurrence.end|time('short') }}{% endif %}
{% if occurrence.isMultiDay %} to {{ occurrence.end|date('D, M j') }}{% endif %}
{% if occurrence.cancelled %}<strong>Cancelled</strong>{% endif %}
{% if occurrence.rescheduled %}Moved from {{ occurrence.originalStart|date('M j') }}{% endif %}
{{ occurrence.note }}
```

### Listing dates across entries

`craft.recurrence.occurrences()` queries the occurrences table:

```twig
{# Upcoming dates across a section, 12 per page #}
{% paginate craft.recurrence.occurrences()
    .section('events')
    .from(now)
    .includeCancelled()
    .with(['featureImage'])
    .limit(12) as pageInfo, occurrences %}

{% for occurrence in occurrences %}
    <a href="{{ occurrence.url }}">{{ occurrence.entry.title }}</a>
    {{ occurrence.start|date('D, M j') }}
    {% if occurrence.cancelled %}Cancelled{% endif %}
{% endfor %}

{{ pageInfo.total }} dates
```

The query is a regular `craft\db\Query` whose rows come back as `Occurrence` models, so `{% paginate %}`, `sprig.paginate(query, page)`, `batch()` and plain `{% for occurrence in query %}` loops all work.

| Method | |
| --- | --- |
| `from(date)` | Dates that haven't ended by then (ongoing ones included). |
| `to(date)` | Dates that start before then. |
| `section()`, `type()`, `relatedTo()`, `search()`, `status()`, `id()`, … | Any entry query param; status, post/expiry dates and permissions apply as in a normal entry query. |
| `site(handle\|id)`, `siteId(id)` | The site; defaults to the current one. |
| `with(...)` | Eager-load relations on the entries. |
| `includeCancelled()` | Include cancelled dates, which are left out by default. |
| `unique()` | One result per entry: its first matching date (its last, with `reverse()`). |
| `reverse()` | Latest first, e.g. for an archive. |
| `field(handle)` | One Recurrence field; rarely needed. |
| `limit()`, `offset()`, `all()`, `one()`, `count()`, `exists()` | As on any query. |

Criteria can also be passed as an object: `craft.recurrence.occurrences({section: 'events', from: now, limit: 12})`.

Date strings are read in the site's time zone, like Craft's own date params.

### Filtering entries by date

Entry queries gain `occursBetween(from, to)`, matching entries with at least one **non-cancelled** date overlapping the range. Either bound may be `null`.

```twig
{% set thisWeek = craft.entries
    .section('events')
    .occursBetween(now, now|date_modify('+7 days'))
    .all() %}
```

This returns entries, not dates, and doesn't order them by date; use `craft.recurrence.occurrences().unique()` when you want entries in date order.

## Dated URLs

Each date can have its own page at `{entry-uri}/{key}`. The key is the date's actual `yyyy-mm-dd`, plus `-HHMM` when two of the series' dates start on the same day. The plugin doesn't route these itself, since only the site knows which URLs it wants to give up. Add a route per section in `config/routes.php`, pointing at the plugin's controller:

```php
return [
    '<uri:events/[^/]+>/<key:\d{4}-\d{2}-\d{2}(?:-\d{4})?>' => 'recurrence/occurrence/view',
];
```

- `uri` must capture the entry's full URI, as its section's URI format produces it: `events/[^/]+` for `events/{slug}`, `calendar/.+` for a nested structure under `calendar/`.
- `key` must use the pattern above.
- `occurrence.url` builds `{entry-url}/{key}`, so links work with routes of that shape. For any other shape, e.g. `events/{slug}/on/{key}`, build links yourself: `url(entry.uri ~ '/on/' ~ occurrence.urlKey)`.

The controller renders the entry's own template with an extra `occurrence` variable, so one template serves both the entry URL and its dated URLs:

```twig
{% set occ = occurrence ?? entry.eventSchedule.next() ?? entry.eventSchedule.previous() %}
```

It sets the entry as the matched element before rendering, so SEOmatic and anything else that reads `craft.app.urlManager.matchedElement` sees the entry as on its own URL.

How dated URLs behave:

- Past dates still render, even after they've been pruned from the occurrences table.
- Dates that were never part of the series return 404.
- One-off entries 301 to the entry URL.
- Entries without a template for the site, or whose section routes to a custom controller, return 404.
- Rescheduling a date changes its URL to the new date (see [Gotchas](#gotchas)).

`{% cache %}` keys on the path, so each dated URL is cached separately. On the bare entry URL, expire the cache when the date it shows ends: `{% cache until occ.end %}`.

### Your own template

For full control, route to your own template instead and load the date with `craft.recurrence.occurrence(entry, key)`, which returns `null` when no date has that key:

```twig
{% set occ = craft.recurrence.occurrence(entry, key) %}
{% if not occ %}{% exit 404 %}{% endif %}
```

A template route has no matched element, so plugins that read it don't see the entry. That includes SEO plugins, which usually load their meta before the template's own code runs. With SEOmatic, load the entry's meta yourself, before any output:

```twig
{% do seomatic.helper.loadMetadataForUri(entry.uri) %}
```

Routes pointing at `recurrence/occurrence/view` don't need this.

## Calendar files (ICS)

```twig
<a href="{{ craft.recurrence.icsUrl(entry) }}">Add to calendar</a>
<a href="{{ craft.recurrence.feedUrl('events') }}">Subscribe</a>
<a href="{{ craft.recurrence.feedUrl('events')|replace('/^https?:/', 'webcal:') }}">Subscribe (webcal)</a>
```

- `craft.recurrence.icsUrl(entry)` gives `recurrence/ics/{uid}.ics`, downloaded as one series: an RRULE with EXDATEs for skipped and cancelled dates, plus a RECURRENCE-ID event for each moved or annotated date. It returns `null` for entries without a URL or a Recurrence field.
- `craft.recurrence.feedUrl('events')` gives `recurrence/feed/events.ics`, every live entry in the section, served inline so calendar apps can subscribe and refresh.

Both work without routes of your own, and only serve live entries that have a URL. For a calendar file under the entry's URL, e.g. `events/{slug}.ics`, add a route with a `uri` param and link to it yourself (`url(entry.uri ~ '.ics')`):

```php
'<uri:events/[^/]+>.ics' => 'recurrence/ics/element',
```

Each event's description and location come from the [calendar settings](#calendar-files).

## Importing

Besides the stored JSON, the field accepts an RFC 5545 string, so an importer such as Feed Me can map an iCal rule straight into it:

```
DTSTART;TZID=America/New_York:20261105T190000
RRULE:FREQ=WEEKLY;BYDAY=TH;COUNT=10
```

- `DTSTART`'s TZID is kept when it's a real IANA zone; UTC or floating times are read in Craft's system time zone.
- A rule has no end time, so imported events are given **60 minutes**.
- Only what the field can express is kept: `FREQ`, `INTERVAL`, weekly `BYDAY`, monthly "Nth/last weekday", `UNTIL` and `COUNT`. Anything else (`BYMONTHDAY` lists, `BYSETPOS`, `EXDATE`, multiple RRULEs…) is dropped.
- A string that isn't a usable rule leaves the field empty.

## Daylight saving time

Times are stored as local wall-clock times with their time zone, so a 7 pm weekly event stays at 7 pm across DST changes. Around the changes themselves:

- **A time that doesn't exist** (clocks spring forward, e.g. 02:30) moves forward by the gap, to 03:30, keeping its length. Only that date moves; the rest of the series keeps the typed time.
- **A time that happens twice** (clocks fall back, e.g. 01:30) means the first one, before the change.
- **An event that runs through a change keeps its end time.** Every date ends at the same local time as the first, so on the night the clocks change, that date is an hour longer or shorter. A weekly 01:30–02:30 event runs two hours on the fall-back night (01:30 before the change to 02:30 after), and an overnight 22:00–06:00 event runs nine hours in autumn and seven in spring. That keeps a "Friday 6 pm to Sunday noon" retreat ending at noon.

Calendar apps work the last case out differently. RFC 5545 gives every date of a series the first date's exact length, so apps importing the `.ics` show those nights an hour off from the site: the 01:30 event as an hour long, the overnight event ending at 05:00 or 07:00. It only affects events running through the change itself, which is 1–3 am on two nights a year in most zones.

## Gotchas

### Setup and maintenance

- **Without the daily `extend` cron, open-ended series run dry.** The occurrences table only holds dates up to the horizon, measured from the last time each entry was written. Listings and `occursBetween()` stop finding a "never ending" weekly event once that date passes. `schedule.next()` and `upcoming()` expand on the fly and aren't affected, which can hide the problem on entry pages.
- **`extend` only revisits entries that already have rows.** Entries that predate the field, were imported directly into the database, or had no dates in the window need `recurrence/occurrences/rebuild` (or a re-save).
- **Settings changes need the queue.** Changing the horizon, pruning or max dates rebuilds the table in a queue job; until it runs, listings reflect the old window. On sites with `runQueueAutomatically` off, make sure a queue worker is running after deploys.
- **One Recurrence field per field layout.** Saving a layout with two is a validation error. Dated URLs and ICS files belong to the entry as a whole.

### Limits and windows

- **`maxOccurrences` favours upcoming dates.** The table holds `pastMonths` back to `horizonMonths` ahead, about 30 months by default, so a daily event that has been running for a year has ~900 dates in that window. When an entry has more dates than the cap, every upcoming date up to the horizon is stored first, and the most recent past dates fill what's left. So a long-running series never loses its next dates to its old ones. A daily series is still the one to watch: 18 months ahead is ~548 dates, more than the default cap of 500, so listings reach about 16 months ahead and show no past dates for it. Raise `maxOccurrences` (e.g. to `1000`) for daily series, especially if past-event archives should include them.
- **Listings only see the window.** `craft.recurrence.occurrences()` and `occursBetween()` can't find dates older than `pastMonths`, so a long "past events" archive needs a larger `pastMonths`. Dated URLs for older dates still resolve.
- **Bounded series are stored to their end.** A series ending on a date (or after N times) is stored in full even past the horizon, still subject to `maxOccurrences`.

### Cancelled dates

- **Cancelled dates are left out by default, everywhere**: `craft.recurrence.occurrences()`, `schedule.upcoming()`, `schedule.between()`, `next()`, `previous()` and `occursBetween()`. To show them labelled as cancelled, opt in with `.includeCancelled()` or `upcoming(n, true)`. `occursBetween()` has no opt-in, since an entry whose only date in the range is cancelled doesn't occur in it.
- Cancelled dates are still stored and still have pages: their dated URLs render, so a template can say the date was cancelled.
- The control panel's preview always lists cancelled dates, so editors can see and restore them.

### Drafts and previews

- Only saved, canonical entries are written to the occurrences table. Drafts, revisions and unsaved changes in live preview show up via `entry.field.upcoming()` etc. (expanded on the fly) but **not** in `craft.recurrence.occurrences()` or `occursBetween()` results.

### URLs

- **Rescheduling a date changes its URL, and the old URL returns 404**, with no redirect. If a moved date was shared or indexed, add a redirect yourself.
- **Moving a date onto a day that already has one adds `-HHMM` to both URLs**, so the date that didn't move gets a new URL too.
- A date can't be moved to the exact start time of another date in the series; the field won't validate.
- `occ.url` assumes the `{entry-url}/{key}` route shape. With any other shape, build links from `occ.urlKey`, never `occ.key` (which is the original date).
- Your `uri` route pattern must match the section's URI format exactly, or dated URLs 404.
- Custom template routes need SEO meta loaded manually (see [Your own template](#your-own-template)).

### Dates and rules

- **Monthly "on day 31"** (or 29/30) skips months that don't have that day, per RFC 5545. Use "last weekday" or a list of dates if that's not what you want.
- **"5th Thursday" means "last Thursday"**, since not every month has a fifth.
- **A bare skipped date skips every date on that day**; a time-suffixed key skips just one.
- **Time zones are per schedule, fixed at creation.** New schedules take Craft's system time zone and keep it. Changing the system time zone later doesn't move existing events, and editors can't choose a zone per entry.
- Date strings passed to `from()`, `to()` and `occursBetween()` are read in the **site's** time zone.

### Calendar files

- **Section feeds include every live entry in the section, uncached.** For large sections, put the feed URL behind a CDN or reverse-proxy cache.
- Calendar apps refresh subscriptions on their own schedule (often hours to a day), so changes don't show up immediately.
- `icsUrl()` returns `null` for entries without a URL; guard links with `{% if %}`.
- Description sources support Plain Text and CKEditor fields only; use a custom template for anything else.
- Settings reference fields by UID, so a field that's deleted or removed from every Recurrence layout shows as "Missing field" in the settings and gives blanks.
- Events running through a DST change can show an hour off in calendar apps (see [Daylight saving time](#daylight-saving-time)).

### Other

- The field isn't indexed for keyword search; `search()` won't match dates or rule text.
- The control panel's index column shows the schedule summary, not the next date.

## Console commands

| Command | |
| --- | --- |
| `php craft recurrence/occurrences/extend` | Extends open-ended series to the horizon and drops rows that fell out of the window or belong to deleted entries. Run daily. |
| `php craft recurrence/occurrences/rebuild [--section=<handle>]` | Rewrites every entry's rows, including entries that have none yet. For first-time setup, imports and repairs. |

For running the tests, see [DEVELOPMENT.md](DEVELOPMENT.md).
