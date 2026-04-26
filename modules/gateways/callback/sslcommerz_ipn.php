<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewaymodule = 'sslcommerz';
$GATEWAY = getGatewayVariables($gatewaymodule);

if (!$GATEWAY['type']) {
    die('Module Not Activated');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    http_response_code(400);
    die('No Post Data To Validate!');
}

// Config field names are 'username' / 'password' (see sslcommerz_config()).
$store_id     = $GATEWAY['username'] ?? '';
$store_passwd = $GATEWAY['password'] ?? '';

if ($store_id === '' || $store_passwd === '') {
    logTransaction($GATEWAY['name'], $_POST, 'Unsuccessful - Missing Store Credentials');
    exit();
}

$tran_id     = isset($_POST['tran_id']) ? trim($_POST['tran_id']) : '';
$val_id      = isset($_POST['val_id']) ? trim($_POST['val_id']) : '';
$post_status = isset($_POST['status']) ? $_POST['status'] : '';

if ($tran_id === '' || $val_id === '' || $post_status !== 'VALID') {
    logTransaction($GATEWAY['name'], $_POST, 'Unsuccessful - Invalid IPN');
    exit();
}

// 1) Verify IPN authenticity via SSLCommerz hash (verify_sign + verify_key)
if (!empty($_POST['verify_sign']) && !empty($_POST['verify_key'])) {
    $pre_define_key = explode(',', $_POST['verify_key']);
    $new_data = [];
    foreach ($pre_define_key as $key) {
        if (isset($_POST[$key])) {
            $new_data[$key] = $_POST[$key];
        }
    }
    $new_data['store_passwd'] = md5($store_passwd);
    ksort($new_data);
    $hash_string = urldecode(http_build_query($new_data));
    if (md5($hash_string) !== $_POST['verify_sign']) {
        logTransaction($GATEWAY['name'], $_POST, 'Unsuccessful - Hash Mismatch');
        exit();
    }
} else {
    logTransaction($GATEWAY['name'], $_POST, 'Unsuccessful - Missing verify_sign');
    exit();
}

// 2) Server-to-server validation with SSLCommerz validator API
const SSLCOMMERZ_VALIDATOR_SANDBOX = 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php';
const SSLCOMMERZ_VALIDATOR_LIVE    = 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';
$base = ($GATEWAY['testmode'] == 'on') ? SSLCOMMERZ_VALIDATOR_SANDBOX : SSLCOMMERZ_VALIDATOR_LIVE;

$query = http_build_query([
    'val_id'       => $val_id,
    'store_id'     => $store_id,
    'store_passwd' => $store_passwd,
    'v'            => 1,
    'format'       => 'json',
]);
$requested_url = $base . '?' . $query;

$handle = curl_init();
curl_setopt($handle, CURLOPT_URL, $requested_url);
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($handle, CURLOPT_TIMEOUT, 30);
$result = curl_exec($handle);
$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
$curlErr = curl_errno($handle);
curl_close($handle);

if ($code !== 200 || $curlErr) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'curl_error' => $curlErr, 'http' => $code], 'Unsuccessful - Validator Unreachable');
    exit();
}

$validated = json_decode($result, true);
if (!is_array($validated)) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'response' => $result], 'Unsuccessful - Invalid Validator Response');
    exit();
}

$vStatus   = $validated['status'] ?? '';
$vTranId   = $validated['tran_id'] ?? '';
$vCurrency = $validated['currency'] ?? '';
$vAmount   = isset($validated['currency_amount']) ? (float) $validated['currency_amount'] : 0.0;
$vBankTran = $validated['bank_tran_id'] ?? '';
$vRisk     = $validated['risk_level'] ?? '1';

// 3) Cross-check validator response against the POST and the local invoice
if ($vTranId !== $tran_id) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - tran_id Mismatch');
    exit();
}

if (!in_array($vStatus, ['VALID', 'VALIDATED'], true)) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - Not Validated');
    exit();
}

// SSLCommerz risk levels: 0 = no risk, 1 = high risk, 2 = medium (review).
// Reject only level 1 — accept 0 and 2 per SSLCommerz integration guidance.
if ((string) $vRisk === '1') {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - High Risk');
    exit();
}

// Recover invoice id: prefer value_a (set by the link handler); fall back to
// tran_id which may be "<invoiceid>-<hex>" or a bare invoice id.
$invoiceid = 0;
if (!empty($validated['value_a']) && ctype_digit((string) $validated['value_a'])) {
    $invoiceid = (int) $validated['value_a'];
} elseif (preg_match('/^(\d+)(?:-[a-f0-9]+)?$/i', $tran_id, $m)) {
    $invoiceid = (int) $m[1];
}
if ($invoiceid <= 0) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - Invalid Invoice Id');
    exit();
}
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceid)->first();
if (!$invoice) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - Invoice Not Found');
    exit();
}

$order_amount = (float) $invoice->total;
if (abs($order_amount - $vAmount) > 0.01) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated, 'expected' => $order_amount], 'Unsuccessful - Amount Mismatch');
    exit();
}

if ($vCurrency === '') {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Unsuccessful - Missing Currency');
    exit();
}

// Currency cross-check: link handler stores expected currency in value_b.
// Reject if the validator-reported currency doesn't match what we asked for.
$expectedCurrency = $validated['value_b'] ?? ($_POST['value_b'] ?? '');
if ($expectedCurrency !== '' && strcasecmp($vCurrency, $expectedCurrency) !== 0) {
    logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated, 'expected_currency' => $expectedCurrency], 'Unsuccessful - Currency Mismatch');
    exit();
}

$invoiceid = checkCbInvoiceID($invoiceid, $GATEWAY['name']);
checkCbTransID($vBankTran !== '' ? $vBankTran : $tran_id);

addInvoicePayment(
    $invoiceid,
    $vBankTran !== '' ? $vBankTran : $tran_id,
    $vAmount,
    0,
    $gatewaymodule
);

logTransaction($GATEWAY['name'], ['post' => $_POST, 'validated' => $validated], 'Successful');
exit();
