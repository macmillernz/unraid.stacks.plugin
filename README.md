# Stacks UI

Unraid plugin: manage Docker Compose stacks from a native "Stacks" tab,
hiding the built-in Docker tab.

## Layout

- `stacksUI.plg` — plugin descriptor (install/remove, boot-hook wiring).
- `source/stacksUI/` — packaged verbatim into `/usr/local/emhttp/plugins/stacksUI/`.
  - `Stacks.page` — the tab itself (`Menu="Tasks:60"` — the file's basename is what appears as both the nav label and URL, see below for why `Tasks:N` and not `Menu="Stacks"`).
  - `include/StacksHelper.php` — stack CRUD + `docker compose` wrappers (all shelling out goes through `stacksUI_compose_run()`, using `proc_open` with cwd set to the stack's dir and `escapeshellarg` on every arg).
  - `include/ajax.php` — JSON endpoint the frontend calls (list/get/create/update/delete/up/down/restart/pull/logs/validate/autostart). CSRF for POST is enforced globally by Unraid's own `webGui/include/local_prepend.php` (via `auto_prepend_file` in `php.ini`) before this script even runs — do not re-check `$_POST['csrf_token']` here, it's already been validated and stripped by the time your code executes.
  - `js/stacksUI.js`, `css/stacksUI.css` — card list (one per stack, expandable to a per-container table: service, image, state, ports, network+IP), create/edit wizard (compose + env as line-numbered editors with Upload buttons and a Verify Syntax check), logs viewer, per-stack Autostart switch, Start All/Stop All. Start/stop/restart show an inline spinner in place of the clicked button rather than a popup — there is no action-progress modal anymore.
  - `scripts/patch_docker_menu.sh`, `scripts/restore_docker_menu.sh` — hide/restore the built-in Docker tab.
  - `scripts/install_compose_cli.sh` — installs the bundled `docker-compose` CLI if missing (see below).
  - `event/docker_started/autostart_stacks` — starts every `autostart: true` stack, hooked to Unraid's own `docker_started` array-start event (same lifecycle point the built-in Docker page's own Autostart uses).
  - `DashboardTile.page` — adds a "Stacks" tile to the Main Dashboard (name + running/stopped) and hides the built-in Docker tile there, via Unraid's own documented custom-tile mechanism — see Dashboard tile below.
- `package.sh` — builds `source/stacksUI/` into a `.txz` for a real release (paths rooted at `/` so `upgradepkg` extracts it correctly). Version defaults to whatever `stacksUI.plg`'s `<!ENTITY version ...>` currently says (see Versioning below) rather than generating its own.
- `deploy.sh` — dev-cycle rsync straight to a test box (`STACKS_UI_HOST=user@host ./deploy.sh`), skipping packaging.

## Versioning, author, and the Plugins page icon — fixed 2026-07-11

`stacksUI.plg`'s root `<PLUGIN>` tag carries `author`, `version`, and
`icon` — Unraid's own Plugins page (`dynamix.plugin.manager`'s
`ShowPlugins.php`) reads these three attributes directly via
`simplexml_load_file()` on the installed `.plg` (via the
`/var/log/plugins/<name>.plg` → `/boot/config/plugins/<name>.plg`
symlink chain) and falls back to literal "anonymous"/"unknown" text if
that file can't be read or parsed — no error shown, just those silent
defaults. Two real bugs surfaced fixing this for real:

1. The installed `/boot/config/plugins/stacksUI.plg` had gone missing on
   the test box entirely (a casualty of earlier reboot/uninstall testing
   sessions that only ever restored the `.txz` package, never this
   separate descriptor file) — `simplexml_load_file()` silently returns
   `false` when the file doesn't exist, which is exactly what produced
   "anonymous"/"unknown".
2. Once restored, it *still* failed to parse — a genuinely broken `.plg`:
   a bare `&` in "B&W" inside a `<CHANGES>` entry (added this same
   session) is invalid XML. **`xmllint --noout` did not catch this** (kept
   reporting the file "valid" both before and after the fix) — only
   testing against the box's actual `simplexml_load_file()` +
   `libxml_use_internal_errors(true)` + `libxml_get_errors()` surfaced
   the real parser error ("EntityRef: expecting ';'", pointing at the
   exact line). `xmllint` alone is not sufficient to trust a `.plg` file;
   verify with the real PHP parser when in doubt.

