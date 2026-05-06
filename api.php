<?php
/**
 * PrivaScan - Backend API Handler (v2 — Two-Step Architecture)
 * =============================================================
 * Step 1: Auto-Discovery — Accept a company name OR generic URL, ask Gemini
 *         to find the official Privacy Policy URL.
 * Step 2: Scrape & Analyze — Fetch the discovered URL, extract text, and
 *         call Gemini again to produce the structured Privacy Nutrition Label.
 *
 * HOW TO SET YOUR API KEY:
 *   1. Go to https://aistudio.google.com/app/apikey and create a free Gemini API key.
 *   2. Copy config.example.php → config.php and paste your key inside it.
 */

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

// Load API key from config.php (excluded from Git via .gitignore)
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode([
        'error' => 'config.php not found. Copy config.example.php to config.php and add your Gemini API key.',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

define(
    'GEMINI_API_URL',
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY
);

define('MAX_TEXT_LENGTH', 15000);   // Max characters sent to Gemini
define('SCRAPE_TIMEOUT', 20);      // cURL timeout (seconds)
define('GEMINI_TIMEOUT', 60);      // Gemini API cURL timeout (seconds)

// ─── CORS / HEADERS ───────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Only POST is accepted.']);
    exit;
}

// ─── INPUT VALIDATION ─────────────────────────────────────────────────────────

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

// Accept both JSON body and classic POST form field — now keyed as "query"
$userInput = $body['query'] ?? ($body['url'] ?? ($_POST['query'] ?? ($_POST['url'] ?? '')));
$userInput = trim($userInput);

if (empty($userInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'No input provided. Please enter a company name or privacy policy URL.']);
    exit;
}

// ─── HELPER: Determine if the input already looks like a full URL ─────────────

/**
 * Returns true if the input is a valid http(s) URL.
 */
function isDirectUrl(string $input): bool
{
    return (bool) filter_var($input, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($input, PHP_URL_SCHEME)), ['http', 'https'], true);
}

/**
 * Returns true if the input looks like a bare domain (e.g. "apple.com",
 * "spotify.co.uk") rather than a company name like "Netflix".
 */
function looksLikeDomain(string $input): bool
{
    // Must contain a dot, no spaces, and match a basic domain pattern
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/', $input);
}

// ─── FUNCTION: Generic cURL helper for Gemini calls ──────────────────────────

/**
 * Sends a prompt to the Gemini API and returns the raw model text output.
 *
 * @param  string $prompt     The full prompt text (system + user combined).
 * @param  float  $temperature  Controls randomness (lower = more deterministic).
 * @return string             The model's text response.
 * @throws RuntimeException   On network or API errors.
 */
function callGeminiRaw(string $prompt, float $temperature = 0.1): string
{
    $requestPayload = [
        'contents' => [
            [
                'role'  => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature'    => $temperature,
            'topP'           => 0.8,
            'maxOutputTokens' => 8192,
        ],
    ];

    $jsonPayload = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => GEMINI_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_TIMEOUT        => GEMINI_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,    // Set to true in production (XAMPP lacks CA bundle)
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curlErr)) {
        throw new RuntimeException("Failed to reach Gemini API: {$curlErr}");
    }

    if ($httpCode !== 200) {
        $errDetail = json_decode($response, true)['error']['message'] ?? 'Unknown API error';
        throw new RuntimeException("Gemini API returned HTTP {$httpCode}: {$errDetail}");
    }

    $geminiResponse = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Gemini returned invalid JSON in its outer response envelope.');
    }

    $modelText = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (empty($modelText)) {
        $finishReason = $geminiResponse['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        throw new RuntimeException("Gemini returned an empty response. Finish reason: {$finishReason}");
    }

    return trim($modelText);
}

// ─── STEP 1: Auto-Discovery — Find the Privacy Policy URL ────────────────────

