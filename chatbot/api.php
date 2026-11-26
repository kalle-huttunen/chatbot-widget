<?php
// Chatbot API endpoint.
// Takes the visitor's message and the earlier conversation as JSON, forwards
// them to OpenAI and stores any contact details the visitor shares.

declare(strict_types=1);

const OAC_MAX_BODY_BYTES = 262144;
const OAC_MAX_HISTORY = 50;          // earlier messages sent to the model; keep in sync with chatbot.js
const OAC_MAX_MESSAGE_LENGTH = 500;  // characters in a visitor message; keep in sync with chatbot.js
const OAC_MAX_REPLY_LENGTH = 2000;   // characters kept from an earlier assistant message
const OAC_RATE_LIMIT_MAX = 20;       // requests per window per IP address
const OAC_RATE_LIMIT_WINDOW = 60;    // seconds

// Texts by language. A language missing here uses the English texts.
const OAC_MESSAGES = [
    'en' => [
        'rate_limited' => 'Too many requests. Please try again shortly.',
        'empty' => 'Message cannot be empty.',
        'too_long' => 'Message is too long.',
        'unavailable' => 'The service is temporarily unavailable. Please try again later.',
        'lead_saved' => 'Note: The user just shared their contact details and they have been saved. Thank them briefly, tell them we will be in touch soon, and answer their question if they had one.',
        'lead_failed' => 'Note: The user just shared their contact details, but saving them failed. Apologise briefly and ask them to contact us directly using the contact information above.',
    ],
    'fi' => [
        'rate_limited' => 'Liian monta pyyntöä. Yritä hetken kuluttua uudelleen.',
        'empty' => 'Viesti ei voi olla tyhjä.',
        'too_long' => 'Viesti on liian pitkä.',
        'unavailable' => 'Palvelussa on väliaikainen ongelma. Yritä myöhemmin uudelleen.',
        'lead_saved' => 'Huomautus: Käyttäjä antoi juuri yhteystietonsa ja ne on tallennettu. Kiitä lyhyesti, kerro että olemme pian yhteydessä, ja vastaa myös hänen mahdolliseen kysymykseensä.',
        'lead_failed' => 'Huomautus: Käyttäjä antoi juuri yhteystietonsa, mutta niiden tallennus epäonnistui. Pahoittele lyhyesti ja pyydä häntä ottamaan yhteyttä suoraan yllä olevilla yhteystiedoilla.',
    ],
];

function oac_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function oac_fail(int $status, string $message): void
{
    oac_respond($status, ['success' => false, 'error' => $message]);
}

function oac_text(string $lang, string $key): string
{
    return (OAC_MESSAGES[$lang] ?? OAC_MESSAGES['en'])[$key];
}

// "en-US" and "EN" both resolve to "en". A language without a system prompt
// in config.php resolves to OAC_DEFAULT_LANG.
function oac_lang($requested): string
{
    if (is_string($requested) && preg_match('/^([a-z]{2,3})(?:[-_]|$)/i', $requested, $m)) {
        $lang = strtolower($m[1]);
        if (isset(OAC_SYSTEM_PROMPTS[$lang])) {
            return $lang;
        }
    }
    return OAC_DEFAULT_LANG;
}

// Fixed-window counter per IP address, kept in a locked file so that
// concurrent requests cannot both read the same count.
function oac_rate_limit_ok(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/oac_rates';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create rate limit directory ' . $dir);
    }

    $now = time();

    // Occasionally remove counters whose window has passed.
    if (random_int(1, 100) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $now - OAC_RATE_LIMIT_WINDOW) {
                @unlink($file);
            }
        }
    }

    $handle = fopen($dir . '/' . hash('sha256', $ip) . '.json', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('cannot open rate limit file in ' . $dir);
    }

    $data = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($data) || !isset($data['count'], $data['expires']) || $data['expires'] <= $now) {
        $data = ['count' => 0, 'expires' => $now + OAC_RATE_LIMIT_WINDOW];
    }
    $data['count']++;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $data['count'] <= OAC_RATE_LIMIT_MAX;
}