Versioning now follows `year.month.day.n` (bump `n` for each same-day
release, reset to 1 on a new day) — the version lives in exactly one
place, `stacksUI.plg`'s `<!ENTITY version ...>`, and `package.sh` reads
it as the default rather than generating its own from `date`. Author is
`macmiller`. The plugin icon is `images/stacks.png` (see below) — Unraid
looks it up via `plugins/<name>/images/<icon>` once the root tag's
`icon="stacks.png"` attribute is set.

**Packaging gotcha for future version bumps:** our package filenames
embed the full version string directly (`stacksUI-<version>.txz`) rather
than following Slackware's strict `name-version-arch-build` convention,
so bumping the version changes the *package name* Slackware tracks, not
just its version field. `upgradepkg --install-new` does **not**
automatically remove a differently-named prior package — confirmed by
hand: after bumping from `stacksUI-2026.07.10` to `stacksUI-2026.07.11.1`,
the old package was still separately listed in `/var/log/packages/`.
Explicitly `removepkg <old-package-name>` before installing a new
version until/unless this naming scheme changes.

## Dashboard tile (Stacks) + hiding the built-in Docker tile — verified live 2026-07-11

The Main Dashboard is a completely different mechanism from the nav bar
(see Docker tab hiding below) — it's one large core file,
`dynamix/DashStats.page` (~2900 lines), not a set of small per-tab pages.
Patching it directly the way we patch `Docker.page` would be far riskier
(much bigger blast radius, much more likely to drift out of sync with
Unraid core updates). Instead, Unraid ships a genuine, documented,
zero-core-file-patch extension point for exactly this: any `.page` file
in the `Dashboard` menu group can set a `$mytiles[$pluginname][$column]`
global (columns: `column1`/`column2`/`column3`), and `DashStats.page`
itself calls `customTiles($column)` at three points to echo whatever's
been registered there. The official template lives right on every
Unraid box at `webGui/DashboardCustomTileExample.page-` (the trailing
`-` keeps it inactive — it's a copy-paste starting point, not live code).

`DashboardTile.page` uses this: `Menu="Dashboard:0"` (the `:0` rank sorts
it before `DashStats.page`'s own unranked `Menu="Dashboard"` entry, so
`$mytiles` is populated before `customTiles()` runs — confirmed by
reading `find_pages()`'s `ksort($pages,SORT_NATURAL)` behavior and
`MainContentTabless.php`'s per-page `eval()` loop directly, not assumed).
It builds a `column2` tile (matches where the built-in Docker tile
lives): a 3-per-row CSS grid of `<div>`s, each showing the stack's logo
(or a placeholder) with a small green/red status dot overlaid in the
corner, and the name below — calling `stacksUI_list_stacks()` directly
(same PHP process, no AJAX needed for something this simple). **Never
use a `<table>` inside this tile** — see the 2026.07.11.4 bug below for
why; only `<div>`/`<span>` are used.

Hiding the built-in Docker tile uses the exact same file and the exact
same "no core patch" principle: since any `.page` file's whole body
(not just its `$mytiles` side effect) gets `eval()`'d and echoed into the
page in sequence, a plain `<style>#docker_view { display: none !important; }</style>`
placed directly in `DashboardTile.page` renders as a genuine part of the
Dashboard's output. This is simpler and safer than patching
`DashStats.page`'s own `$dockerd` condition would have been — no boot
persistence concerns either, since this is just another file in our own
plugin package, reinstalled the same way as everything else every boot.
`#docker_view` stays in the DOM (just visually hidden), so anything else
that targets it (e.g. `DashboardApps.php`'s polling) is unaffected.

Verified live end-to-end: loaded the real Dashboard, confirmed the
`<style>` rule renders, confirmed `#docker_view` is still present in the
DOM (not removed, just hidden), confirmed the Stacks tile renders with
all real stacks and correct running/stopped colors, and confirmed the
page structure stayed non-tabbed/non-duplicated (`Dashboard.page`'s own
`Tabs="false"` already guarantees this regardless of how many
`Menu="Dashboard:N"` pages exist).

**2026.07.11.2 shipped a real regression: it broke the entire Dashboard
(rendered blank).** Root cause, confirmed by reading
`webGui/include/DefaultPageLayout/MainContent.php`'s `generateContent()`
directly: **any `.page` file's whole body gets run through a full
Markdown parser unless its header sets `Markdown="false"` explicitly**
(`empty($page['Markdown']) || $page['Markdown'] == 'true'` triggers it -
i.e. it defaults to *on*, not off). `DashboardTile.page` didn't set it,
so Markdown wrapped the injected `<style>` block inside an invalid
`<style>`-inside-`<p>`, corrupting the page. Fixed in 2026.07.11.3 by
adding `Markdown="false"` to the header (matching plenty of other real
`.page` files that already do this, e.g. `AddContainer.page`,
`DockerContainers.page` - should have matched the convention from the
start). **Any new `.page` file added to this plugin going forward should
default to `Markdown="false"` unless there's a specific reason to want
Markdown processing** - it is not a safe default to leave unset.

**2026.07.11.3's fix wasn't actually sufficient — the Dashboard was
still blank for the user after deploying it.** This one needed a real
browser to diagnose: all server-side checks (fresh HTML fetch, no PHP
errors, correct markup, fast response, tbody-count comparisons) looked
completely fine both times, because the actual failure was a client-side
JavaScript crash invisible to curl. The user's browser console showed:

```
Uncaught TypeError: Cannot read properties of undefined (reading 'md5')
    at HTMLTableSectionElement.<anonymous> (Dashboard:3300:67)
    ...at addProperties (Dashboard:3299:39)
```

Root cause: the original tile design used a `<table>` inside a `<td>` to
list stacks (`<table style="width:100%">...rows...</table>`) without an
explicit `<tbody>` around its rows. Browsers auto-insert an implicit
`<tbody>` for any `<table>`/`<tr>` combo that doesn't have one — and that
auto-generated tbody has no `title` attribute. Unraid's own dashboard
init script, `addProperties()` in `DashStats.page`, runs
`$('div.frame tbody').not('.system').each(...)` and unconditionally calls
`.attr('title').md5()` on every single tbody on the page — so the
implicit, title-less tbody threw an uncaught exception that aborted the
rest of that init script, leaving the whole Dashboard blank below the
nav bar (which renders via separate, earlier, unaffected code — matching
exactly what the user described: nav/titles/buttons visible, content
area empty). The official `DashboardCustomTileExample.page-` template
never uses a nested table for exactly this reason — it uses plain
`<span class="w18/w26/w36/w44/w72">` elements for column-like alignment
instead. **Never nest a `<table>` inside a custom dashboard tile** — use
`<div>`/`<span>` with CSS grid/flex for any multi-column layout, as the
current tile now does.

Also worth remembering from this debugging session: two dead ends were
ruled out carefully before finding the real cause — Brave's Shields
(ad/tracker blocking) was suspected first (disabling it changed nothing,
ruling it out) - and a benign, unrelated console warning ("Error with
Permissions-Policy header: Unrecognized feature: 'interest-cohort'") was
present throughout and is **not** connected to this plugin at all (it's
Unraid's own server sending a `Permissions-Policy` header Brave doesn't
recognize - purely cosmetic noise).

Fully fixed and live-verified in 2026.07.11.4: tbody count matches the
known-safe baseline exactly (+1 for our one real tile, no phantom
implicit ones), no console errors reported by the user beyond the
unrelated `interest-cohort` one, and the tile now renders correctly as a
3-column grid with logos and status dots.

## Native Unraid styling — done 2026.07.11.5

The plugin's whole look (buttons, inputs, status badges, container list
table, modal, and the Dashboard tile) was switched from a custom
hardcoded color scheme to Unraid's own native styling, so it blends into
the rest of the webGUI and follows whichever theme (light/dark/etc.) is
active instead of looking the same regardless:

- Plain `<button>`/`<input type=text>` already get Unraid's native look
  for free from `webGui/styles/default-base.css` (uppercase
  gradient-bordered buttons, underline-style inputs) — removed our own
  `stacksUI-btn-primary`/`stacksUI-btn-danger` color classes and the
  custom `.stacksUI-modal input[type=text]` override entirely; only kept
  a margin reset (native buttons ship with spacing sized for loose
  settings-page forms, too wide for our compact toolbar/card rows).
  Unraid has no native primary/danger button color convention — every
  button uses the same red/orange brand gradient — so we don't invent
  one either; Delete still `confirm()`s before acting.
- Colors (status badges/dots, error text, validation banners, spinner)
  now come from `webGui/styles/default-color-palette.css`'s CSS custom
  properties (`--green-500`, `--red-800` "Unraid Brand Red", `--gray-*`,
  `--blue-700`, `--orange-500`) and theme-level variables
  (`--text-color`, `--border-color`, `--background-color`,
  `--mild-background-color`, `--alt-text-color`) instead of hardcoded
  hex.
- The container list table now uses Unraid's own `table.unraid`
  striping/hover convention (just adds the class in the markup) instead
  of custom table CSS.
- The Dashboard tile's status dot and logo placeholder background use
  the same variables (`var(--green-500)`/`var(--red-800)` for the dot,
  `var(--background-color)` for its border, `var(--gray-150)`/
  `var(--alt-text-color)` for the placeholder) so it matches the rest of
  the restyled plugin.
- The compose/env code editor and the logs viewer were **deliberately
  left** with their own dark, VS-Code-style hardcoded colors regardless
  of site theme — that's a distinct, common "code editor" convention,
  not something Unraid's own theme system has an opinion on.

Verified live on the test box after rebuild/reinstall (2026.07.11.5):
real PHP `simplexml_load_file()` parse of the `.plg` succeeds, package
installed cleanly after `removepkg` of the prior version, Dashboard
returns HTTP 200 with no fatal/parse errors and the known-good tbody
count, Stacks page returns HTTP 200, all 11 real stacks still list
correctly via the AJAX API, and the served CSS/HTML show the expected
`var(--...)` usages (native button/table classes render with no leftover
`-primary`/`-danger` classes). Actual on-screen look/theme-match still
needs the user's own visual confirmation — that can't be checked from
the server side.

## New icon + Azure/Grey (sidebar theme) support — done 2026.07.11.6

User reported the plugin "doesn't play nicely" with the Azure/Grey themes
and asked for a new icon (three overlaid rounded squares, middle one
filled). Investigated both live on the box rather than guessing:

- **Root cause of the sidebar breakage**: Azure and Grey are Unraid's two
  "sidebar" themes (`ThemeHelper::SIDEBAR_THEMES`) — White/Black use a
  horizontal top nav instead. In sidebar mode every tab collapses to a
  small icon-only column that expands to reveal its name on hover; the
  icon comes from a `Code=` header attribute (a raw codepoint into a
  bundled icon font), turned into `.nav-item a[href='/PageName']:before{content:'\<Code>'}`
  by `webGui/include/PageBuilder.php`'s `generate_sidebar_icon_css()`
  (only emitted for sidebar themes — confirmed via
  `webGui/include/DefaultPageLayout.php`). Every built-in tab
  (Dashboard, Shares, Docker, etc.) sets this; `Stacks.page` never did,
  so in Azure/Grey it fell back to plain, unstyled, non-popping-out text
  squeezed into the same fixed-width icon column as everything else —
  the actual "doesn't play nicely" symptom.
- **Fix**: added `Code="f24d"` to `Stacks.page`'s header — the bundled
  FontAwesome "clone" glyph (two overlapping squares, confirmed present
  in `webGui/styles/font-awesome.woff` via its `.fa-clone:before` rule),
  reached through the same `font-family: docker-icon, fontawesome,
  unraid` fallback chain the core pages use for their own `Code=`
  values. This is a real font glyph, not an image, so its color already
  follows `.nav-item a`'s own CSS (`color: var(--gray-400)`, hover
  `var(--orange-100)`) automatically — genuinely theme-adaptive for
  free, no separate light/dark asset needed here. **Verified live**
  against the box's real, already-active Azure theme (no setting was
  changed to test this): authenticated fetch of `/Dashboard` shows
  `.nav-item a[href='/Stacks']:before{content:'\f24d'}` generated
  correctly, right alongside every built-in tab's own rule, with
  `Theme--azure Theme--sidebar` confirmed active and zero PHP errors.
- **New icon** (`images/stacks.png`, referenced by both `Stacks.page`'s
  `Icon=` header and the `.plg`'s own `icon=` attribute for the Plugins
  page listing): three overlaid rounded-corner squares, the middle one
  filled, replacing the old solid-black 4-square outline placeholder.
  The old icon was pure black — invisible against the Grey theme's dark
  background, and the actual reason for the "follow light/dark logo
  colours" ask. Since this location (the Plugins-page listing,
  `dynamix.plugin.manager/include/ShowPlugins.php`) just emits a plain
  static `<img>` tag with no per-theme swap mechanism to hook into (confirmed
  by reading the source — no dark/light variant convention exists there,
  and patching that core file is out of scope for this plugin), true
  swapped light/dark assets aren't achievable at that one spot. Used the
  next best thing instead: outline squares in a neutral mid-gray
  (`--gray-500`, `#808080`) plus the filled middle square in Unraid's own
  brand orange (`--orange-500`, `#ff8c2f`) — both colors read clearly
  against white, black, and gray backgrounds alike (checked by
  compositing the generated PNG over all three). Generated with a small
  pure-Python script (4x supersampled rounded-rect rasterizer + manual
  zlib/struct PNG encoding, no imaging library — same approach as the
  original placeholder icon), not committed to the repo since it's a
  one-off asset-generation tool, not part of the plugin itself.
