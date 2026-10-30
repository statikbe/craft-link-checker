# Link Checker

Finds the broken links in the content of your Craft CMS 5 site and lists the pages that contain them in a dashboard widget, so
editors can fix them before visitors find them.

- **Broken links**: links to entries that were deleted, are in the trash, disabled, expired, not in the page's site or have no
  page of their own; links to deleted assets; links to pages of the site itself (`/nl/over-ons`, `https://example.be/…`)
  that no longer exist; links to a website whose domain doesn't exist (a typo, an expired domain); external pages that
  answer 404 or 410.
- **Warnings**: links to entries that aren't published yet; links to a page that a redirect sends on (link to the page
  itself); external pages that moved permanently; anchors (`#team`) that aren't on the target page; invalid email addresses and phone numbers; a deleted entry whose
  saved URL still works.
- **Broken links** dashboard widget: the pages with broken links, with a link to edit each one and every broken link with
  its reason and where it is (*Content builder 2 (Content row) › Columns 1 (Text) › Text*). Each user only sees the pages
  they may edit.
- **Links panel** in the entry sidebar: the problems of the page as it's edited, updated after every autosave.
- **Check on publish**: a warning (or, with `'block'`, an error that stops the save) when a page has broken links, shown on
  the row, the block or column and the field, as for Craft's own errors.
- **Global sets** (a footer, site settings) are checked too.
- **Utilities → Link Checker**: every problem with filters (broken or warnings, site, search), a CSV export, and the ignored
  links.
- **Ignore** a link (in the utility or the Links panel): it's no longer reported anywhere, until you stop ignoring it.
- **Console**: `craft link-checker/rebuild` reads every page again; `craft link-checker/report` lists the problems.

