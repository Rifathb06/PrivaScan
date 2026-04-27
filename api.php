<?php
/**
 * PrivaScan - Backend API Handler
 * ================================
 * Handles: URL validation → web scraping → text extraction → Gemini API call → JSON response.
 *
 * HOW TO SET YOUR API KEY:
 *   1. Go to https://aistudio.google.com/app/apikey and create a free Gemini API key.
 *   2. Paste it below as the value of GEMINI_API_KEY (replace YOUR_GEMINI_API_KEY_HERE).
 *      Example: define('GEMINI_API_KEY', 'AIzaSy...');
 */

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

// Load API key from config.php (excluded from Git via .gitignore)
// If you don't have config.php yet, copy config.example.php → config.php
// and paste your Gemini API key inside it.
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

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

// Accept both JSON body and classic POST form field
$targetUrl = $body['url'] ?? ($_POST['url'] ?? '');
$targetUrl = trim($targetUrl);

if (empty($targetUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'No URL provided. Please submit a privacy policy URL.']);
    exit;
}

// Validate URL format — must be http or https
if (
    !filter_var($targetUrl, FILTER_VALIDATE_URL) ||
    !in_array(strtolower(parse_url($targetUrl, PHP_URL_SCHEME)), ['http', 'https'])
) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL. Please provide a valid HTTP or HTTPS link.']);
    exit;
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

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,     // Follow redirects
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => SCRAPE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,    // Set to true in production (XAMPP lacks CA bundle)
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',       // Accept any encoding (auto-decompress gzip/br)
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($html === false || !empty($curlErr)) {
        throw new RuntimeException("cURL error while fetching URL: {$curlErr}");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("The target URL returned HTTP status {$httpCode}. Cannot scrape.");
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

// ─── FUNCTION: Call Gemini API ────────────────────────────────────────────────

/**
 * Sends the extracted policy text to the Gemini API.
 * Instructs Gemini to respond ONLY with a valid JSON object.
 *
 * @param  string $policyText  Extracted plain text from the privacy policy.
 * @return array               Decoded JSON array from Gemini.
 * @throws RuntimeException    On API errors or malformed responses.
 */
function callGeminiApi(string $policyText): array
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

    // ── Build the Gemini request payload ──────────────────────────────────────
    // NOTE: system_instruction is embedded directly in the user message for
    // maximum compatibility. This works on both v1 and v1beta endpoints.
    $fullPrompt = $systemPrompt . "\n\n--- PRIVACY POLICY TEXT TO ANALYZE ---\n\n" . $policyText;

    $requestPayload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $fullPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,   // Low temp = deterministic, structured output
            'topP' => 0.8,
            'maxOutputTokens' => 8192,
        ],
    ];

    $jsonPayload = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    // ── cURL call to Gemini ────────────────────────────────────────────────────
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => GEMINI_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => GEMINI_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,    // Set to true in production (XAMPP lacks CA bundle)
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curlErr)) {
        throw new RuntimeException("Failed to reach Gemini API: {$curlErr}");
    }

    if ($httpCode !== 200) {
        $errDetail = json_decode($response, true)['error']['message'] ?? 'Unknown API error';
        throw new RuntimeException("Gemini API returned HTTP {$httpCode}: {$errDetail}");
    }

    // ── Parse Gemini response ─────────────────────────────────────────────────
    $geminiResponse = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Gemini returned invalid JSON in its outer response envelope.');
    }

    // Extract the model's text output from the nested Gemini structure
    $modelText = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (empty($modelText)) {
        // Check for safety blocks or finish reasons
        $finishReason = $geminiResponse['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        throw new RuntimeException("Gemini returned an empty response. Finish reason: {$finishReason}");
    }

    // Strip any accidental markdown code fences (e.g. ```json ... ```)
    $modelText = preg_replace('/^```(?:json)?\s*/i', '', trim($modelText));
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

try {
    // 1. Scrape the target URL
    $html = scrapeUrl($targetUrl);

    // 2. Extract text from <p> and <li> elements
    $policyText = extractText($html);

    // 3. Call Gemini API and get structured analysis
    $analysis = callGeminiApi($policyText);

    // 4. Add metadata and return success response
    $analysis['scanned_url'] = $targetUrl;
    $analysis['scanned_at'] = gmdate('Y-m-d H:i:s') . ' UTC';
    $analysis['chars_analyzed'] = strlen($policyText);

    http_response_code(200);
    echo json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'code' => 500,
        'context' => 'An error occurred while processing the privacy policy.',
    ]);
} catch (Throwable $e) {
    // Catch any other unexpected PHP error
    http_response_code(500);
    echo json_encode([
        'error' => 'An unexpected server error occurred.',
        'code' => 500,
        'context' => $e->getMessage(),
    ]);
}
