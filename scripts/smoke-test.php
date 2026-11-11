<?php

/**
 * End-to-end check of a RUNNING copy of The Lobby, from the outside, over real HTTP(S).
 *
 *   php scripts/smoke-test.php https://your-site.example            (about 40 seconds)
 *   php scripts/smoke-test.php https://your-site.example --quick    (skips waiting for a hold to expire)
 *
 * It behaves like real people: creates a throw-away trip ("Smoke test (automated)"), joins five people,
 * makes all five grab the SAME bed at the SAME moment (exactly one may win), confirms a bed, lets a
 * hold expire, uploads a file to the vault, opens it through the 60-second link, checks a stranger is
 * refused, books a pretend hotel and sends it to the trip (approval, change, cancellation, forged webhooks),
 * ends the trip and checks the file is really gone.
 *
 * No Laravel needed: only PHP with the curl extension. The throw-away trip stays in the database
 * (marked ended, files deleted), like every trip, because the audit log is never erased.
 * Join/create are limited to 10 requests per minute per IP, so wait a minute between runs.
 */
$base = rtrim($argv[1] ?? '', '/');
$quick = in_array('--quick', $argv, true);
if ($base === '') {
    fwrite(STDERR, "Usage: php scripts/smoke-test.php <base-url> [--quick]\n");
    exit(2);
}

$fails = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $fails;
    $ok || $fails++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $extra !== '' ? "  ($extra)" : '');
}

