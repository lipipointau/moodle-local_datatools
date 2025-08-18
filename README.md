# Data Tools (CLI) — local_datatools

Minimal CLI utilities for Moodle 3.x+ / Totara 17+.

## Anonymise users (names + emails)

Random realistic first/last names and safe emails (`username+id@domain`).

```bash
# Preview only (default).
php local/datatools/cli/anonymise_users.php --domain=example.com --dry-run=1

# Apply to all (force), excluding admins:
php local/datatools/cli/anonymise_users.php --domain=example.com --applyall=1 --dry-run=0
```

**Flags**
- `--domain` (string): target email domain (default `example.com`)
- `--includeadmins=1` to include site admins (default 0)
- `--exclude-domains="a.com,b.org"` to skip those current domains
- `--applyall=1` to overwrite even if already on the target domain
- `--dry-run=1` preview mode (default)

> Tip: Use a non-routable/test domain or disable outbound mail (`$CFG->noemailever = true;`) in non-prod.
