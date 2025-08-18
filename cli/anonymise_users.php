<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'domain'          => 'example.com',
        'includeadmins'   => false,
        'exclude-domains' => '',
        'applyall'        => false,
        'dry-run'         => true,
        'help'            => false,
    ],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Anonymise user data (realistic first/last names) and set emails to username+id@domain.\n";
    echo "USAGE:\n";
    echo "  php local/datatools/cli/anonymise_users.php [--domain=example.com] [--includeadmins=0|1]\n";
    echo "                         [--exclude-domains=\"foo.com,bar.org\"] [--applyall=0|1]\n";
    echo "                         [--dry-run=0|1]\n\n";
    echo "BEHAVIOUR:\n";
    echo "  - --exclude-domains: skip users whose CURRENT email domain matches any listed domain.\n";
    echo "  - --applyall=1: force-set domain for everyone (except excluded/admins).\n";
    echo "  - --dry-run=1: preview without changes.\n";
    exit(0);
}

$domain        = trim((string)($options['domain'] ?? 'example.com'));
$includeadmins = !empty($options['includeadmins']);
$dryrun        = !empty($options['dry-run']);
$applyall      = !empty($options['applyall']);

$excludedomainraw = trim((string)($options['exclude-domains'] ?? ''));
$excludedomains = array_filter(array_map(function($d) {
    return strtolower(trim($d));
}, $excludedomainraw === '' ? [] : explode(',', $excludedomainraw)));

if (!preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $domain)) {
    cli_error("Invalid --domain: {$domain}");
}

$firstnames = ['James','Mary','John','Patricia','Robert','Jennifer','Michael','Linda','William','Elizabeth','David','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Charles','Karen','Daniel','Nancy','Matthew','Lisa','Anthony','Betty','Mark','Margaret','Donald','Sandra','Andrew','Emily','Joshua','Olivia','Ryan','Sophia','Ethan','Chloe','Liam','Ava'];
$lastnames  = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores'];

$adminids = [];
if (!$includeadmins) {
    foreach (get_admins() as $a) {
        $adminids[] = (int)$a->id;
    }
}

$select = '';
$params = [];
if (!$includeadmins && $adminids) {
    list($notin, $inparams) = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'adm', false);
    $select = "id $notin";
    $params = $inparams;
}

$rs = $DB->get_recordset_select('user', $select, $params, 'id ASC',
    'id, username, firstname, lastname, email');

$checked = 0;
$updated = 0;
$skipped_excludedomain = 0;
$skipped_samedomain = 0;

$transaction = null;
if (!$dryrun) {
    $transaction = $DB->start_delegated_transaction();
}

foreach ($rs as $u) {
    $checked++;

    $currdomain = '';
    if (is_string($u->email) && strpos($u->email, '@') !== false) {
        $currdomain = strtolower(substr(strrchr($u->email, '@'), 1));
    }

    if ($currdomain !== '' && in_array($currdomain, $excludedomains, true)) {
        $skipped_excludedomain++;
        continue;
    }

    $newfirstname = $firstnames[array_rand($firstnames)];
    $newlastname  = $lastnames[array_rand($lastnames)];

    $local = strtolower(preg_replace('/[^a-z0-9._%+\-]/', '.', (string)$u->username));
    $local = trim($local, '.');
    if ($local === '') { $local = 'user'; }

    if (!$applyall && $currdomain === strtolower($domain)) {
        $skipped_samedomain++;
        $newemail = $u->email;
    } else {
        $newemail = $local . '+' . $u->id . '@' . $domain;
        if (!validate_email($newemail)) {
            cli_problem("Generated invalid email for user {$u->id}: {$newemail} (skipping email update)");
            $newemail = $u->email;
        }
    }

    if ($u->firstname === $newfirstname && $u->lastname === $newlastname && $u->email === $newemail) {
        continue;
    }

    if (!$dryrun) {
        $DB->update_record('user', (object)[
            'id'        => $u->id,
            'firstname' => $newfirstname,
            'lastname'  => $newlastname,
            'email'     => $newemail,
        ]);
    }

    $updated++;
}
$rs->close();

if ($dryrun) {
    echo "DRY RUN SUMMARY\n";
    echo "  Checked:           {$checked}\n";
    echo "  Would update:      {$updated}\n";
    echo "  Skipped (excluded domains): {$skipped_excludedomain}\n";
    echo "  Skipped (already on target domain, applyall=0): {$skipped_samedomain}\n";
} else {
    $transaction->allow_commit();
    echo "DONE\n";
    echo "  Checked:           {$checked}\n";
    echo "  Updated:           {$updated}\n";
    echo "  Skipped (excluded domains): {$skipped_excludedomain}\n";
    echo "  Skipped (already on target domain, applyall=0): {$skipped_samedomain}\n";
    echo "Tip: run 'php admin/cli/purge_caches.php' to clear caches.\n";
}