// The history comes from the browser, so nothing in it is trusted: only user
// and assistant messages pass, each cut to a maximum length, newest last.
function oac_clean_history($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $clean = [];
    foreach (array_slice($raw, -OAC_MAX_HISTORY) as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = $msg['role'] ?? null;
        $content = $msg['content'] ?? null;
        if (!is_string($content) || !in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        $content = trim($content);
        if ($content === '') {
            continue;
        }
        $limit = $role === 'user' ? OAC_MAX_MESSAGE_LENGTH : OAC_MAX_REPLY_LENGTH;
        $clean[] = ['role' => $role, 'content' => mb_substr($content, 0, $limit)];
    }

    return $clean;
}

// Returns [email, phone], each null when the message has none.
function oac_extract_contact(string $message): array
{
    $email = null;
    $phone = null;

    if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $message, $m)) {
        $email = filter_var($m[0], FILTER_VALIDATE_EMAIL) ?: null;
    }

    if (preg_match(OAC_PHONE_PATTERN, $message, $m)) {
        $number = preg_replace('/[^\d+]/', '', $m[0]);
        $digits = strlen(ltrim($number, '+'));
        if ($digits >= 7 && $digits <= 15) {
            $phone = $number;
        }
    }

    return [$email, $phone];
}

// Sends a JSON request with a bearer token. Returns [HTTP status, body];
// the status is 0 when the request did not complete.
function oac_http_json(string $method, string $url, string $token, array $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('[OAC] ' . $method . ' ' . $url . ' failed: ' . curl_error($ch));
        return [0, ''];
    }

    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $response];
}

