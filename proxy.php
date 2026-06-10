<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$input = json_decode(file_get_contents('php://input'), true);

$DATA_DIR = __DIR__ . '/datos';

// --- Gemini API key: from request body, then from config file
$apiKey = '';
if (!empty($input['gemini_key'])) {
    $apiKey = trim($input['gemini_key']);
    file_put_contents($DATA_DIR.'/gemini_config.json', json_encode([
        'key'   => $apiKey,
        'model' => $input['model'] ?? 'gemini-2.5-flash-lite'
    ]));
} elseif (file_exists($DATA_DIR.'/gemini_config.json')) {
    $cfg    = json_decode(file_get_contents($DATA_DIR.'/gemini_config.json'), true);
    $apiKey = $cfg['key'] ?? '';
}

// --- HuggingFace API key: from request body, then from config file
$hfKey = '';
if (!empty($input['hf_key'])) {
    $hfKey = trim($input['hf_key']);
    file_put_contents($DATA_DIR.'/hf_config.json', json_encode(['key' => $hfKey]));
} elseif (file_exists($DATA_DIR.'/hf_config.json')) {
    $hfCfg = json_decode(file_get_contents($DATA_DIR.'/hf_config.json'), true);
    $hfKey = $hfCfg['key'] ?? '';
}

$model = $input['model'] ?? 'gemini-2.5-flash-lite';

// ── IMAGE GENERATION MODE ─────────────────────────────────────────
if (!empty($input['generate_image']) || !empty($input['_save_only'])) {
    // _save_only: solo guardar la key HF, sin generar imagen
    if (!empty($input['_save_only'])) { echo json_encode(['ok' => true]); exit; }

    $imagePrompt = $input['image_prompt'] ?? 'educational illustration';
    $engine      = $input['image_engine'] ?? 'huggingface'; // 'gemini' | 'huggingface'

    // ── Intento 1: Gemini image generation ───────────────────────
    if ($engine === 'gemini' && $apiKey) {
        $body = json_encode([
            'contents'         => [['parts' => [['text' => $imagePrompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT']]
        ]);
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent?key=' . $apiKey;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data  = json_decode($response, true);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (!empty($part['inlineData']['data'])) {
                echo json_encode(['image_b64' => $part['inlineData']['data']]);
                exit;
            }
        }
        // Gemini no devolvió imagen — caer a HuggingFace
    }

    // ── Intento 2: HuggingFace Inference API ─────────────────────
    if (!$hfKey) {
        echo json_encode(['error' => 'No HuggingFace API key configured. Add it in Settings.']);
        exit;
    }
    $hfModel = 'black-forest-labs/FLUX.1-schnell';
    $hfUrl   = 'https://router.huggingface.co/hf-inference/models/' . $hfModel;
    $ch = curl_init($hfUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['inputs' => $imagePrompt]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $hfKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $hfResponse = curl_exec($ch);
    $hfCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($hfCode !== 200) {
        $err = json_decode($hfResponse, true);
        echo json_encode(['error' => 'HuggingFace ' . $hfCode . ': ' . ($err['error'] ?? substr($hfResponse, 0, 200))]);
        exit;
    }
    // HF devuelve la imagen como bytes binarios
    echo json_encode(['image_b64' => base64_encode($hfResponse)]);
    exit;
}

// ── TEXT GENERATION MODE ──────────────────────────────────────────
if (empty($apiKey)) {
    echo json_encode(['error' => 'No Gemini API key configured. Go to Settings.']);
    exit;
}

$messages     = $input['messages'] ?? [];
$systemPrompt = $input['system']   ?? '';
$userContent  = $messages[0]['content'] ?? '';
$fullPrompt   = $systemPrompt ? $systemPrompt . "\n\nUsuario: " . $userContent : $userContent;

$body = json_encode([
    'contents'         => [['parts' => [['text' => $fullPrompt]]]],
    'generationConfig' => [
        'maxOutputTokens' => (int)($input['max_tokens'] ?? 4000),
        'temperature'     => 0.7
    ]
]);

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$gemini = json_decode($response, true);
if (isset($gemini['error'])) {
    http_response_code($httpCode);
    echo json_encode(['error' => $gemini['error']['message'] ?? 'Gemini error']);
    exit;
}

$text = $gemini['candidates'][0]['content']['parts'][0]['text'] ?? '';
echo json_encode(['content' => [['type' => 'text', 'text' => $text]]]);
