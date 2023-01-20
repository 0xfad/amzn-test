<?php

include_once __DIR__ . '/../internal/functions.inc.php';

$cache = new Memcache();
$cache->addServer(getenv("CACHE_HOST"), getenv("CACHE_PORT"));

$trackingCode = getTrackingCode();
if (!$trackingCode) {
    sendResponse([
        'type' => 'error',
        'code' => 400,
        'message' => 'You must provide a tracking id',
    ], 400);
}

// Check if the tracking code is cached.
if (($cachedData = $cache->get($trackingCode)) !== false) {
    $data = json_decode($cachedData, true);
    sendResponse($data);
}

$cookies = [
    'session-id' => null,
    'session-id-time' => null,
    'session-token' => null,
    'ubid-acbit' => null,
];

/* ------------------------------------------------------------------------------------------------------------------ *
 * Retrieve the CSRF token from https://track.amazon.it/tracking page.
 * This token are mandatory for strings translations and for obtain the necessary token from Amzn api.
 * ------------------------------------------------------------------------------------------------------------------ */
$csrfToken = "";
try {
    $trackingPageResponse = getData('GET', sprintf(BASE_URL . "/tracking/%s", $trackingCode), [
        CURLOPT_HEADER => true,
    ]);
    // find a html tag containing the token.
    preg_match('/<meta name="CSRF-TOKEN" content="(.+)"/i', $trackingPageResponse, $m);

    $csrfToken = $m[1] ?? null;
    if (is_null($csrfToken)) {
        sendResponse([
            'type' => 'error',
            'code' => 500,
            'message' => 'Unable to retrieve a valid CSRF token',
        ]);
    }

    $cookies = array_merge($cookies, parseCookies($trackingPageResponse));

} catch (Exception $e) {
    sendResponse([
        'type' => 'error',
        'status' => 500,
        'message' => $e->getMessage(),
    ], 500);
}
/* ------------------------------------------------------------------------------------------------------------------ */


/* ------------------------------------------------------------------------------------------------------------------ *
 * Try to fill the necessary cookies from Amazon server response.
 * In order to translate the strings it is necessary to obtain a session-token from the Amazon server.
 *
 * This token is not provided immediately but only by adding a cookie to the request to get a
 * new cookie needed on the next request.
 *
 * The following block proceeds by forwarding a request of type HEAD to the server integrating
 * the cookies of the response until the $cookies array is filled.
 *
 * This block proceeds in an attempt to obtain all the necessary cookies,
 * without exceeding the limit of 5 attempts in order not to run into an infinite loop.
 */
/* ------------------------------------------------------------------------------------------------------------------ */
$attempts = 0;
while (!fullFilled($cookies)) {
    try {
        $headers = getData('HEAD', sprintf(BASE_URL . "/tracking/%s", $trackingCode), [], [
            sprintf("anti-csrftoken-a2z: %s", $csrfToken),
            sprintf("Cookie: %s", stringifyCookies($cookies)),
        ]);
        $cookies = array_merge($cookies, parseCookies($headers));

    } catch (Exception $e) {
        break;
    }

    if ($attempts >= 5) {
        break;
    }

    $attempts += 1;
}
/* ------------------------------------------------------------------------------------------------------------------ */


/* ------------------------------------------------------------------------------------------------------------------ *
 * Make a call to the amazon API to get the shipping information and populate
 * $eventHistory with the raw data obtained from an attribute of the response JSON.
 *
 * It also takes care of searching within the JSON for all the translatable keys to process them later.
/* ------------------------------------------------------------------------------------------------------------------ */
$localizedStrings = [];
$trackingHistory = [];
try {
    $trackingData = getData('GET', sprintf(BASE_URL . "/api/tracker/%s", $trackingCode), [], [
        sprintf("anti-csrftoken-a2z: %s", $csrfToken),
        sprintf("Cookie: %s", stringifyCookies($cookies)),
    ]);

    $trackingData = json_decode($trackingData, true);
    if (is_null($trackingData)) {
        sendResponse([
            'type' => 'error',
            'code' => 500,
            'message' => sprintf('Unable to decode trackingData JSON: %s', json_last_error_msg()),
        ], 500);
    }

    $progressTracker = json_decode($trackingData['progressTracker'], true);
    if (is_null($progressTracker)) {
        sendResponse([
            'type' => 'error',
            'code' => 500,
            'message' => sprintf('Unable to decode progressTracker JSON: %s', json_last_error_msg()),
        ], 500);
    }

    if (isset($progressTracker['errors']) && !empty($progressTracker['errors'])) {
        sendResponse([
            'type' => 'error',
            'code' => 404,
            'message' => $progressTracker['errors'][0]['errorMessage'],
        ], 404);
    }

    preg_match_all('/"localisedStringId":"([\d\w_\-]+)"/im', $trackingData['eventHistory'], $strings);
    $localizedStrings = $strings[1];

    $eventHistory = json_decode($trackingData['eventHistory'], true);
    if (is_null($eventHistory)) {
        sendResponse([
            'type' => 'error',
            'code' => 500,
            'message' => sprintf('Unable to decode eventHistory JSON: %s', json_last_error_msg()),
        ]);
    }

    $progressMetadata = $progressTracker['summary']['metadata'];
    $trackingHistory = [
        'trackingID' => $trackingCode,
        'summary' => [
            'shipperName' => $progressMetadata['shipperName']['stringValue'],
            'carrier' => $progressMetadata['lastLegCarrier']['stringValue'],
            'expectedDeliveryDate' => $progressTracker['expectedDeliveryDate'],
        ],
        'history' => $eventHistory['eventHistory'],
    ];

} catch (Exception $e) {
    sendResponse([
        'type' => 'error',
        'code' => 500,
        'message' => $e->getMessage(),
    ]);
}
/* ------------------------------------------------------------------------------------------------------------------ */

/* ------------------------------------------------------------------------------------------------------------------ *
 * If translatable strings are found while retrieving the shipment history, a call is made to the
 * translation endpoint, passing the list of keys to translate as the payload.
/* ------------------------------------------------------------------------------------------------------------------ */
if (!empty($localizedStrings)) {
    try {
        $localizedStringsData = getData('POST', BASE_URL . "/getLocalizedStrings", [], [
            sprintf("anti-csrftoken-a2z: %s", $csrfToken),
            sprintf("Cookie: %s", stringifyCookies($cookies)),
        ], [
            'localizationKeys' => $localizedStrings,
        ]);

        $localizedStrings = json_decode($localizedStringsData, true);
        if (is_null($localizedStrings)) {
            $localizedStrings = [];
        }

    } catch (Exception $e) {
        ; // do nothing
    }
}

$trackingHistory['history'] = array_map(function($d) use ($localizedStrings) {
    return [
        'code' => $d['eventCode'],
        'status' => $localizedStrings[$d['statusSummary']['localisedStringId']] ?? '',
        'location' => $d['location'],
        'time' => $d['eventTime'],
    ];
}, $trackingHistory['history']);

$cache->set($trackingCode, json_encode($trackingHistory), null, 60);
$cache->close();

sendResponse($trackingHistory);