- Re-ran the full version-bump ritual for `2026.07.11.6`: `xmllint`, the
  real on-box `simplexml_load_file()` parse, `package.sh` rebuild,
  explicit `removepkg` of `2026.07.11.5` before `upgradepkg
  --install-new`, `.plg` redeploy with the real MD5, then verified the
  installed icon file's MD5 matches the repo copy exactly and the
  Plugins page's live version-check channel reports `2026.07.11.6`.
- **Found and cleaned up an unrelated, pre-existing stale artifact while
  checking the Plugins page**: `/boot/config/plugins-error/stacksUI.plg`
  — a leftover from a failed install attempt on 2026-07-10, sitting
  there silently ever since (confirmed via its file timestamp, well
  before any of this project's later fixes). It made the Plugins page
  show a spurious "ERROR" row for stacksUI alongside the real, correctly
  working entry. Removed it — harmless cleanup of a stray quarantine
  file, not a change to anything live/relied upon.
- **Still open**: like the native-styling change above, this is visual —
  confirmed correct from the server side (generated CSS, icon bytes,
  version string) but the user's own look at the Azure/Grey sidebar and
  the new icon is the only way to confirm it actually reads well in
  practice.

## Data

Each stack's files live at `<stacksDir>/<stackname>/` (`stacksDir` defaults to
`/boot/config/plugins/stacksUI`, configurable — see Settings below):
- `docker-compose.yml`
- `.env` (optional)
- `meta.json` (`logoUrl`, `createdAt`, `autostart` — our own metadata, not passed to compose)

