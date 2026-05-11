=== Propeller Embed ===
Contributors: propeller
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.9
License: GPL-2.0-or-later

Embeds a Propeller app in WordPress with third-party cookie guidance, retry support, admin settings, self-hosted auto-updates, per-instance isolation, and handshake validation.

== Installation ==
1. Upload the plugin ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to Settings → Propeller Embed.
4. Configure your iframe URL and, if you want auto-updates, your update JSON URL.
5. Use [propeller_embed] in a page or post.

== Auto-update JSON ==
Host plugin-update.json on your own HTTPS domain and keep these in sync:
- Plugin header Version
- ZIP filename / package URL
- JSON version
- JSON download_url

Suggested workflow:
1. Build a new ZIP with the same plugin folder name.
2. Upload the ZIP to a stable HTTPS URL.
3. Edit plugin-update.json and bump version.
4. Click “Check for updates now” in Settings → Propeller Embed.

== Changelog ==
= 1.3.9 =
* Added [propeller_embed_switcher] for true one-at-a-time loading.
* Added dynamic initialization support for embeds inserted after page load.
* Updated bundled and recommended JSON manifest author/homepage fields.

= 1.3.8 =
* Added redundant instance transport fields in handshake init payload for GTM fallback.
* Parent now accepts instanceId aliases in iframe messages for more resilient GTM integrations.

= 1.3.0 =
* Added full per-instance isolation for multiple embeds on one page.
* Added instance-specific debug panels with clear-log control.
* Added stricter postMessage source matching to prevent cross-instance interference.

= 1.2.0 =
* Added self-hosted auto-update support.
* Added update JSON settings.
* Added a “Check for updates now” button and update status panel.


Version: 1.3.9
- Added [propeller_embed_switcher] for true one-at-a-time loading.
- Added dynamic initialization support for embeds inserted after page load.
- Updated recommended and bundled JSON manifest author/homepage fields.