// Returns the decoded response, or null when HubSpot did not accept the request.
function oac_hubspot(string $method, string $path, array $body): ?array
{
    [$status, $response] = oac_http_json($method, 'https://api.hubapi.com' . $path, OAC_HUBSPOT_TOKEN, $body, 15);
    if ($status < 200 || $status >= 300) {
        error_log('[OAC] HubSpot ' . $method . ' ' . $path . ' HTTP ' . $status . ': ' . substr($response, 0, 500));
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

// Updates the contact that already has this email (or this phone number when
// no email was given), otherwise creates a new contact.
function oac_save_to_hubspot(?string $email, ?string $phone): bool
{
    $properties = array_filter(['email' => $email, 'phone' => $phone]);
    $key = $email !== null ? 'email' : 'phone';

    $found = oac_hubspot('POST', '/crm/v3/objects/contacts/search', [
        'filterGroups' => [['filters' => [
            ['propertyName' => $key, 'operator' => 'EQ', 'value' => $properties[$key]],
        ]]],
        'limit' => 1,
    ]);
    if ($found === null) {
        return false;
    }

    $id = $found['results'][0]['id'] ?? null;
    $saved = $id !== null
        ? oac_hubspot('PATCH', '/crm/v3/objects/contacts/' . rawurlencode((string) $id), ['properties' => $properties])
        : oac_hubspot('POST', '/crm/v3/objects/contacts', ['properties' => $properties]);

    return $saved !== null;
}

function oac_send_notification(?string $email, ?string $phone): bool
{
    $body = "New contact details from the chatbot:\n\n"
        . 'Email: ' . ($email ?? 'not given') . "\n"
        . 'Phone: ' . ($phone ?? 'not given') . "\n";
    $headers = 'From: ' . OAC_NOTIFICATION_FROM . "\r\nContent-Type: text/plain; charset=UTF-8";

    return mail(OAC_NOTIFICATION_EMAIL, 'Chatbot: new contact details', $body, $headers);
}

// True when the contact details reached HubSpot or the notification mailbox.
function oac_store_lead(?string $email, ?string $phone): bool
{
    $stored = false;

    if (OAC_HUBSPOT_TOKEN !== '') {
        $stored = oac_save_to_hubspot($email, $phone);
    }

    if (OAC_NOTIFICATION_EMAIL !== '') {
        $sent = oac_send_notification($email, $phone);
        if (!$sent) {
            error_log('[OAC] the notification mail was not accepted for delivery');
        }
        $stored = $stored || $sent;
    }

    if (!$stored) {
        error_log('[OAC] contact details were not stored: no working HubSpot token or notification address');
    }

    return $stored;
}

// Returns the reply text, or null when OpenAI gave none.
function oac_query_openai(array $messages): ?string
{
    $body = [
        'model' => OAC_OPENAI_MODEL,
        'messages' => $messages,
        'max_completion_tokens' => OAC_OPENAI_MAX_TOKENS,
    ];
    if (OAC_OPENAI_REASONING_EFFORT !== '') {
        $body['reasoning_effort'] = OAC_OPENAI_REASONING_EFFORT;
    }

    [$status, $response] = oac_http_json('POST', 'https://api.openai.com/v1/chat/completions', OAC_OPENAI_API_KEY, $body, 30);
    if ($status !== 200) {
        error_log('[OAC] OpenAI HTTP ' . $status . ': ' . substr($response, 0, 500));
        return null;
    }

    $reply = json_decode($response, true)['choices'][0]['message']['content'] ?? null;
    if (!is_string($reply) || trim($reply) === '') {
        error_log('[OAC] OpenAI returned no reply text: ' . substr($response, 0, 500));
        return null;
    }

    return trim($reply);
}

// ============================
// Handle the request
// ============================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        oac_fail(405, 'Method not allowed');
    }

    // A JSON content type cannot be sent cross-site without a CORS preflight,
    // and this endpoint answers none, so other sites cannot use it from a browser.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        oac_fail(415, 'Content-Type must be application/json');
    }

    $body = file_get_contents('php://input', false, null, 0, OAC_MAX_BODY_BYTES + 1);
    if ($body === false || strlen($body) > OAC_MAX_BODY_BYTES) {
        oac_fail(413, 'Request is too large');
    }

    $input = json_decode($body, true);
    if (!is_array($input)) {
        oac_fail(400, 'Request body must be a JSON object');
    }

    if (!is_file(__DIR__ . '/config.php')) {
        throw new RuntimeException('config.php is missing: copy config.example.php and fill it in');
    }
    require __DIR__ . '/config.php';

    if (OAC_OPENAI_API_KEY === '') {
        throw new RuntimeException('OAC_OPENAI_API_KEY is empty in config.php');
    }
    if (!isset(OAC_SYSTEM_PROMPTS[OAC_DEFAULT_LANG])) {
        throw new RuntimeException('OAC_SYSTEM_PROMPTS has no prompt for OAC_DEFAULT_LANG');
    }

    $lang = oac_lang($input['lang'] ?? null);

    if (!oac_rate_limit_ok($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        oac_fail(429, oac_text($lang, 'rate_limited'));
    }

    $message = $input['message'] ?? null;
    $message = is_string($message) ? trim($message) : '';
    if ($message === '') {
        oac_fail(400, oac_text($lang, 'empty'));
    }
    if (mb_strlen($message) > OAC_MAX_MESSAGE_LENGTH) {
        oac_fail(400, oac_text($lang, 'too_long'));
    }

    $messages = array_merge(
        [['role' => 'system', 'content' => OAC_SYSTEM_PROMPTS[$lang]]],
        oac_clean_history($input['conversation'] ?? null),
        [['role' => 'user', 'content' => $message]]
    );

    // Contact details are stored before the model is asked, so a failed
    // model call does not lose them.
    [$email, $phone] = oac_extract_contact($message);
    if ($email !== null || $phone !== null) {
        $stored = oac_store_lead($email, $phone);
        $messages[] = ['role' => 'system', 'content' => oac_text($lang, $stored ? 'lead_saved' : 'lead_failed')];
    }

    $reply = oac_query_openai($messages);
    if ($reply === null) {
        oac_fail(502, oac_text($lang, 'unavailable'));
    }

    oac_respond(200, ['success' => true, 'data' => ['reply' => $reply]]);
} catch (Throwable $e) {
    error_log('[OAC] ' . $e->getMessage());
    oac_fail(500, OAC_MESSAGES['en']['unavailable']);
}