`docker compose` is always run with cwd set to that directory, so it
picks up `docker-compose.yml`/`.env` by convention (no `-f`/`--env-file`
flags needed) and infers the project name from the directory name — same
convention verified working end-to-end in the earlier `unraid-ui` project's
`backend/src/lib/compose.ts`.

Uninstalling the plugin leaves this directory in place (stacks survive a
reinstall/upgrade); delete it manually for a clean wipe.

## Settings — verified live 2026-07-11

The "Settings" button (toolbar) exposes two independent, persisted values
in `<STACKSUI_DEFAULT_DIR>/settings.json` (always at the fixed
`/boot/config/plugins/stacksUI/settings.json`, regardless of what
`stacksDir` itself is set to — this file is the bootstrap pointer, so its
own location can't be the thing being pointed at):

- **Stacks directory** (`stacksDir`) — where every stack's own files live.
  Changing this **moves existing stacks** to the new location
  (`stacksUI_move_stacks()`: tries a plain `rename()` first, falls back to
  recursive copy+delete for a cross-filesystem move e.g. flash → array
  share) rather than silently orphaning them. Only real stack directories
  (anything with its own `docker-compose.yml`) are moved — `packages/`
  and `settings.json` itself are untouched.
- **Default data root** (`dataRoot`, default `/mnt/user/appdata`) — doesn't
  move or touch anything. It only pre-fills a `DATA_ROOT=<dataRoot>/<name>`
  suggestion in a **new** stack's `.env` (kept in sync with the stack name
  field as you type, but only until you edit `.env` yourself — after that
  your edit is never overwritten). Existing stacks are unaffected.