/**
 * Asks Gemini to locate the official privacy policy URL for the given input.
 * The input can be a company name ("Netflix"), a bare domain ("apple.com"),
 * or even a full website URL ("https://spotify.com").
 *
 * @param  string $input  The user-provided company name or website.
 * @return string         A valid http(s) privacy policy URL.
 * @throws RuntimeException  If the URL cannot be determined.
 */
function discoverPrivacyPolicyUrl(string $input): string
{
    $discoveryPrompt = <<<PROMPT
You are a URL discovery assistant. Your ONLY job is to find the official, current privacy policy URL for a company or website.

Company or website to look up: {$input}

Rules:
- Reply with ONLY the full URL starting with http:// or https://.
- The URL must point directly to the privacy policy page, not a homepage or terms page.
- Do NOT add any conversational text, markdown, explanation, or quotation marks.
- If you cannot confidently find the official privacy policy URL, reply with exactly: NOT_FOUND
- Prefer the primary/global English version of the privacy policy.
- Common patterns: /privacy, /privacy-policy, /legal/privacy, /about/privacy
PROMPT;

    $result = callGeminiRaw($discoveryPrompt, 0.0);

    // Clean up the response — strip whitespace, quotes, markdown artifacts
    $result = trim($result, " \t\n\r\0\x0B\"'`");

    // Validate the returned URL
    if (
        stripos($result, 'NOT_FOUND') !== false
        || !filter_var($result, FILTER_VALIDATE_URL)
        || !in_array(strtolower(parse_url($result, PHP_URL_SCHEME)), ['http', 'https'], true)
    ) {
        throw new RuntimeException(
            "Could not automatically locate the privacy policy for \"{$input}\". "
            . "Please try providing a direct privacy policy URL instead."
        );
    }

    return $result;
}

// ─── FUNCTION: Scrape raw HTML with cURL ──────────────────────────────────────

/**
 * Fetches the HTML content of a given URL using cURL.
 * Sets a realistic browser User-Agent to avoid basic bot-detection.
 *
 * @param  string $url   The target URL to fetch.
 * @return string        Raw HTML string on success.
 * @throws RuntimeException if the request fails or returns non-200 status.
 */
function scrapeUrl(string $url): string
{
    $ch = curl_init();

    // Extract the host for a realistic Referer header
    $parsedHost = parse_url($url, PHP_URL_HOST) ?? '';

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => SCRAPE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Sec-CH-UA: "Chromium";v="126", "Google Chrome";v="126", "Not-A.Brand";v="8"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "Windows"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'Referer: https://' . $parsedHost . '/',
        ],
        CURLOPT_COOKIEFILE     => '',   // Enable cookie engine (in-memory)
    ]);

    $html     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($html === false || !empty($curlErr)) {
        throw new RuntimeException("cURL error while fetching URL: {$curlErr}");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("SCRAPE_BLOCKED:{$httpCode}");
    }

    return $html;
}

// ─── FUNCTION: Extract readable text from HTML ────────────────────────────────

/**
 * Parses raw HTML and extracts clean text from <p> and <li> elements only.
 * This avoids sending navigation, JS code, or styling data to the Gemini API.
 *
 * @param  string $html       Raw HTML string.
 * @param  int    $maxLength  Maximum characters to return.
 * @return string             Extracted plain text.
 * @throws RuntimeException   If HTML cannot be parsed at all.
 */
function extractText(string $html, int $maxLength = MAX_TEXT_LENGTH): string
{
    // Suppress DOMDocument warnings for malformed HTML (common on real sites)
    libxml_use_internal_errors(true);

    $dom = new DOMDocument('1.0', 'UTF-8');

    // loadHTML expects ISO-8859-1; force UTF-8 interpretation via meta tag injection
    $html = '<?xml encoding="utf-8" ?>' . $html;

    if (!$dom->loadHTML($html, LIBXML_NONET)) {
        libxml_clear_errors();
        throw new RuntimeException('Failed to parse the HTML from the target URL.');
    }

    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Select all <p> and <li> nodes — the core content nodes of any policy page
    $nodes = $xpath->query('//p | //li');

    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException('No readable text content (<p> or <li> tags) found on that page.');
    }

    $lines = [];
    foreach ($nodes as $node) {
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
        if (strlen($text) > 30) {   // Skip trivially short text fragments
            $lines[] = $text;
        }
    }

    if (empty($lines)) {
        throw new RuntimeException('Could not extract meaningful text from the page.');
    }

    $combined = implode("\n", $lines);

    // Trim to the maximum character budget to stay within Gemini token limits
    return mb_substr($combined, 0, $maxLength);
}