/** One "browser": its own cookie jar, so each Client is a different person. */
final class Client
{
    private string $jar;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'lobbysmoke');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function handle(string $method, string $url, array $headers = [], $body = null, bool $cookies = true)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, // we want to SEE redirects, not follow them
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return $ch;
    }

    public static function result($ch, string $raw): array
    {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => substr($raw, $headerSize)];
    }

    public function request(string $method, string $url, array $headers = [], $body = null, bool $cookies = true): array
    {
        $ch = $this->handle($method, $url, $headers, $body, $cookies);
        $raw = curl_exec($ch);
        if ($raw === false) {
            fwrite(STDERR, 'Network error: '.curl_error($ch)."\n");
            exit(2);
        }

        return self::result($ch, $raw);
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    public function form(string $url, array $fields): array
    {
        return $this->request('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** The same call the lobby screen's JavaScript makes. */
    public function jsonPost(string $url, string $csrf): array
    {
        return $this->request('POST', $url, self::jsonHeaders($csrf), '');
    }

    public static function jsonHeaders(string $csrf): array
    {
        return ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'X-CSRF-TOKEN: '.$csrf];
    }

    public function cookieHandle(string $url, string $csrf)
    {
        return $this->handle('POST', $url, self::jsonHeaders($csrf), '');
    }
}

function csrf(string $html): string
{
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function codeFromLocation(?string $location): string
{
    return preg_match('#/t/([A-Za-z0-9]{8})#', (string) $location, $m) ? $m[1] : '';
}

/** @return array<int, array{id:int,status:string,seconds_left:?int}> */
function beds(array $state): array
{
    $out = [];
    foreach ($state['rooms'] as $room) {
        foreach ($room['beds'] as $bed) {
            $out[] = $bed;
        }
    }

    return $out;
}

echo "Smoke test of $base\n\n";

// ---- 1. the site answers, over HTTPS if given ----------------------------------------------------------
$org = new Client;
$home = $org->get($base.'/');
check('home page answers 200', $home['status'] === 200, 'HTTP '.$home['status']);
check('home page has the CSRF token', csrf($home['body']) !== '');
if ($home['status'] !== 200) {
    exit(1);
}
if (str_starts_with($base, 'https://')) {
    $plain = (new Client)->get('http://'.substr($base, 8).'/');
    check('plain http redirects to https', in_array($plain['status'], [301, 302, 308], true) && str_starts_with($plain['headers']['location'] ?? '', 'https://'));
}

// ---- 2. create a trip ----------------------------------------------------------------------------------
$created = $org->form($base.'/trips', [
    '_token' => csrf($home['body']),
    'trip_name' => 'Smoke test (automated)',
    'organiser_name' => 'Smoke Organiser',
    'starts_on' => date('Y-m-d'),
    'ends_on' => date('Y-m-d', strtotime('+2 days')),
    'rooms' => "Room 1: Window, Door, Couch\nRoom 2: Left, Right",
]);
$code = codeFromLocation($created['headers']['location'] ?? '');
check('create trip redirects into the new trip', $created['status'] === 302 && $code !== '', $code !== '' ? "code $code" : 'HTTP '.$created['status']);
if ($code === '') {
    echo "\nStopping: cannot continue without a trip. ".($created['status'] === 429 ? 'Rate limited: wait a minute.' : '')."\n";
    exit(1);
}
$trip = $base.'/t/'.$code;
$lobby = $org->get($trip);
$orgToken = csrf($lobby['body']);
check('organiser sees the lobby', $lobby['status'] === 200 && $orgToken !== '');
$state = json_decode($org->get($trip.'/state', ['Accept: application/json'])['body'], true);
$all = beds($state);
check('lobby state lists 5 free beds', count($all) === 5 && count(array_filter($all, fn ($b) => $b['status'] === 'free')) === 5);
$contested = $all[0]['id'];

// ---- 3. five people join -------------------------------------------------------------------------------
$people = [];
for ($i = 1; $i <= 5; $i++) {
    $c = new Client;
    $page = $c->get($base.'/join/'.$code);
    $join = $c->form($base.'/join', ['_token' => csrf($page['body']), 'code' => $code, 'name' => "Smoke $i"]);
    $ok = $join['status'] === 302 && str_contains($join['headers']['location'] ?? '', '/t/'.$code);
    $my = $ok ? $c->get($trip) : ['status' => 0, 'body' => ''];
    $people[$i] = ['client' => $c, 'token' => csrf($my['body'])];
    if (! $ok || $my['status'] !== 200) {
        check("person $i joins", false, 'join HTTP '.$join['status']);
    }
}
check('five people joined and can see the lobby', count(array_filter($people, fn ($p) => $p['token'] !== '')) === 5);

// ---- 4. THE RACE: five requests for the same bed at the same instant -----------------------------------
$multi = curl_multi_init();
$handles = [];
foreach ($people as $i => $p) {
    $ch = $p['client']->cookieHandle($trip.'/beds/'.$contested.'/grab', $p['token']);
    curl_multi_add_handle($multi, $ch);
    $handles[$i] = $ch;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);
} while ($running > 0);
$wins = [];
$statuses = [];
foreach ($handles as $i => $ch) {
    $res = Client::result($ch, curl_multi_getcontent($ch));
    $statuses[] = $res['status'];
    if ($res['status'] === 200 && (json_decode($res['body'], true)['ok'] ?? false)) {
        $wins[] = $i;
    }
    curl_multi_remove_handle($multi, $ch);
}
check('RACE: exactly one of five wins the same bed', count($wins) === 1, 'statuses '.implode(',', $statuses));
$winner = $wins[0] ?? 1;
$winnerClient = $people[$winner]['client'];
$winnerToken = $people[$winner]['token'];

// ---- 5. confirm, then a hold that expires ----------------------------------------------------------------
$confirm = $winnerClient->jsonPost($trip.'/beds/'.$contested.'/confirm', $winnerToken);
check('winner confirms the bed', $confirm['status'] === 200 && (json_decode($confirm['body'], true)['state']['me']['taken_label'] ?? null) !== null);
$late = $people[$winner === 1 ? 2 : 1];
$steal = $late['client']->jsonPost($trip.'/beds/'.$contested.'/grab', $late['token']);
check('a confirmed bed cannot be grabbed by someone else', $steal['status'] === 409);

$other = $all[1]['id'];
$hold = $late['client']->jsonPost($trip.'/beds/'.$other.'/grab', $late['token']);
$holdState = json_decode($hold['body'], true)['state'] ?? null;
$left = null;
foreach ($holdState ? beds($holdState) : [] as $b) {
    if ($b['id'] === $other) {
        $left = $b['seconds_left'];
    }
}
check('grabbing a free bed holds it with a countdown', $hold['status'] === 200 && $left !== null, "seconds_left $left");
if ($quick || $left === null) {
    echo "SKIP  hold expiry (--quick)\n";
} else {
    echo "      waiting {$left}s + 3s for the hold to run out...\n";
    sleep($left + 3);
    $after = json_decode($org->get($trip.'/state', ['Accept: application/json'])['body'], true);
    $status = null;
    foreach (beds($after) as $b) {
        $b['id'] === $other && $status = $b['status'];
    }
    check('the hold expired and the bed is free again', $status === 'free', "status $status");
}

// ---- 6. the vault ----------------------------------------------------------------------------------------
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\nsmoke-".bin2hex(random_bytes(4))."\n";
$tmp = tempnam(sys_get_temp_dir(), 'smokepdf');
file_put_contents($tmp, $pdf);
$vaultPage = $winnerClient->get($trip.'/vault');
$up = $winnerClient->request('POST', $trip.'/vault', [], ['_token' => csrf($vaultPage['body']), 'file' => new CURLFile($tmp, 'application/pdf', 'smoke ticket.pdf')]);
unlink($tmp);
check('member uploads a PDF', $up['status'] === 302, 'HTTP '.$up['status']);
$list = $winnerClient->get($trip.'/vault');
preg_match('#/vault/(\d+)/open#', $list['body'], $m);
$fileId = $m[1] ?? '';
check('the file is listed in the vault', $fileId !== '' && str_contains($list['body'], 'smoke ticket.pdf'));

$open = $winnerClient->get($trip.'/vault/'.$fileId.'/open');
$link = $open['headers']['location'] ?? '';
check('open sends the member to a signed link', $open['status'] === 302 && str_contains($link, 'X-Amz-Signature'));
check('the link lives 60 seconds', str_contains($link, 'X-Amz-Expires=60'));
check('the browser is told not to cache it', str_contains(strtolower($open['headers']['cache-control'] ?? ''), 'no-store'));
$fetched = (new Client)->request('GET', $link, [], null, false);
check('the link returns the exact file', $fetched['status'] === 200 && $fetched['body'] === $pdf, 'HTTP '.$fetched['status']);
$bare = (new Client)->request('GET', strtok($link, '?'), [], null, false);
check('the same URL without the signature is refused (bucket is private)', $bare['status'] === 403, 'HTTP '.$bare['status']);
$stranger = (new Client)->get($trip.'/vault/'.$fileId.'/open');
check('a stranger with no login is NOT sent to the file', $stranger['status'] === 302 && ! str_contains($stranger['headers']['location'] ?? '', 'amazonaws'));

// ---- 6b. the pretend booking sites and the booking connection ---------------------------------------------
$demoHome = (new Client)->get($base.'/demo');
if ($demoHome['status'] === 404) {
    echo "SKIP  the pretend booking sites are switched off on this site (DEMO_PARTNERS=false)\n";
} else {
    $bedCount = fn () => count(beds(json_decode($org->get($trip.'/state', ['Accept: application/json'])['body'], true)));
    $beforeBeds = $bedCount();

    $site = new Client;
    $stayForm = $site->get($base.'/demo/stay');
    $book = $site->form($base.'/demo/stay', [
        '_token' => csrf($stayForm['body']), 'hotel' => 'sunrise', 'room_type' => 'Sea view',
        'check_in' => date('Y-m-d'), 'check_out' => date('Y-m-d', strtotime('+2 days')), 'rooms' => '2', 'guests' => '2',
    ]);
    preg_match('#/demo/stay/(STAY-[A-Z0-9]{6})#', $book['headers']['location'] ?? '', $bm);
    $ref = $bm[1] ?? '';
    check('the pretend hotel site takes a booking', $book['status'] === 302 && $ref !== '', $ref);

    if ($ref !== '') {
        $shown = $site->get($base.'/demo/stay/'.$ref);
        $send = $site->form($base.'/demo/stay/'.$ref.'/send', ['_token' => csrf($shown['body']), 'trip_code' => $code]);
        check('the booking is sent to the trip', $send['status'] === 302, 'HTTP '.$send['status']);

        $plan = $org->get($trip.'/plan');
        check('the organiser sees it waiting for approval', str_contains($plan['body'], $ref) && str_contains($plan['body'], 'Waiting for you'));
        check('nothing was added to the lobby before approval', $bedCount() === $beforeBeds);

        $member = $people[1]['client']->get($trip.'/plan');
        $memberBtn = $people[1]['client']->form($trip.'/bookings/0/accept', ['_token' => csrf($member['body'])]);
        check('an ordinary member cannot approve a booking', $memberBtn['status'] === 403, 'HTTP '.$memberBtn['status']);

        preg_match('#/bookings/(\d+)/accept#', $plan['body'], $am);
        $accept = $org->form($trip.'/bookings/'.($am[1] ?? '0').'/accept', ['_token' => csrf($plan['body'])]);
        check('the organiser adds it to the trip', $accept['status'] === 302, 'HTTP '.$accept['status']);
        check('two hotel rooms with four beds appeared in the lobby', $bedCount() === $beforeBeds + 4, 'beds '.$bedCount());

        $shown2 = $site->get($base.'/demo/stay/'.$ref);
        $change = $site->form($base.'/demo/stay/'.$ref.'/change', [
            '_token' => csrf($shown2['body']), 'hotel' => 'aurora', 'room_type' => 'Garden view',
            'check_in' => date('Y-m-d'), 'check_out' => date('Y-m-d', strtotime('+3 days')), 'rooms' => '3', 'guests' => '2',
        ]);
        check('a change on the hotel site is accepted', $change['status'] === 302, 'HTTP '.$change['status']);
        check('the change reached the trip by itself (3 rooms now)', $bedCount() === $beforeBeds + 6, 'beds '.$bedCount());
        $plan2 = $org->get($trip.'/plan');
        check('the plan shows the new hotel', str_contains($plan2['body'], 'Beach Villa Aurora'));

        $forged = (new Client)->request('POST', $base.'/webhooks/stay', ['Content-Type: application/json', 'X-Lobby-Timestamp: '.time(), 'X-Lobby-Signature: sha256='.str_repeat('0', 64)], json_encode(['trip_code' => $code, 'booking' => ['booking_ref' => 'STAY-FORGED']]), false);
        check('a forged webhook is refused (401)', $forged['status'] === 401, 'HTTP '.$forged['status']);
        $unsigned = (new Client)->request('POST', $base.'/webhooks/stay', ['Content-Type: application/json'], '{}', false);
        check('an unsigned webhook is refused (401)', $unsigned['status'] === 401, 'HTTP '.$unsigned['status']);

        $shown3 = $site->get($base.'/demo/stay/'.$ref);
        $cancel = $site->form($base.'/demo/stay/'.$ref.'/cancel', ['_token' => csrf($shown3['body'])]);
        check('cancelling on the hotel site is accepted', $cancel['status'] === 302, 'HTTP '.$cancel['status']);
        check('the cancellation removed the free rooms from the trip', $bedCount() === $beforeBeds, 'beds '.$bedCount());
    }
}

// ---- 7. end the trip: everything is deleted --------------------------------------------------------------
$vaultAgain = $org->get($trip.'/vault');
$end = $org->form($trip.'/end-trip', ['_token' => csrf($vaultAgain['body'])]);
check('organiser ends the trip', $end['status'] === 302, 'HTTP '.$end['status']);
$closed = $org->get($trip.'/vault');
check('the vault page says the trip has ended', str_contains($closed['body'], 'This trip has ended'));
$dead = (new Client)->request('GET', $link, [], null, false);
check('the old link is dead: the file was deleted from storage', in_array($dead['status'], [403, 404], true), 'HTTP '.$dead['status']);
$late2 = $winnerClient->get($trip.'/vault/'.$fileId.'/open');
check('opening the file after the end gives no link', ! str_contains($late2['headers']['location'] ?? '', 'amazonaws'));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails CHECK(S) FAILED\n";
exit($fails === 0 ? 0 : 1);