- **Backup path** (`backupPath`, blank = disabled) — after every
  create/update, `stacksUI_backup_stack()` mirrors the stack's
  `docker-compose.yml`/`.env`/`meta.json` into `<backupPath>/<name>/`
  (clears the destination first each time, so it's a clean mirror, not an
  accumulating history). Best-effort: a backup failure never blocks the
  actual save — `stacksUI_write_stack()` catches it and `ajax.php` returns
  a `backupWarning` alongside the normal success response, surfaced in the
  UI as an alert after the stack saves. Setting/changing `backupPath` in
  Settings also immediately backs up **every existing stack**
  (`stacksUI_backup_all()`), not just ones you happen to edit afterward -
  best-effort per stack, returned as `backedUp: {name: true|"error"}` in
  the settings-save response.

The Settings modal itself closes as soon as save succeeds (it used to
stay open showing an in-modal message - fixed per user feedback). Any
moved-stacks list or per-stack backup failures are now surfaced via an
`alert()` after the modal closes, instead of a message inside it.

Verified live: `dataRoot`-only change (no file movement) round-tripped
correctly with zero stacks affected; the move logic itself
(`stacksUI_move_stacks()`) was verified against synthetic test
directories covering both the same-filesystem `rename()` path and the
cross-filesystem fallback (confirmed genuinely cross-fs: tmpfs `rootfs` →
array `shfs`, both moved correctly, non-stack files/dirs correctly left
behind) — deliberately **not** tested by actually changing `stacksDir`
against the real box, since that would move all real stacks (11, 2 of
them running) as a side effect of testing rather than something asked
for. If picking this up again: the underlying move function is proven,
but a real end-to-end `stacksDir` change against live stacks hasn't been
done — worth doing once, carefully, before fully trusting it in the UI.

