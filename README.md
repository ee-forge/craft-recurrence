# Recurrence for Craft CMS 5

Recurring dates for **ordinary entries**. Add a Recurrence field to any entry type and the entry gets:

- a start/end (or all-day) date, plus an optional repeat rule: daily, weekly on chosen days, monthly on day N / Nth weekday / last weekday, yearly, or a list of specific dates. A series can end never, on a date, or after N times;
- extra dates and skipped dates;
- **per-date overrides**: cancel a date (it stays listed as cancelled), reschedule it, or attach a note;
- an **occurrences table** with a row per date, so listing, paging and counting dates is plain SQL;
- a **URL per date**, `{entry-uri}/{yyyy-mm-dd}`, once you route it;
- **ICS** downloads per entry and a subscribable feed per section.

Entries stay entries, so Matrix, multi-site, drafts, SEOmatic, Feed Me and upgrades behave natively.

## Setup

1. Install the plugin and add a **Recurrence** field to an entry type. A layout can hold only one Recurrence field, since dated URLs and ICS files belong to the entry as a whole. The defaults are shared by all Recurrence fields and live in **Settings → Plugins → Recurrence**: horizon, past months kept, max dates, and the calendar description and location. New schedules use Craft's system time zone and store it, so their times stay put across DST and system time zone changes. Changing the horizon, past months or max dates rebuilds the occurrences table in a queue job, both on a CP save and when `craft up` applies a deployed change.
2. To give each date its own page, add a route per section (see [Dated URLs](#dated-urls)).
3. Existing entries get their dates written to the occurrences table when saved. To write them all at once, run `php craft recurrence/occurrences/rebuild [--section=events]`.
4. Add a daily cron job so open-ended series keep extending:

   ```
   0 3 * * * php /path/to/craft recurrence/occurrences/extend
   ```

## Templates

```twig
{# Upcoming dates across a section, 12 per page #}
{% paginate craft.recurrence.occurrences()
    .section('events')
    .from(now)
    .limit(12) as pageInfo, occurrences %}
{% for occ in occurrences %}
    {{ occ.entry.title }} — {{ occ.start|date('D, M j') }} {{ occ.allDay ? '' : occ.start|time('short') }}
    {% if occ.cancelled %}Cancelled{% endif %}
    {% if occ.rescheduled %}Moved from {{ occ.originalStart|date('M j') }}{% endif %}
    {{ occ.note }}
    <a href="{{ occ.url }}">Details</a>
{% endfor %}
{{ pageInfo.total }} dates
```

The query is a regular `craft\db\Query` whose rows come back as occurrence
models, so `{% paginate %}`, `sprig.paginate(query, page)`, `batch()` and
plain `{% for occ in query %}` loops all work on it.

Query methods:
- `from(date)`: dates that haven't ended (ongoing ones included);
- `to(date)`: dates starting before it;
- `section()`, `type()`, `relatedTo()`, `search()`, and any other entry query param;
- `site()`;
- `field(handle)`;
- `includeCancelled(false)`;
- `unique()`: one result per entry, its next date;
- `reverse()`: latest first;
- `limit()`, `offset()`, `all()`, `one()`, `count()`, `exists()`, as on any query.

Date strings are read in the site's time zone, like Craft's own date params.

On an entry, the field value is a schedule:

```twig
{% set schedule = entry.eventSchedule %}
{{ schedule.summary }}              {# "Weekly on Wed at 7:00 PM" #}
{% set next = schedule.next() %}    {# next date that isn't cancelled #}
{% for occ in schedule.upcoming(6) %}…{% endfor %}
{{ schedule.previous() }}           {# latest past date, for finished series #}
{{ schedule.isRecurring }}
```

Entry queries gain `occursBetween(from, to)`, which matches entries with a non-cancelled date in the range:

```twig
{% set thisWeek = craft.entries.section('events').occursBetween(now, now|date_modify('+7 days')).all() %}
```

## Dated URLs

Each date can have its own page at `{entry-uri}/{key}`. The key is the date's actual `yyyy-mm-dd`, plus `-HHMM` when two of the series' dates start on that day. The plugin doesn't route these itself, since only the site knows which URLs it wants to give up. Add a route per section in `config/routes.php`, pointing at the plugin's controller:

```php
return [
    '<uri:events/[^/]+>/<key:\d{4}-\d{2}-\d{2}(?:-\d{4})?>' => 'recurrence/occurrence/view',
];
```

- `uri` must capture the entry's full URI, as its section's URI format produces it: `events/[^/]+` for `events/{slug}`, `calendar/.+` for a nested structure under `calendar/`.
- `key` must use the pattern above.
- `occ.url` builds `{entry-url}/{key}`, so links work with routes of that shape. For any other shape, e.g. `events/{slug}/on/{key}`, build links yourself: `url(entry.uri ~ '/on/' ~ occ.urlKey)`.

The controller renders the entry's own template with an extra `occurrence` variable:

```twig
{% set occ = occurrence ?? entry.eventSchedule.next() ?? entry.eventSchedule.previous() %}
```

It sets the entry as the matched element before rendering, so SEOmatic and anything else that reads `craft.app.urlManager.matchedElement` sees the entry as on its own URL.

- Rescheduling a date changes its URL to the new date; the old URL returns 404. If the move puts two dates on one day, both get the `-HHMM` suffix.
- A date can't be moved to the exact start time of another date in the series.
- Internally a date's changes stay keyed to its *original* date (`occ.key`, the RFC 5545 RECURRENCE-ID); the URL key is `occ.urlKey`.
- Past dates still render.
- Dates that were never part of the series return 404.
- One-off entries 301 to the entry URL.
- Entries without a template (or whose section routes to a custom controller) return 404.

`{% cache %}` keys on the path, so each dated URL is cached separately. Expire the bare URL's cache when the date it shows ends: `{% cache until occ.end %}`.

### Your own template

For full control, route to your own template instead and load the date with `craft.recurrence.occurrence(entry, key)`, which returns null when no date has that key:

```twig
{% set occ = craft.recurrence.occurrence(entry, key) %}
{% if not occ %}{% exit 404 %}{% endif %}
```

A template route has no matched element, so plugins that read it (`craft.app.urlManager.matchedElement`) don't see the entry. That includes SEO plugins, which usually load their meta before the template's own code runs. With SEOmatic, load the entry's meta yourself, before any output:

```twig
{% do seomatic.helper.loadMetadataForUri(entry.uri) %}
```

Routes pointing at `recurrence/occurrence/view` don't need this.

## ICS

- `craft.recurrence.icsUrl(entry)` gives `recurrence/ics/{uid}.ics`, one series with RRULE/EXDATE plus RECURRENCE-ID overrides.
- `craft.recurrence.feedUrl('events')` gives `recurrence/feed/events.ics`, for subscribing to a whole section.

Both work without any routes of your own. For a calendar file under the entry's URL, e.g. `events/{slug}.ics`, add a route with a `uri` param and link to it yourself (`url(entry.uri ~ '.ics')`):

```php
'<uri:events/[^/]+>.ics' => 'recurrence/ics/element',
```

Each event's description and location in calendar files come from the plugin settings. Each is one of:

- **None.**
- **A field.** The list shows fields in layouts that have a Recurrence field, under their names there. Entries without that field get a blank.
  - Description: plain text, or CKEditor/Redactor rich text, whose paragraphs, line breaks and list items carry over.
  - Location: plain text; an Addresses field, which gives the formatted address plus `GEO` coordinates so calendar apps can pin it; or an Entries field, which gives the related entry's title.
- **Custom template…**, an object template rendered against each entry, for anything else (e.g. an address on a related entry). HTML is converted to plain text, and a template error leaves the value blank and logs a warning.

Any setting can be pinned in `config/recurrence.php`, which locks it in the CP. Field sources are field UIDs:

```php
return [
    'horizonMonths' => 36,
    'icsDescriptionSource' => '7cb2ace3-0114-4231-a527-143318b9641d',
    'icsLocationSource' => 'template',
    'icsLocationTemplate' => "{{ object.venue.one().title ?? '' }}",
];
```

## Daylight saving time

Times are stored as local wall-clock times with their time zone, so a 7 pm weekly event stays at 7 pm across DST changes. Around the changes themselves:

- **A time that doesn't exist** (clocks spring forward, e.g. 02:30) moves forward by the gap, to 03:30, keeping its length. Only that date moves; the rest of the series keeps the typed time.
- **A time that happens twice** (clocks fall back, e.g. 01:30) means the first one, before the change.
- **An event that runs through a change keeps its end time.** Every date ends at the same local time as the first, so on the night the clocks change, that date is an hour longer or shorter. A weekly 01:30–02:30 event runs two hours on the fall-back night (01:30 before the change to 02:30 after), and an overnight 22:00–06:00 event runs nine hours in autumn and seven in spring. That keeps a "Friday 6 pm to Sunday noon" retreat ending at noon.

Calendar apps work the last case out differently. RFC 5545 gives every date of a series the first date's exact length, so apps importing the `.ics` show those nights an hour off from the site: the 01:30 event as an hour long, the overnight event ending at 05:00 or 07:00. It only affects events running through the change itself, which is 1–3 am on two nights a year in most zones.