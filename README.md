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

## Menu tab icon

`images/stacks.png` — 4 offset black square outlines on a transparent
background, generated programmatically (raw PNG encoding, no image
library dependency). Wired up via the `.plg`'s `icon="stacks.png"`
attribute so it shows on the Plugins page (see Versioning above); not
rendered for the top-nav tab itself in the classic theme (established
earlier — icons only show there for the sidebar theme or sub-content
tabs).