It reads native Link fields, rich text fields (CKEditor, Redactor) and [Hyper](https://verbb.io/craft-plugins/hyper) fields,
also in nested entries: Matrix (any depth, also [Trinity](https://github.com/statikbe/craft-trinity) rows), Content Blocks
and entries in CKEditor. No other plugin is needed, and it works on existing sites.

## How it works

Every link of every page is stored in a table, per site, with where it is. A page is read again when it (or a block on it) is
saved. **Whether a link is broken isn't stored**: it's worked out from the target's current state each time the report is
read. Deleting, disabling or expiring an entry shows up in the widget straight away, without reading the pages that link to
it, and without cron.

Links to entries are stored by ID (`{entry:12@1:url}`), so renaming an entry or changing its URL doesn't break them. Only
links typed as a URL break when a page moves. Those are matched against the URIs of the live entries, the routes and the
templates of the site, and the redirects of [SEO Fields](https://plugins.craftcms.com/seo-fields) and
[Retour](https://plugins.craftcms.com/retour).

## External links

External links (to other websites) are checked in queue jobs, never in an editor's request:

1. **The domain** is looked up (DNS), cached per domain: again after 7 days when it exists, after a day when it doesn't. When
   even `example.com` can't be looked up (no network, a broken resolver) nothing is marked.
2. **The page** is requested (`checkExternal`), each URL once however many pages link to it: HEAD first, a GET when a server
   doesn't answer HEAD properly. One request at a time, with `hostDelay` between two requests to the same host.
   - 404 or 410 twice in a row, at least an hour apart (`failuresBeforeBroken`): **broken**.
   - A permanent redirect (301, 308) to a page that works: a **warning**. Not when it only changes http to https, `www.` or
     a trailing slash.
   - Anything else (401, 403, 429, 5xx, a timeout, an SSL error): unknown, never reported. Sites that block link checkers
     would always look broken, so their pages aren't requested (`skipHosts`: Facebook, Instagram, LinkedIn, X, TikTok).
   - A working page is checked again after 7 days, a failing one after an hour, a broken or unknown one after a day.
   - Redirects are followed by hand, so every step is checked: only http(s) on ports 80 and 443, never an address in a
     private or reserved range (the server's own network, cloud metadata), and the checked address is the one connected to.

This needs outbound HTTP from the server. Where that's not allowed, set `'checkExternal' => false` for that environment;
the domains are still checked.

## Requirements

Craft CMS 5.6 or later, PHP 8.2 or later.

## Installation

```bash
composer require statikbe/craft-link-checker
php craft plugin/install link-checker
```

Installing queues a job that reads every page. On a large site you can also run it yourself:

```bash
php craft link-checker/rebuild                   # every page, the domains and the external pages, now
php craft link-checker/rebuild --skip-external   # without requesting the external pages
php craft link-checker/rebuild --queue           # as a queue job
php craft link-checker/check-urls                # the external pages that are due (--all: every one)
php craft link-checker/report                    # the broken links (--warnings: the warnings too)
```

Then add the **Broken links** widget to the dashboard.

## Configuration

Copy this to `config/link-checker.php` and change what you need. Multi-environment configs work as usual.

```php
<?php

return [
    // The sections whose entries are checked: '*' or a list of handles
    'sections' => '*',

    // Fields that are never read for links
    'excludeFields' => [],

    // Other hosts that are this website: the production domain on a local or staging install, an old domain.
    // www and non-www are already the same.
    'internalHosts' => ['www.example.be'],

    // Links to ignore: regular expressions matched against the stored value
    'ignorePatterns' => [],

    // A link to an entry in another site (language): 'ok' or 'warning'
    'crossSiteLinks' => 'ok',

    // Anchors (#…) that aren't on the target page, as warnings
    'checkAnchors' => true,

    // Anchors the templates render on every page (not in the content): strings, or regular expressions between # signs
    'knownAnchors' => ['main', 'contact-form'],

    // Invalid mailto: and tel: links, as warnings
    'checkEmailAndPhone' => true,

    // Also check the global sets
    'checkGlobals' => true,

    // Check that the domain of an external link exists (DNS)
    'checkDomains' => true,

    // Request external pages (HTTP): 404/410 twice is broken, a permanent redirect a warning
    'checkExternal' => true,
    // Hosts whose pages aren't requested (they block link checkers); their domain is still checked
    'skipHosts' => ['facebook.com', 'instagram.com', 'linkedin.com', 'x.com', 'twitter.com', 'tiktok.com'],
    'timeout' => 10,               // seconds
    'hostDelay' => 1000,           // milliseconds between two requests to the same host
    'failuresBeforeBroken' => 2,

    // When an entry with broken links is published: 'warn' (saved, with a warning), 'block' (not saved, the errors on the
    // fields) or false. Warnings never count. A link can break without the page being edited, so 'warn' is the default.
    'mode' => 'warn',

    // The Links panel in the entry sidebar
    'panel' => true,
];
```

## Anchors

A page's anchors are the ids in its rich text (`id` and `name` attributes) and the anchors of
[Entry Anchors](https://github.com/statikbe/craft-entry-anchors) fields. Ids that only your templates render aren't in the
content: list them in `knownAnchors`, or a link to them is reported as an anchor that's not found. A page in a section that
isn't checked has no known anchors, so links to its anchors aren't reported.

## The Links panel

The sidebar of an entry gets a **Links** panel under the notes: the broken links and warnings of the page as it's edited,
also the changes that aren't saved yet, with the reason and where each link is. It's collapsible (the browser remembers it
for every entry) and reloads after every autosave. The number of broken links shows next to its title, also when it's
collapsed.

## Check on publish

With `'warn'`, publishing a page with broken links shows a warning that lists them; the page is saved. With `'block'`, it
isn't saved: each broken link is shown where it is, on the row or block, the column (its card turns red) and the field. Only
broken links count, never warnings, and only when an entry is published in a web request: not for drafts, autosaves,
resaving or console commands.

## Utilities → Link Checker

Every link with a problem, with the page (a link to edit it), the site, the link, the problem and where it is. Filter by
broken links, warnings or both, by site, and search in the page, the link and the location. **Export CSV** downloads the
filtered list. Users need Craft's permission for the utility ("Link Checker" under Utilities).

**Ignore** stops reporting a link everywhere it's used (by its stored value: a URL, an entry reference, a mailto:). The
ignored links are listed at the bottom, with who ignored them and **Stop ignoring**. An editor can also ignore a link in the
Links panel of a page they may edit.

## Widget

The widget lists the pages with broken links, broken ones first. Its settings: the number of pages shown (the rest is
counted) and whether to also list pages with only warnings.

## License

MIT, see [LICENSE.md](LICENSE.md).