// ─── STEP 2: Analyze — Call Gemini with scraped policy text ──────────────────

/**
 * Sends the extracted policy text to the Gemini API.
 * Instructs Gemini to respond ONLY with a valid JSON object.
 *
 * @param  string $policyText  Extracted plain text from the privacy policy.
 * @return array               Decoded JSON array from Gemini.
 * @throws RuntimeException    On API errors or malformed responses.
 */
function analyzePrivacyPolicy(string $policyText): array
{
    // ── System prompt: strict JSON schema enforcement ──────────────────────────
    $systemPrompt = <<<PROMPT
You are an expert privacy law analyst AI. Analyze the privacy policy text provided and respond ONLY with a single, valid JSON object. Do not include any markdown, code fences, explanations, or text outside the JSON object.

The JSON object MUST contain exactly these keys:
{
  "summary": "<2-sentence plain-English TL;DR of what the policy says>",
  "data_collected": ["<type of data>", "..."],
  "third_party_sharing": <true or false>,
  "risk_level": "<Low | Medium | High>",
  "red_flags": ["<concerning clause or practice>", "..."]
}

Rules:
- "summary" must be exactly 2 sentences.
- "data_collected" must be an array of short strings (e.g. "Email Address", "Location Data", "Browser Cookies").
- "third_party_sharing" must be a JSON boolean (true/false), not a string.
- "risk_level" must be exactly one of: "Low", "Medium", or "High".
- "red_flags" must be an array of strings. If none exist, return an empty array [].
- Do NOT include any trailing commas or invalid JSON syntax.
PROMPT;

    $fullPrompt = $systemPrompt . "\n\n--- PRIVACY POLICY TEXT TO ANALYZE ---\n\n" . $policyText;

    $modelText = callGeminiRaw($fullPrompt, 0.1);

    // Strip any accidental markdown code fences (e.g. ```json ... ```)
    $modelText = preg_replace('/^```(?:json)?\s*/i', '', $modelText);
    $modelText = preg_replace('/\s*```$/', '', $modelText);

    // Decode the actual structured JSON returned by the model
    $parsed = json_decode(trim($modelText), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        throw new RuntimeException(
            'Gemini did not return valid structured JSON. Raw output: ' . mb_substr($modelText, 0, 200)
        );
    }

    // ── Validate required keys are present ────────────────────────────────────
    $requiredKeys = ['summary', 'data_collected', 'third_party_sharing', 'risk_level', 'red_flags'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $parsed)) {
            throw new RuntimeException("Gemini response is missing required key: '{$key}'.");
        }
    }

    return $parsed;
}

// ─── MAIN EXECUTION ───────────────────────────────────────────────────────────

/**
 * Fallback: Ask Gemini to analyze the privacy policy directly from its
 * training knowledge when scraping is blocked (403, Cloudflare, etc.).
 */
