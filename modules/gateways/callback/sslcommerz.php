<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Direct access is not allowed.');
}

// Prefer value_a (invoice id) when present, else parse from tran_id ("<id>-<hex>" or "<id>")
$invoiceId = 0;
if (!empty($_POST['value_a']) && ctype_digit((string) $_POST['value_a'])) {
    $invoiceId = (int) $_POST['value_a'];
} elseif (!empty($_POST['tran_id']) && preg_match('/^(\d+)/', (string) $_POST['tran_id'], $m)) {
    $invoiceId = (int) $m[1];
}

if ($invoiceId <= 0) {
    exit('Invalid transaction.');
}

// Map SSLCommerz status to a payment outcome flag + optional reason code.
// Reason codes are decoded by sslcommerz_error_messages() in the main module.
$status = strtoupper($_POST['status'] ?? '');
$qs = ['id' => $invoiceId];
if ($status === 'VALID') {
    $qs['paymentsuccess'] = 'true';
} else {
    $qs['paymentfailed'] = 'true';
    if ($status === 'CANCELLED') {
        $qs['reason'] = 'user-cancel';
    } elseif ($status === 'FAILED') {
        $qs['reason'] = 'declined';
    } else {
        $qs['reason'] = 'unknown';
    }
}
$target = '/viewinvoice.php?' . http_build_query($qs);

// Prefer a server-side HTTP redirect (works without JavaScript).
if (!headers_sent()) {
    header('Location: ' . $target, true, 303);
    exit();
}

// Fallback: meta refresh + JS + manual link if headers were already flushed.
$safe = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url=' . $safe . '">
    <title>Redirecting…</title>
</head><body>
    <p>Redirecting to your invoice. <a href="' . $safe . '">Click here if you are not redirected.</a></p>
    <script>window.location.replace(' . json_encode($target) . ');</script>
    <noscript>
        <p>JavaScript is disabled. <a href="' . $safe . '">Continue to invoice</a>.</p>
    </noscript>
</body></html>';
