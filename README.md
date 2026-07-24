# RAN Booster Bitbucket Cloud

The Bitbucket Cloud provider add-on for RAN Booster. It keeps the established
Bitbucket repository discovery, archive, credential, webhook and diagnostics
behaviour behind RAN Booster Provider API 5.

It requires RAN Booster with Provider API 5. When compatible, it registers the
existing `bb` provider in the normal provider band immediately after GitHub.
It does not access the Core container, sidecar path or Core storage directly.

## Licence

GPL-2.0-or-later.
