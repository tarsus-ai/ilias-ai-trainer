<?php

/**
 * A stand-in for the Tarsus API, run by PHP's built-in web server
 * (php -d enable_post_data_reading=0 -S 127.0.0.1:PORT router.php).
 *
 * It records every request - method, path, form fields and the files that
 * came with them - as one JSON line in the file named by the environment
 * variable TARSUS_FAKE_LOG, and answers with a plausible body for the
 * endpoint. The file named by TARSUS_FAKE_CONTROL may hold
 * {"fail": {"<path>": {"status": 500, "body": "..."}}, "usage": [...]} to
 * make single endpoints fail or to set the usage report.
 *
 * The plugin's client sends multipart form-data with GET and DELETE too,
 * which PHP does not parse by itself, so the body is parsed here.
 *
 * @license GPL-3.0-or-later
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$contenttype = (string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$raw = (string) file_get_contents('php://input');

$fields = [];
$files = [];
if (preg_match('~multipart/form-data;\s*boundary=("?)([^";]+)\1~i', $contenttype, $match)) {
    foreach (explode('--' . $match[2], $raw) as $part) {
        $part = ltrim($part, "\r\n");
        if ($part === '' || strncmp($part, '--', 2) === 0) {
            continue;
        }
        $split = strpos($part, "\r\n\r\n");
        if ($split === false) {
            continue;
        }
        $head = substr($part, 0, $split);
        $body = substr($part, $split + 4);
        if (substr($body, -2) === "\r\n") {
            $body = substr($body, 0, -2);
        }
        if (!preg_match('~name="([^"]*)"~', $head, $name)) {
            continue;
        }
        if (preg_match('~filename="([^"]*)"~', $head, $filename)) {
            $type = preg_match('~Content-Type:\s*([^\r\n]+)~i', $head, $t) ? trim($t[1]) : '';
            $files[$name[1]] = ['filename' => $filename[1], 'type' => $type, 'size' => strlen($body), 'sha1' => sha1($body)];
        } else {
            $fields[$name[1]] = $body;
        }
    }
} elseif ($raw !== '') {
    parse_str($raw, $fields);
}

$log = getenv('TARSUS_FAKE_LOG');
if ($log) {
    file_put_contents($log, json_encode([
        'method' => $method,
        'path' => $path,
        'fields' => $fields,
        'files' => $files,
        'agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

$control = [];
$controlfile = getenv('TARSUS_FAKE_CONTROL');
if ($controlfile && is_file($controlfile)) {
    $control = json_decode((string) file_get_contents($controlfile), true) ?: [];
}

$respond = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};

if (isset($control['fail'][$path])) {
    http_response_code((int) ($control['fail'][$path]['status'] ?? 500));
    header('Content-Type: text/plain');
    echo (string) ($control['fail'][$path]['body'] ?? 'failure');
    return;
}
if (isset($control['raw'][$path])) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo (string) $control['raw'][$path];
    return;
}

$ids = static function (string $key) use ($fields): array {
    $decoded = json_decode((string) ($fields[$key] ?? '[]'), true);
    return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
};

$question = static function (string $id, string $type): array {
    $data = ['question' => 'What joins two metal parts by melting them?', 'taxonomyLevel' => 'WISSEN', 'difficultyLevel' => 1];
    if ($type === 'singleChoiceQuestionWidget') {
        $data['choices'] = [
            ['choice' => 'Welding', 'isCorrect' => true, 'clarification' => 'Right.'],
            ['choice' => 'Sawing', 'isCorrect' => false, 'clarification' => 'No.'],
        ];
    } elseif ($type === 'trueFalseQuestionWidget') {
        $data['choices'] = [
            ['choice' => true, 'isCorrect' => true, 'clarification' => ''],
            ['choice' => false, 'isCorrect' => false, 'clarification' => ''],
        ];
    }
    return ['_id' => $id, 'bit' => ['type' => $type, 'text' => $data['question'], 'data' => $data]];
};

switch ($path) {
    case '/api/v1/brain/awareness/list/memories':
        $respond(['awareness' => ['brain_ids' => [
            ['_id' => 'brain-test-1', 'active' => true, 'brainLanguage' => 'de', 'brainTag' => 'default', 'brainCreatedDateTime' => 1726722755],
            ['_id' => 'brain-test-2', 'active' => true, 'brainLanguage' => 'en', 'brainTag' => 'default', 'brainCreatedDateTime' => 1726722999],
        ]]]);
        break;

    case '/api/v1/brain/awareness/list/clone-voices':
        $respond(['assistance' => ['voices' => [
            ['assistant' => 'Sara', 'country' => 'de-DE', 'gender' => 'FEMALE'],
            ['assistant' => 'Daniel', 'country' => 'en-US', 'gender' => 'MALE'],
        ]]]);
        break;

    case '/api/v1/brain/awareness/get/usage':
        $respond(['awareness' => ['usage' => $control['usage'] ?? [
            ['aiCredits' => 6, 'key' => 'answeredQuery', 'name' => 'Answered Search', 'service' => 'search'],
            ['aiCredits' => 21, 'key' => 'questionGeneration', 'name' => 'Question Generation', 'service' => 'assets'],
            ['aiCredits' => 0, 'key' => 'indexedText', 'name' => 'Text Index', 'service' => 'index'],
        ]]]);
        break;

    case '/api/v1/brain/access/track/text-bits':
    case '/api/v1/brain/access/track/url-bits':
    case '/api/v1/brain/access/track/document-bits':
    case '/api/v1/brain/access/track/audio-bits':
    case '/api/v1/brain/access/track/video-bits':
        $respond(['access' => ['received' => $control['received'] ?? true]]);
        break;

    case '/api/v1/brain/access/untrack/multiple-resources':
        $respond(['access' => ['untracked' => ['resource_ids' => $ids('resource_ids')]]]);
        break;

    case '/api/v1/brain/access/untrack/multiple-assets':
        $respond(['access' => ['untracked' => ['asset_ids' => $ids('asset_ids')]]]);
        break;

    case '/api/v1/brain/access/check/tracked-resources':
        $respond(['access' => array_map(static fn(string $id): array => [
            'resourceId' => $id, 'tracked' => true, 'status' => $control['trackstatus'] ?? 'done',
        ], $ids('resource_ids'))]);
        break;

    case '/api/t0/activity/retrieve/next/widgets':
        $respond(['activity' => ['widgets' => [], 'earnedExperiencePoints' => 2, 'totalExperiencePoints' => 12]]);
        break;

    case '/api/t0/activity/generate/next/widgets':
        $state = (string) ($fields['state'] ?? 'ask');
        if ($state === 'practice') {
            $respond(['activity' => ['widgets' => [
                $question('w-sc-1', 'singleChoiceQuestionWidget'),
                $question('w-open-1', 'openQuestionWidget'),
            ], 'earnedExperiencePoints' => 0, 'totalExperiencePoints' => 12, 'practiceProgress' => 0]]);
        } elseif ($state === 'learn') {
            $respond(['activity' => ['widgets' => [[
                'stackId' => 'stack-1',
                'stackedWidgets' => [
                    ['_id' => 's-1', 'bit' => ['type' => 'textWidget', 'data' => ['title' => 'Arc welding', 'text' => 'An arc melts the metal.']]],
                    $question('s-q', 'singleChoiceQuestionWidget'),
                ],
            ]], 'earnedLearningUnits' => 0, 'totalLearningUnits' => 9]]);
        } elseif (($fields['interaction_type'] ?? '') === 'ai-driven') {
            $respond(['activity' => ['widgets' => [
                ['_id' => 'a-1', 'bit' => ['type' => 'htmlWidget', 'data' => [
                    'question' => (string) ($fields['query'] ?? ''),
                    'html' => '<!doctype html><html><body><button>Try</button></body></html>',
                ]]],
            ]]]);
        } else {
            $respond(['activity' => ['widgets' => [
                ['_id' => 'a-1', 'bit' => ['type' => 'answerWidget', 'text' => (string) ($fields['query'] ?? ''), 'data' => [
                    'question' => (string) ($fields['query'] ?? ''),
                    'answer' => 'Welding joins metal parts by **melting** them.',
                ]]],
            ]]]);
        }
        break;

    case '/api/t0/activity/evaluate/single-choice-question/widget':
    case '/api/t0/activity/evaluate/true-false-question/widget':
    case '/api/t0/activity/evaluate/open-question/widget':
    case '/api/t0/activity/evaluate/cross-multiplication-question/widget':
    case '/api/t0/activity/evaluate/trade-costing-question/widget':
        $respond(['activity' => [
            'earnedExperiencePoints' => 2, 'totalExperiencePoints' => 12, 'practiceProgress' => 16.67,
            'widget' => ['_id' => (string) ($fields['widget_id'] ?? ''), 'bit' => ['data' => [
                'answer' => 'Welding',
                'evaluation' => ['evaluated' => true, 'score' => 100, 'feedback' => 'Well done.', 'answer' => 'Welding',
                    'userAnswer' => (string) ($fields['user_answer'] ?? '')],
            ]]],
        ]]);
        break;

    case '/api/t0/activity/generate/audio/explainer/stack':
        $respond(['activity' => ['audio' => ['data' => [
            'audioBytes' => base64_encode('RIFF....WAVEfmt '), 'mimeType' => 'audio/wav', 'assistant' => ['alias' => (string) ($fields['voice'] ?? 'Sara')],
        ]]]]);
        break;

    case '/api/t0/activity/reset/user/widgets':
        $respond(['activity' => ['reset' => true]]);
        break;

    default:
        $respond(['error' => 'unknown endpoint ' . $path], 404);
}