Backup path was fully live-tested end-to-end against a disposable test
stack: create → confirmed byte-identical mirror written → edit → confirmed
mirror updated to match → pointed `backupPath` at an intentionally
unwritable location (`/proc/...`) → confirmed the save still succeeded
with a `backupWarning` explaining the failure, rather than silently losing
the backup or blocking the save. That second case caught a real bug:
`mkdir()`/`copy()` return `false` on failure rather than throwing, so the
first version of `stacksUI_copy_recursive()` silently treated a failed
backup as a success — fixed by checking every `mkdir()`/`copy()` return
value and throwing explicitly on failure.

Note: the same `/boot/config/plugins/stacksUI/` directory also holds
`packages/` (where the installed `.txz` lives, per the `.plg`'s
`<FILE Name="&configdir;/packages/...">` convention). `stacksUI_list_names()`
in `StacksHelper.php` filters stack directories by the presence of their
own `docker-compose.yml` specifically to avoid treating `packages/` (or
any other non-stack directory) as a bogus stack — this was a real bug
caught during live testing (a "packages" stack showed up with a
`docker compose exited with code 125` error) before the filter was added.

## Docker tab hiding — fully verified live 2026-07-10 (Unraid 7.3.2), including reboot + uninstall

Confirmed by reading `webGui/include/PageBuilder.php`, `DefaultPageLayout.php`,
and `DefaultPageLayout/Navigation/Main.php` directly on the test box: the
main horizontal nav bar renders exactly the pages in the **`Tasks`** menu
group (`find_pages('Tasks')`), one tab per page, ordered by the `:N`
suffix (`Main.page`/`Dashboard.page`=1, `Shares.page`=2, `Settings.page`=4,
`Plugins.page`=50, **`Docker.page`=60**, `VMs.page`=70, `Apps.page`=80,
`Tools.page`=90). The Docker tab is `dynamix.docker.manager/Docker.page`
(`Menu="Tasks:60"`) — **not** `DockerContainers.page` (`Menu="Docker:1"`,
which only controls a sub-page shown *inside* the Docker tab once you're
on it). An earlier version of this plugin patched the wrong file for
this reason; `patch_docker_menu.sh` now correctly targets `Docker.page`,
backs it up, and renames its `Menu=` value so it drops out of the `Tasks`
group entirely. `Stacks.page` claims the freed `Tasks:60` slot, so
"Stacks" now appears exactly where "Docker" used to (this is also why the
page is named `Stacks.page`, not `StacksUI.page` — the nav label and URL
come from the file's basename, not any `Title=`/`Name=` header).

Fully live-verified end-to-end on the test box (192.168.1.22, Unraid 7.3.2):
- Docker tab disappeared from the nav, Stacks tab appeared in its place at
  the same position, page loads with no PHP errors.
- Full stack lifecycle worked for real: create → start → confirmed serving
  HTTP traffic → logs → edit-prefill → stop → delete → confirmed clean, no
  leftover files/containers/images.
- **Reboot persistence confirmed with an actual reboot** (not just code
  review): installed via the real `.plg` mechanism (`.txz` under
  `/boot/config/plugins/stacksUI/packages/`, registered via
  `/usr/local/sbin/plugin install`, tracked in `/var/log/packages/` and
  `/var/log/plugins/`), rebooted the box, and confirmed both the Docker
  tab stayed hidden and Stacks still worked afterward. Note:
  `/usr/local/emhttp` is fully rebuilt from OS packages on every boot — a
  plain `rsync`-based dev deploy (what `deploy.sh` does) does **not**
  survive a reboot by itself; only a real package install
  (`package.sh` → `.txz` → `upgradepkg`) does.
- **Uninstall reversibility confirmed**: ran the `.plg`'s remove steps,
  confirmed the Docker tab came back and Stacks' files were gone, then
  reinstalled and confirmed working again.

One caveat if reinstalling manually during dev (not via a real hosted
release): `plugin install` will attempt to re-download from `SRC` if
`/var/log/plugins/<name>.plg`'s tracking symlink is missing, and our `.plg`
still has a placeholder `SRC`/`MD5` until a real release is cut — that
download will fail. Use `upgradepkg --install-new <path-to-txz>` directly
against the locally-built package instead (what `deploy.sh`/manual testing
should do until there's a real hosted release).

## Docker Compose CLI dependency — bundled and self-healing

Unraid does not ship `docker compose` built in on every version (this
test box didn't). Rather than depend on it being installed separately
(what the first version of this plugin did, manually, for testing),
`package.sh` bundles a pinned static `docker-compose` binary (checksum-
verified at build time) into the package at `vendor/docker-compose`, and
`scripts/install_compose_cli.sh` installs it to
`/usr/local/lib/docker/cli-plugins/docker-compose` if a working
`docker compose` isn't already present. This script runs at install time
and is added to the same `/boot/config/go` boot hook as the Docker-tab
patch, because **`/usr/local/lib` is just as non-persistent as
`/usr/local/emhttp`** — confirmed the hard way: the manually-installed
CLI plugin from earlier testing disappeared after each of this plugin's
own reboot tests, which is exactly what caused a real stack (`authentik`)
to fail with `docker compose exited with code 125` (an unhelpful message —
it's what you get any time the `docker` CLI doesn't recognize `compose`
as a subcommand at all, not a compose-file problem).

Not yet re-verified with a fresh full reboot cycle (the underlying
`/boot/config/go` persistence mechanism itself was already proven with a
real reboot for the Docker-tab patch, and this script was verified
directly + is idempotent) — if being thorough, re-run the reboot test to
confirm both hooks fire together.

## Autostart — verified live 2026-07-10

Modeled directly on how the built-in Docker page's own Autostart works
(`dynamix.docker.manager/include/DockerContainers.php`/`UpdateConfig.php`):
that stores enabled container names in a plain file
(`/var/lib/docker/unraid-autostart`) and `/etc/rc.d/rc.docker`'s
`docker_container_start()` starts each one when the docker service comes
up during array start. We don't hook `rc.docker` itself (that's core
OS, not ours to patch) — instead we use Unraid's own documented plugin
event mechanism: `/usr/local/sbin/emhttp_event <name>` is invoked by
`emhttpd` at each array lifecycle point and runs
`plugins/*/event/<name>/*` for any plugin that has a matching script.
`docker_started` ("Occurs during cmdStart execution. The docker service
is enabled and started.") is exactly the right timing — same moment
Unraid's own container autostart happens, confirmed by finding
`dynamix/event/docker_started/update_services` already using it.
`event/docker_started/autostart_stacks` reads each stack's `meta.json`
and runs `docker compose up -d` for any with `"autostart": true`.

Verified live: toggled autostart on for the `authentik` stack via the UI
switch (persists to `meta.json`), ran `docker compose down` to fully
remove its containers, then ran the event script directly (exactly what
`emhttp_event docker_started` would invoke) and confirmed it brought the
whole stack back up. Not yet re-verified with an actual array restart/
reboot in combination with the other two boot hooks (Docker-tab-hide,
compose-CLI self-heal) — worth doing together if being fully thorough.

## Data-loss bug: meta.json race condition — found and fixed 2026-07-13

User reported that every real stack's `logoUrl` had silently gone back to
blank after repeatedly reloading the Stacks page. Root-caused via SSH
(not guessed): checked every stack's `meta.json` mtime and content, found
all 11 rewritten to the exact default shape (`{"logoUrl": null,
"autostart": false}`, no `createdAt` even where one previously existed)
at the same moment — a shape and simultaneity that only makes sense as a
bulk reset, not individual edits. Two compounding bugs, both real:

1. **`webGui/javascript/jquery.switchbutton.js`'s own init sequence fires
   a synthetic `change` event on every checkbox it wraps, every single
   time it's initialized** — read the actual minified source:
   `_initLayout()` deliberately flips `options.checked` and calls
   `_toggleSwitch()` to sync the widget's visuals to the checkbox's real
   state, and `_toggleSwitch()` unconditionally calls
   `this.element.change()`. Since `loadList()` re-initializes
   `.switchButton()` on all 11 Autostart checkboxes on every single list
   refresh (including a plain page reload), this fired an `autostart`
   save for every stack, every time, regardless of whether the user
   touched anything.
2. **`meta.json`'s read-then-write had no file locking.** Those frequent,
   unnecessary concurrent saves (worsened directly by the user rapidly
   reloading the page, confirmed live: partial restores kept getting
   silently re-wiped seconds after being written, proving something was
   *actively* re-triggering, not a one-off historical event) could race:
   one request's `file_put_contents()` write landing in the middle of
   another's `file_get_contents()` read produced a torn, unparseable JSON
   body. `json_decode()` failed, silently fell back to the hardcoded
   defaults, and *that* got written straight back out — destroying real
   data with no error anywhere.

Data was fully recoverable: `backupPath` was configured, so
`stacksUI_backup_stack()` had already mirrored every stack's real
`meta.json` to `/mnt/user/backups/stacksUI/<name>/` before the
corruption. Restored by direct copy after confirming, via `diff`, that
backups matched current state on everything except the wiped fields.

Fixed both root causes rather than just restoring the symptom:
- `stacksUI.js`: the Autostart checkbox now carries the server-reported
  value in `data-autostart`, and the `change` handler compares against
  it before posting — a spurious/no-op event (checked === previous
  value) is now a no-op instead of a wasted (and, it turns out,
  dangerous) save.
- `StacksHelper.php`: every `meta.json` read-modify-write now happens
  inside one exclusive `flock()` critical section
  (`stacksUI_update_meta()`), and plain reads take a shared lock
  (`stacksUI_read_meta()`) — a concurrent write can no longer land in
  the middle of another request's read. `stacksUI_write_meta()` (a bare
  overwrite with no lock) is gone; every call site now goes through the
  locked mutator.

**Verified the fix actually holds under the exact failure condition**,
not just that the code looks right: fired 5 waves of 11 concurrent
`autostart` saves each (55 concurrent requests, deliberately reproducing
the rapid-refresh race) against all 11 real stacks and confirmed every
`logoUrl`/`createdAt` survived intact afterward - before this fix, this
exact test would have reproduced the wipe.

**How to apply:** any bundled third-party JS widget (jQuery UI-style
plugins especially) should be checked for whether its *init* path fires
the same events a real user action would - re-initializing a widget on
every re-render (as `loadList()` does here) turns "fires once on
mount" bugs into "fires on every single page load" bugs. Separately:
**any read-modify-write of a file that a concurrent request could also
touch needs a lock** - this project had gotten away with unlocked file
I/O for stack data up to now only because nothing had previously caused
concurrent writes to the same file at meaningful volume.

## Menu tab icon

`images/stacks.png` — 4 offset black square outlines on a transparent
background, generated programmatically (raw PNG encoding, no image
library dependency). Wired up via the `.plg`'s `icon="stacks.png"`
attribute so it shows on the Plugins page (see Versioning above); not
rendered for the top-nav tab itself in the classic theme (established
earlier — icons only show there for the sidebar theme or sub-content
tabs).
