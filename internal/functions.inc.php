<?php

const BASE_URL = "https://track.amazon.it";

/**
 * Retrieve the trackingCode from request uri.
 *
 * @return string
 */
function getTrackingCode(): string {
    $trackingCode = ltrim($_SERVER['REQUEST_URI'], '/');
    if (isset($_SERVER['QUERY_STRING'])) {
        $trackingCode = str_replace("?" . $_SERVER['QUERY_STRING'], '', $trackingCode);
    }
    return $trackingCode;
}

/**
 * Send a formatted json response.
 *
 * @param array $data
 *   JSON data array.
 * @param int $code
 *   Status code.
 * @param array $headers
 *   Response headers.
 *
 * @return void
 */
function sendResponse(array $data, int $code = 200, array $headers = []): void {
    http_response_code($code);
    header("Content-Type: application/json");
    foreach ($headers as $header) {
        header($header);
    }

    ob_start();
    echo json_encode($data);
    ob_end_flush();
    exit(0);
}

/**
 * Check if all values in array are filled.
 *
 * @param array $data
 *   The array to test.
 *
 * @return bool
 */
function fullFilled(array $data): bool {
    return array_sum(array_map(fn ($d) => strlen($d) > 0 ? 1 : 0, array_values($data))) === count($data);
}

/**
 * Convert a key-value array in a cURL compatible string for cookie option.
 *
 * @param array $data
 *   The cookies array.
 *
 * @return string
 *   A cURL cookie field compatible string.
 */
function stringifyCookies(array $data): string {
    $data = array_filter($data, fn ($v) => strlen($v) > 0);
    return join("; ", array_map(
        fn ($k, $v) => sprintf("%s=%s", $k, $v),
        array_keys($data),
        array_values($data),
    ));
}

/**
 * Parse a multi-line string searching for Set-Cookie attribute.
 *
 * @param string $data
 *   The string to search in.
 *
 * @return array
 *   A key-value array containing the cookie name as key and the cookie value as value.
 */
function parseCookies(string $data): array {
    $result = [];

    preg_match_all("/Set-Cookie: ([0-9\w\-_=]+)/im", $data, $co);
    foreach ($co[1] ?? [] as $c) {
        [$k, $v] = explode("=", $c);
        if (isset($result[$k])) {
            continue;
        }

        $result[$k] = $v;
    }

    return $result;
}

/**
 * Fetch data from URL.
 *
 * @param string $method
 *   The request method.
 * @param string $url
 *   The URL.
 * @param array $options
 *   A cURL options array.
 * @param string[] $headers
 *   A list of header values.
 * @param array $data
 *   If method are set to POST, this field will contains the POST DATA.
 *
 * @return string
 *   The request results.
 * @throws Exception
 */
function getData(string $method, string $url, array $options = [], array $headers = [], array $data = []): string {
    switch (strtolower($method)) {
        case 'head':
            $options[CURLOPT_HEADER] = true;
            $options[CURLOPT_NOBODY] = true;
            break;
        case 'post':
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            $headers[] = 'Content-Type: application/json';
            break;
    }

    $c = curl_init();
    curl_setopt_array($c, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    curl_setopt_array($c, $options);
    $result = curl_exec($c);
    curl_close($c);
    if (curl_errno($c) !== 0) {
        throw new Exception(curl_error($c));
    }

    return $result;
}