function analyzeViaGeminiFallback(string $url, string $originalQuery): array
{
    $prompt = <<<PROMPT
You are an expert privacy law analyst AI. The website at the following URL has blocked automated scraping:
URL: {$url}
Original query: {$originalQuery}

Using your training knowledge about this company's privacy policy, analyze it and respond ONLY with a single, valid JSON object. Do not include any markdown, code fences, explanations, or text outside the JSON object.

The JSON object MUST contain exactly these keys:
{
  "summary": "<2-sentence plain-English TL;DR of what the policy says>",
  "data_collected": ["<type of data>", "..."],
  "third_party_sharing": <true or false>,
  "risk_level": "<Low | Medium | High>",
  "red_flags": ["<concerning clause or practice>", "..."]
}

Rules:
- "summary" must be exactly 2 sentences.
- "data_collected" must be an array of short strings.
- "third_party_sharing" must be a JSON boolean (true/false), not a string.
- "risk_level" must be exactly one of: "Low", "Medium", or "High".
- "red_flags" must be an array of strings. If none exist, return an empty array [].
- Base your analysis on the most recent version of this company's privacy policy you know about.
- Do NOT include any trailing commas or invalid JSON syntax.
PROMPT;

    $modelText = callGeminiRaw($prompt, 0.1);

    $modelText = preg_replace('/^```(?:json)?\s*/i', '', $modelText);
    $modelText = preg_replace('/\s*```$/', '', $modelText);

    $parsed = json_decode(trim($modelText), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        throw new RuntimeException(
            'Gemini did not return valid structured JSON. Raw output: ' . mb_substr($modelText, 0, 200)
        );
    }

    $requiredKeys = ['summary', 'data_collected', 'third_party_sharing', 'risk_level', 'red_flags'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $parsed)) {
            throw new RuntimeException("Gemini response is missing required key: '{$key}'.");
        }
    }

    return $parsed;
}

// ─── MAIN EXECUTION ───────────────────────────────────────────────────────────

try {
    $discoveredUrl = null;
    $wasAutoDiscovered = false;
    $usedFallback = false;

    // ── Decide whether to auto-discover or use the input directly ─────────────
    if (isDirectUrl($userInput)) {
        $path = strtolower(parse_url($userInput, PHP_URL_PATH) ?? '');
        $privacyKeywords = ['privacy', 'legal', 'policy', 'datenschutz', 'gdpr', 'data-policy'];
        $looksLikePolicy = false;

        foreach ($privacyKeywords as $kw) {
            if (strpos($path, $kw) !== false) {
                $looksLikePolicy = true;
                break;
            }
        }

        if ($looksLikePolicy) {
            $discoveredUrl = $userInput;
        } else {
            $discoveredUrl = discoverPrivacyPolicyUrl($userInput);
            $wasAutoDiscovered = true;
        }
    } else {
        $discoveredUrl = discoverPrivacyPolicyUrl($userInput);
        $wasAutoDiscovered = true;
    }

    // ── Try scraping; fall back to Gemini knowledge if blocked ────────────────
    $analysis = null;
    $policyText = '';

    try {
        $html = scrapeUrl($discoveredUrl);
        $policyText = extractText($html);
        $analysis = analyzePrivacyPolicy($policyText);
    } catch (RuntimeException $scrapeErr) {
        // If the site blocked us (403, 406, etc.), use Gemini's own knowledge
        if (strpos($scrapeErr->getMessage(), 'SCRAPE_BLOCKED') !== false) {
            $analysis = analyzeViaGeminiFallback($discoveredUrl, $userInput);
            $usedFallback = true;
        } else {
            throw $scrapeErr; // Re-throw non-blocking errors
        }
    }

    // ── Return success response ───────────────────────────────────────────────
    $analysis['scanned_url']      = $discoveredUrl;
    $analysis['scanned_at']       = gmdate('Y-m-d H:i:s') . ' UTC';
    $analysis['chars_analyzed']   = $usedFallback ? 0 : strlen($policyText);
    $analysis['auto_discovered']  = $wasAutoDiscovered;
    $analysis['original_query']   = $userInput;
    $analysis['analysis_method']  = $usedFallback ? 'ai_knowledge' : 'direct_scrape';

    http_response_code(200);
    echo json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'code'    => 500,
        'context' => 'An error occurred while processing the privacy policy.',
    ]);
} catch (Throwable $e) {
    // Catch any other unexpected PHP error
    http_response_code(500);
    echo json_encode([
        'error'   => 'An unexpected server error occurred.',
        'code'    => 500,
        'context' => $e->getMessage(),
    ]);
}
